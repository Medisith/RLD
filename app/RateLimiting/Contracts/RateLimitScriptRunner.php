<?php

declare(strict_types=1);

namespace App\RateLimiting\Contracts;

/**
 * Porta de execução atômica de scripts Lua no Redis (EVAL).
 *
 * Responsabilidade: expor UMA única operação — executar um script no
 * servidor — para os algoritmos atômicos (Token Bucket, Leaky Bucket).
 *
 * Por que uma porta SEPARADA de RateLimitRedisClient, e não um método a
 * mais naquele contrato: a separação é estrutural, não estética. O
 * NaiveRedisRateLimiter continua dependendo apenas de RateLimitRedisClient
 * (comandos individuais, sem atomicidade composta — o buraco didático da
 * Fase 1 permanece intacto e demonstrável), enquanto os algoritmos das
 * Fases 2 e 3 dependem apenas desta porta (EVAL, sem GET/SET soltos).
 * Nenhum algoritmo consegue "meio termo": ou opera em comandos separados e
 * herda a race, ou opera em script atômico e elimina a race por construção.
 */
interface RateLimitScriptRunner
{
    /**
     * Recebe: o código-fonte Lua, a lista de chaves (KEYS) e a lista de
     * argumentos (ARGV). Faz: executa EVAL no servidor Redis — o script
     * roda de forma atômica: nenhum outro comando é intercalado durante a
     * execução. Retorna: a resposta bruta do Redis (tipicamente array de
     * inteiros; a validação de forma é responsabilidade do algoritmo
     * chamador). Efeitos colaterais: os do script; lança
     * RedisUnavailableException em falha de infraestrutura e
     * LuaScriptFailureException quando o servidor reporta erro de script.
     *
     * @param  list<string>  $keys
     * @param  list<int|float|string>  $arguments
     */
    public function evalScript(string $script, array $keys, array $arguments): mixed;
}
