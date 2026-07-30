<?php

declare(strict_types=1);

namespace App\RateLimiting\Algorithms;

use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Contracts\RateLimitScriptRunner;
use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Exceptions\LuaScriptFailureException;
use App\RateLimiting\Redis\LuaScript;
use App\RateLimiting\Support\RateLimitPolicy;
use App\RateLimiting\Support\RateLimitResult;

/**
 * Token Bucket atômico sobre Redis — Fase 2.
 *
 * Responsabilidade: decidir permitir/negar consumindo tokens de um balde
 * que recarrega continuamente ("capacity" = burst máximo; "refill_rate" =
 * tokens por segundo). TODA a lógica de decisão vive no script Lua
 * versionado em app/RateLimiting/Redis/scripts/token_bucket.lua e executa
 * como UMA operação atômica no servidor — a correção do que a Fase 1 provou
 * quebrar no NaiveRedisRateLimiter. Esta classe é só a casca tipada:
 * carrega o script, envia parâmetros e converte a resposta em
 * RateLimitResult, sem NENHUMA decisão fora do Redis.
 *
 * O relógio usado é o TIME do próprio Redis (dentro do script), imune a
 * clock skew entre instâncias PHP — justificativa completa no cabeçalho do
 * arquivo .lua e em docs/fases/fase-2-token-bucket.md.
 */
final class TokenBucketRateLimiter implements RateLimitAlgorithm
{
    private const string SCRIPT_PATH = __DIR__.'/../Redis/scripts/token_bucket.lua';

    // Script versionado (fonte + SHA-1), carregado UMA vez na construção
    // (falha cedo se o arquivo sumir) — é o cache de processo do fluxo
    // EVALSHA da Fase 4; o adaptador reidrata no servidor se necessário.
    private readonly LuaScript $script;

    /**
     * Recebe: a porta de execução atômica de scripts (EVALSHA + fallback).
     * Faz: carrega o script Lua versionado do disco e pré-computa o SHA-1.
     * Retorna: instância pronta. Efeitos colaterais: leitura de arquivo;
     * lança LuaScriptFailureException se o script não existir ou não puder
     * ser lido.
     */
    public function __construct(
        private readonly RateLimitScriptRunner $scriptRunner,
    ) {
        $this->script = LuaScript::fromFile(self::SCRIPT_PATH);
    }

    /**
     * Recebe: chave resolvida, política vigente (algorithm = token_bucket,
     * refill_rate validado na construção da política) e custo da requisição.
     * Faz: executa o script Lua atômico com [capacity, refill_rate, cost] e
     * converte a resposta [allowed, remaining, retry_after, reset_after] em
     * RateLimitResult. Retorna: o veredito. Efeitos colaterais: leitura e
     * escrita do estado do balde no Redis (dentro do script); lança
     * InvalidRateLimitPolicyException para custo < 1 ou política sem
     * refill_rate; propaga RedisUnavailableException e
     * LuaScriptFailureException.
     */
    public function attempt(string $key, RateLimitPolicy $policy, int $cost): RateLimitResult
    {
        if ($cost < 1) {
            throw InvalidRateLimitPolicyException::forReason(
                "cost must be >= 1 (received: {$cost}) while consuming key '{$key}'"
            );
        }

        // Defesa em profundidade: a política valida isso na construção, mas
        // attempt() aceita qualquer RateLimitPolicy — um chamador que passe
        // uma política de outro algoritmo falha aqui de forma explícita.
        if ($policy->refillRate === null || $policy->refillRate <= 0.0) {
            throw InvalidRateLimitPolicyException::forReason(
                "token_bucket requires refill_rate > 0 on policy '{$policy->name}'"
            );
        }

        $reply = $this->scriptRunner->evalScript(
            script: $this->script,
            keys: [$key],
            arguments: [$policy->capacity, $policy->refillRate, $cost],
        );

        [$allowed, $remaining, $retryAfter, $resetAfter] = $this->parseReply($key, $reply);

        return $allowed
            ? RateLimitResult::allowed($policy, $key, $remaining, $resetAfter)
            : RateLimitResult::denied($policy, $key, $retryAfter, $resetAfter);
    }

    /**
     * Recebe: chave em decisão e resposta bruta do script. Faz: valida o
     * contrato de retorno — array de 4 valores numéricos
     * [allowed, remaining, retry_after, reset_after] — sem tolerar formato
     * inesperado. Retorna: tupla tipada [bool, int, int, int]. Efeitos
     * colaterais: nenhum; lança LuaScriptFailureException para resposta
     * malformada.
     *
     * @return array{0: bool, 1: int, 2: int, 3: int}
     */
    private function parseReply(string $key, mixed $reply): array
    {
        if (! is_array($reply) || count($reply) < 4
            || ! is_numeric($reply[0]) || ! is_numeric($reply[1])
            || ! is_numeric($reply[2]) || ! is_numeric($reply[3])) {
            throw LuaScriptFailureException::unexpectedReply($key, $reply);
        }

        return [(int) $reply[0] === 1, (int) $reply[1], (int) $reply[2], (int) $reply[3]];
    }
}
