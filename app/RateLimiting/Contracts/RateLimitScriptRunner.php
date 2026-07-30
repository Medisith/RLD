<?php

declare(strict_types=1);

namespace App\RateLimiting\Contracts;

use App\RateLimiting\Redis\LuaScript;

/**
 * Porta de execução atômica de scripts Lua no Redis (EVALSHA + fallback).
 *
 * Responsabilidade: expor UMA única operação — executar um script no
 * servidor — para os algoritmos atômicos (Token Bucket, Leaky Bucket).
 *
 * Por que uma porta SEPARADA de RateLimitRedisClient, e não um método a
 * mais naquele contrato: a separação é estrutural, não estética. O
 * NaiveRedisRateLimiter continua dependendo apenas de RateLimitRedisClient
 * (comandos individuais, sem atomicidade composta — o buraco didático da
 * Fase 1 permanece intacto e demonstrável), enquanto os algoritmos das
 * Fases 2 e 3 dependem apenas desta porta. Nenhum algoritmo consegue
 * "meio termo": ou opera em comandos separados e herda a race, ou opera em
 * script atômico e elimina a race por construção.
 *
 * Desde a Fase 4 a porta recebe LuaScript (fonte + SHA-1 pré-computado) e
 * as implementações executam EVALSHA por padrão, reidratando com SCRIPT
 * LOAD quando o servidor responde NOSCRIPT — semântica idêntica ao EVAL,
 * custo de banda por decisão reduzido ao SHA (40 bytes) em vez do fonte
 * inteiro (~4 KB). Trade-off documentado em
 * docs/fases/fase-4-evalsha-and-ops.md.
 */
interface RateLimitScriptRunner
{
    /**
     * Recebe: o script (fonte + SHA-1), a lista de chaves (KEYS) e a lista
     * de argumentos (ARGV). Faz: executa o script no servidor Redis de
     * forma atômica — EVALSHA com o SHA pré-computado; se o servidor não
     * conhecer o script (NOSCRIPT: restart, failover, SCRIPT FLUSH),
     * recarrega com SCRIPT LOAD (verificando que o SHA devolvido confere) e
     * repete o EVALSHA UMA vez. Retorna: a resposta bruta do Redis
     * (tipicamente array de inteiros; a validação de forma é
     * responsabilidade do algoritmo chamador). Efeitos colaterais: os do
     * script; lança RedisUnavailableException em falha de infraestrutura e
     * LuaScriptFailureException para erro de script, SHA divergente ou
     * NOSCRIPT persistente após a reidratação.
     *
     * @param  list<string>  $keys
     * @param  list<int|float|string>  $arguments
     */
    public function evalScript(LuaScript $script, array $keys, array $arguments): mixed;
}
