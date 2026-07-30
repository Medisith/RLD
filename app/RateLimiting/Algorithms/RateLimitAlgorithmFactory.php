<?php

declare(strict_types=1);

namespace App\RateLimiting\Algorithms;

use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Contracts\RateLimitScriptRunner;
use App\RateLimiting\Support\AvailableAlgorithm;

/**
 * Fábrica de algoritmos de limitação — seleção por política (Fases 2 e 3).
 *
 * Responsabilidade: ser o ÚNICO lugar que conhece o mapa
 * AvailableAlgorithm -> implementação concreta. O middleware pede aqui o
 * algoritmo que a política da rota declara; o provider pede aqui o
 * algoritmo global padrão. O match é exaustivo de propósito: um caso novo
 * no enum sem braço correspondente falha de forma explícita
 * (UnhandledMatchError), nunca silenciosamente.
 *
 * Observe as dependências assimétricas por construção: o naive recebe a
 * porta de comandos individuais (e herda a race da Fase 1); os atômicos
 * recebem SOMENTE a porta de EVAL. Nenhum algoritmo consegue misturar os
 * dois mundos.
 */
final class RateLimitAlgorithmFactory
{
    /**
     * Instâncias memoizadas por algoritmo: os limitadores são imutáveis e o
     * carregamento do script Lua (I/O de arquivo) acontece uma única vez
     * por processo, não por requisição.
     *
     * @var array<string, RateLimitAlgorithm>
     */
    private array $instances = [];

    /**
     * Recebe: a porta de comandos individuais (para o naive) e a porta de
     * scripts atômicos (para token/leaky). Faz: guarda dependências.
     * Retorna: instância pronta. Efeitos colaterais: nenhum.
     */
    public function __construct(
        private readonly RateLimitRedisClient $redisClient,
        private readonly RateLimitScriptRunner $scriptRunner,
    ) {
    }

    /**
     * Recebe: o algoritmo pedido pela política (ou pela config global).
     * Faz: devolve a implementação correspondente, construindo e memoizando
     * na primeira chamada. Retorna: RateLimitAlgorithm pronto para decidir.
     * Efeitos colaterais: na primeira chamada de token/leaky, leitura do
     * arquivo .lua (LuaScriptFailureException se ausente).
     */
    public function forAlgorithm(AvailableAlgorithm $algorithm): RateLimitAlgorithm
    {
        return $this->instances[$algorithm->value] ??= match ($algorithm) {
            AvailableAlgorithm::Naive => new NaiveRedisRateLimiter(
                redisClient: $this->redisClient,
            ),
            AvailableAlgorithm::TokenBucket => new TokenBucketRateLimiter(
                scriptRunner: $this->scriptRunner,
            ),
            AvailableAlgorithm::LeakyBucket => new LeakyBucketRateLimiter(
                scriptRunner: $this->scriptRunner,
            ),
        };
    }
}
