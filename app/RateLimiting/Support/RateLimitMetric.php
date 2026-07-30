<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * Métricas mínimas do limitador (Fase 6).
 *
 * Responsabilidade: fechar em enum o conjunto de contadores observáveis —
 * nome de métrica é contrato, não string solta. Os valores são os nomes
 * expostos pelo comando rate-limit:metrics e usados como campo do hash no
 * Redis e nas linhas de log métrico de fallback.
 */
enum RateLimitMetric: string
{
    // Decisões permitidas pelo middleware (qualquer algoritmo).
    case AllowedTotal = 'allowed_total';

    // Decisões negadas com 429.
    case DeniedTotal = 'denied_total';

    // Falhas de infraestrutura Redis vistas pelo middleware
    // (RedisUnavailableException — dispara o failure_mode).
    case RedisErrorsTotal = 'redis_errors_total';

    // Reidratações NOSCRIPT -> SCRIPT LOAD executadas pelo caminho EVALSHA
    // (Fase 4). Em operação normal fica próximo de zero; crescimento
    // contínuo indica restarts/failovers frequentes ou SCRIPT FLUSH externo.
    case EvalshaReloadTotal = 'evalsha_reload_total';
}
