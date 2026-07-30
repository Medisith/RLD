<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * Modos de falha do limitador quando o Redis está indisponível.
 *
 * Responsabilidade: fechar em enum a decisão de resiliência documentada na
 * Fase 0 e HONRADA pelo middleware a partir da Fase 2: com o Redis fora,
 * ou a requisição passa sem contagem (Open) ou é negada com 503 (Closed).
 * Só se aplica a RedisUnavailableException — bug de script Lua
 * (LuaScriptFailureException) NUNCA é absorvido por fail-open.
 */
enum FailureMode: string
{
    // Prioriza disponibilidade do produto: Redis fora -> requisição passa
    // sem contagem (e sem headers de saldo — não há números honestos a dar).
    case Open = 'open';

    // Prioriza proteção do backend: Redis fora -> 503 imediato.
    case Closed = 'closed';
}
