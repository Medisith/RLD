<?php

declare(strict_types=1);

/**
 * Rotas de API do exercício.
 *
 * Responsabilidade: expor a rota de teste. O middleware de limitação será
 * ligado em commit posterior da Fase 1.
 */

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

Route::post('/limitado/ping', PingController::class)
    ->name('limitado.ping');
