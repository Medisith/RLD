<?php

declare(strict_types=1);

namespace App\RateLimiting\Contracts;

use App\RateLimiting\Support\RateLimitPolicy;
use Illuminate\Http\Request;

/**
 * Contrato do resolvedor de chave de limitação.
 *
 * Responsabilidade: definir como uma requisição HTTP vira uma chave única
 * de contagem no Redis. Isolado em contrato para permitir estratégias
 * futuras (por API key, por tenant) sem tocar no middleware.
 */
interface RateLimitKeyResolver
{
    /**
     * Recebe: a requisição HTTP corrente e a política vigente. Faz: aplica a
     * estratégia da política (user | ip | user_or_ip) para identificar
     * o cliente e monta a chave no padrão
     * rate-limit:{strategy}:{identifier}:{routeName}. Retorna: a chave
     * resolvida. Efeitos colaterais: nenhum (não toca no Redis).
     */
    public function resolve(Request $request, RateLimitPolicy $policy): string;
}
