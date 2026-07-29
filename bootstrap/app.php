<?php

declare(strict_types=1);

/**
 * Bootstrap da aplicação Laravel.
 *
 * Responsabilidade: montar a aplicação (rotas, middleware, exceções) sem
 * nenhum uso do rate limiter nativo do framework. O alias de middleware
 * "limitacao.avancada" aponta para a classe de domínio
 * App\LimitacaoRequisicoes\Http\MiddlewareLimitacaoAvancada.
 */

use App\LimitacaoRequisicoes\Http\MiddlewareLimitacaoAvancada;
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
            'limitacao.avancada' => MiddlewareLimitacaoAvancada::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
