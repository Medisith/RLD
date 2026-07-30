<?php

declare(strict_types=1);

namespace App\RateLimiting\Algorithms;

use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Contracts\RateLimitScriptRunner;
use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Exceptions\LuaScriptFailureException;
use App\RateLimiting\Support\RateLimitPolicy;
use App\RateLimiting\Support\RateLimitResult;

/**
 * Leaky Bucket atômico sobre Redis — Fase 3.
 *
 * Responsabilidade: decidir permitir/negar despejando o custo da requisição
 * num balde que DRENA a vazão constante ("capacity" = volume máximo
 * represado; "leak_rate" = unidades drenadas por segundo). Enquanto o Token
 * Bucket da Fase 2 gasta o burst inteiro de uma vez e recarrega depois, o
 * Leaky Bucket suaviza: o downstream nunca recebe mais que leak_rate por
 * segundo em regime — comparativo completo em
 * docs/fases/fase-3-leaky-bucket.md.
 *
 * Mesmas garantias da Fase 2: toda a decisão vive no script Lua versionado
 * em app/RateLimiting/Redis/scripts/leaky_bucket.lua, executado como UMA
 * operação atômica com o relógio TIME do próprio Redis. Esta classe é a
 * casca tipada, sem NENHUMA decisão fora do servidor.
 */
final class LeakyBucketRateLimiter implements RateLimitAlgorithm
{
    private const string SCRIPT_PATH = __DIR__.'/../Redis/scripts/leaky_bucket.lua';

    // Fonte do script, carregada UMA vez na construção (falha cedo se o
    // arquivo sumir) e reutilizada em toda decisão.
    private readonly string $scriptSource;

    /**
     * Recebe: a porta de execução atômica de scripts (EVAL). Faz: carrega o
     * script Lua versionado do disco. Retorna: instância pronta. Efeitos
     * colaterais: leitura de arquivo; lança LuaScriptFailureException se o
     * script não existir ou não puder ser lido.
     */
    public function __construct(
        private readonly RateLimitScriptRunner $scriptRunner,
    ) {
        $source = @file_get_contents(self::SCRIPT_PATH);

        if ($source === false || $source === '') {
            throw LuaScriptFailureException::missingScript(self::SCRIPT_PATH);
        }

        $this->scriptSource = $source;
    }

    /**
     * Recebe: chave resolvida, política vigente (algorithm = leaky_bucket,
     * leak_rate validado na construção da política) e custo da requisição.
     * Faz: executa o script Lua atômico com [capacity, leak_rate, cost] e
     * converte a resposta [allowed, remaining, retry_after] em
     * RateLimitResult. Retorna: o veredito. Efeitos colaterais: leitura e
     * escrita do nível do balde no Redis (dentro do script); lança
     * InvalidRateLimitPolicyException para custo < 1 ou política sem
     * leak_rate; propaga RedisUnavailableException e
     * LuaScriptFailureException.
     */
    public function attempt(string $key, RateLimitPolicy $policy, int $cost): RateLimitResult
    {
        if ($cost < 1) {
            throw InvalidRateLimitPolicyException::forReason(
                "cost must be >= 1 (received: {$cost}) while consuming key '{$key}'"
            );
        }

        // Defesa em profundidade: mesma justificativa do TokenBucketRateLimiter.
        if ($policy->leakRate === null || $policy->leakRate <= 0.0) {
            throw InvalidRateLimitPolicyException::forReason(
                "leaky_bucket requires leak_rate > 0 on policy '{$policy->name}'"
            );
        }

        $reply = $this->scriptRunner->evalScript(
            script: $this->scriptSource,
            keys: [$key],
            arguments: [$policy->capacity, $policy->leakRate, $cost],
        );

        [$allowed, $remaining, $retryAfter] = $this->parseReply($key, $reply);

        return $allowed
            ? RateLimitResult::allowed($policy, $key, $remaining)
            : RateLimitResult::denied($policy, $key, $retryAfter);
    }

    /**
     * Recebe: chave em decisão e resposta bruta do EVAL. Faz: valida o
     * contrato de retorno do script — array de 3 valores numéricos
     * [allowed, remaining, retry_after]. Retorna: tupla tipada
     * [bool, int, int]. Efeitos colaterais: nenhum; lança
     * LuaScriptFailureException para resposta malformada.
     *
     * @return array{0: bool, 1: int, 2: int}
     */
    private function parseReply(string $key, mixed $reply): array
    {
        if (! is_array($reply) || count($reply) < 3
            || ! is_numeric($reply[0]) || ! is_numeric($reply[1]) || ! is_numeric($reply[2])) {
            throw LuaScriptFailureException::unexpectedReply($key, $reply);
        }

        return [(int) $reply[0] === 1, (int) $reply[1], (int) $reply[2]];
    }
}
