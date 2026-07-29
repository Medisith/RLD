<?php

declare(strict_types=1);

/**
 * Bootstrap da aplicação Laravel.
 *
 * Responsabilidade: montar a aplicação (rotas, middleware, exceções).
 * O alias limitacao.avancada será registrado junto do middleware na Fase 1.
 */

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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
