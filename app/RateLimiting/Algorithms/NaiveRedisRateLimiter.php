<?php

declare(strict_types=1);

namespace App\RateLimiting\Algorithms;

use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Support\RateLimitPolicy;
use App\RateLimiting\Support\RateLimitResult;

/**
 * Limitador ingênuo por contador em janela fixa — check-then-act SEM
 * atomicidade.
 *
 * ============================ AVISO DE PROJETO ============================
 * ESTA CLASSE É PROPOSITALMENTE INCORRETA SOB CONCORRÊNCIA. Ela existe para
 * a Fase 1 do exercício: demonstrar, com números reais, por que "ler no
 * Redis, decidir no PHP e escrever no Redis" NÃO funciona como rate limiter
 * distribuído. A prova empírica está em scripts/prove_race_condition.php e
 * os resultados em docs/fases/fase-1-race-condition.md. As versões corretas
 * existem desde as Fases 2 e 3 (TokenBucketRateLimiter e
 * LeakyBucketRateLimiter, atômicos via script Lua) atrás do MESMO contrato
 * RateLimitAlgorithm; esta classe permanece no projeto apenas como artefato
 * didático comparativo. NÃO USE ESTA CLASSE EM PRODUÇÃO.
 * ==========================================================================
 *
 * Responsabilidade: manter um contador de consumo por chave com TTL igual à
 * janela da política, decidindo permitir/negar pela comparação
 * contador + custo <= capacidade.
 */
final readonly class NaiveRedisRateLimiter implements RateLimitAlgorithm
{
    /**
     * Recebe: a porta de acesso ao Redis (somente comandos individuais —
     * ver RateLimitRedisClient). Faz: guarda a dependência. Retorna:
     * instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        private RateLimitRedisClient $redisClient,
    ) {
    }

    /**
     * Recebe: chave resolvida, política vigente e custo da requisição.
     * Faz: executa o ciclo check-then-act descrito abaixo. Retorna:
     * RateLimitResult com o veredito. Efeitos colaterais: lê e escreve o
     * contador no Redis em COMANDOS SEPARADOS (esta é a falha estudada);
     * lança InvalidRateLimitPolicyException para custo < 1 e propaga
     * RedisUnavailableException da infraestrutura.
     */
    public function attempt(string $key, RateLimitPolicy $policy, int $cost): RateLimitResult
    {
        if ($cost < 1) {
            throw InvalidRateLimitPolicyException::forReason(
                "cost must be >= 1 (received: {$cost}) while consuming key '{$key}'"
            );
        }

        // ------------------------------------------------------------------
        // PASSO 1 — CHECK: leitura do contador (comando GET isolado).
        // ------------------------------------------------------------------
        $rawValue = $this->redisClient->get($key);
        $consumed = $rawValue === null ? 0 : (int) $rawValue;

        // ------------------------------------------------------------------
        // PASSO 2 — DECISÃO: tomada AQUI, no PHP, sobre um valor que já pode
        // estar obsoleto.
        //
        // >>> NÃO É ATÔMICO — JANELA DE CORRIDA <<<
        // Entre o GET acima e o SET/INCRBY abaixo, N outros processos podem
        // executar o MESMO GET e ler o MESMO valor. Todos comparam contra a
        // capacidade usando um retrato desatualizado, todos concluem "ainda
        // cabe" e todos escrevem. Resultado: mais consumos admitidos do que
        // a capacidade permite (prova na Fase 1). Nenhuma ordenação de
        // comandos individuais elimina isso; a correção exige que
        // leitura+decisão+escrita virem UMA operação atômica no servidor
        // (script Lua — fase futura).
        // ------------------------------------------------------------------
        if ($consumed + $cost > $policy->capacity) {
            // Mais um comando separado (TTL): o valor pode mudar entre a
            // decisão e esta leitura — aceitável apenas porque é informativo
            // (Retry-After), não decisório.
            $remainingTtl = $this->redisClient->ttl($key);

            // TTL -2 (chave expirou entre os comandos) ou -1 (sem TTL,
            // fruto de outra corrida documentada abaixo): instrui a
            // janela cheia por honestidade conservadora.
            $secondsUntilWindowExpires = $remainingTtl > 0 ? $remainingTtl : $policy->windowSeconds;

            return RateLimitResult::denied(
                policy: $policy,
                key: $key,
                retryAfter: $secondsUntilWindowExpires,
                // Na janela fixa, "voltar ao repouso" e "valer a pena tentar
                // de novo" são o MESMO instante: a expiração da janela.
                resetAfter: $secondsUntilWindowExpires,
            );
        }

        // ------------------------------------------------------------------
        // PASSO 3 — ACT: escrita em comando separado do GET (o "buraco").
        // ------------------------------------------------------------------
        if ($rawValue === null) {
            // Primeira requisição da janela (segundo este processo): SET com
            // TTL. >>> VULNERÁVEL <<< Se dois processos entram aqui ao mesmo
            // tempo, o segundo SET SOBRESCREVE o contador do primeiro
            // (consumo perdido) e reinicia o TTL (janela alongada). Ambos são
            // admitidos como se fossem "o primeiro".
            $this->redisClient->setWithTtl(
                key: $key,
                value: $cost,
                ttlSeconds: $policy->windowSeconds,
            );

            $consumedAfter = $cost;
            // Janela recém-aberta: o reset (X-RateLimit-Reset) é a janela inteira.
            $secondsUntilReset = $policy->windowSeconds;
        } else {
            // Chave já existia: INCRBY. O incremento em si é atômico no
            // Redis, mas a DECISÃO que o autorizou foi tomada sobre leitura
            // velha — o contador pode ultrapassar a capacidade neste exato
            // comando (é o excesso que a prova da Fase 1 mede).
            $consumedAfter = $this->redisClient->increment($key, $cost);

            // Reparo de TTL órfão: se a chave expirou entre o GET e o INCRBY,
            // o INCRBY a recriou SEM TTL (contador eterno). O EXPIRE abaixo
            // remenda — em MAIS um comando separado, ele próprio sujeito a
            // corrida. A necessidade deste remendo é sintoma do desenho
            // errado, não solução. O TTL lido aqui também alimenta o
            // X-RateLimit-Reset (leitura informativa, não decisória).
            $secondsUntilReset = $this->redisClient->ttl($key);

            if ($secondsUntilReset < 0) {
                $this->redisClient->expire($key, $policy->windowSeconds);
                $secondsUntilReset = $policy->windowSeconds;
            }
        }

        return RateLimitResult::allowed(
            policy: $policy,
            key: $key,
            remaining: $policy->capacity - $consumedAfter,
            resetAfter: $secondsUntilReset,
        );
    }
}
