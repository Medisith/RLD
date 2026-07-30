<?php

declare(strict_types=1);

namespace App\RateLimiting\Contracts;

use App\RateLimiting\Support\RateLimitPolicy;
use App\RateLimiting\Support\RateLimitResult;

/**
 * Contrato de todo algoritmo de limitação do exercício.
 *
 * Responsabilidade: definir a única porta entre a camada HTTP e a lógica de
 * decisão. O middleware conhece apenas este contrato — foi exatamente essa
 * fronteira que permitiu entregar o Token Bucket (Fase 2) e o Leaky Bucket
 * (Fase 3) sem alterar a orquestração HTTP, apenas o wiring.
 */
interface RateLimitAlgorithm
{
    /**
     * Recebe: chave de limitação completa (padrão
     * rate-limit:{strategy}:{identifier}:{routeName}), a política vigente
     * e o custo desta requisição. Faz: tenta consumir "cost" unidades do
     * saldo da chave dentro da janela da política. Retorna:
     * RateLimitResult com veredito, saldo restante e instrução de retry.
     * Efeitos colaterais: lê e escreve contadores no Redis; lança
     * RedisUnavailableException se a infraestrutura não responder.
     */
    public function attempt(string $key, RateLimitPolicy $policy, int $cost): RateLimitResult;
}
