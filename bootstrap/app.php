<?php

declare(strict_types=1);

/**
 * Bootstrap da aplicação Laravel.
 *
 * Responsabilidade: montar a aplicação (rotas, middleware, exceções) sem
 * nenhum uso do rate limiter nativo do framework. O alias de middleware
 * "rate-limit.advanced" aponta para a classe de domínio
 * App\RateLimiting\Http\AdvancedRateLimiterMiddleware.
 */

use App\RateLimiting\Http\AdvancedRateLimiterMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias único do exercício: nenhuma referência a "throttle" ou ao
        // ThrottleRequests nativo em nenhum ponto do projeto.
        $middleware->alias([
            'rate-limit.advanced' => AdvancedRateLimiterMiddleware::class,
        ]);

        // --------------------------------------------------------------
        // Proxies confiáveis (Fase 6) — identidade por IP atrás de proxy.
        //
        // Padrão: NENHUM proxy confiável -> X-Forwarded-* é IGNORADO e
        // request->ip() devolve o peer TCP. Isso impede spoofing do header
        // por clientes diretos, mas atrás de um LB todas as requisições
        // teriam o IP do LB (um único balde para todo mundo).
        //
        // Atrás de proxy/LB: defina TRUSTED_PROXIES com a lista dos IPs/
        // CIDRs dos proxies (ex.: "10.0.0.0/8,172.16.0.0/12") ou "*"
        // SOMENTE quando a aplicação for inalcançável fora do LB — confiar
        // em "*" com a porta exposta devolve o spoofing.
        //
        // Atenção (documentado em docs/fases/fase-6): env() aqui roda no
        // bootstrap; com `php artisan config:cache` o .env NÃO é carregado,
        // então TRUSTED_PROXIES precisa existir como variável de ambiente
        // real no processo (systemd/fpm/container), não apenas no .env.
        // --------------------------------------------------------------
        $trustedProxies = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) env('TRUSTED_PROXIES', '')),
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies === ['*'] ? '*' : $trustedProxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
