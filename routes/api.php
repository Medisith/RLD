<?php

declare(strict_types=1);

/**
 * Rotas de API do exercício.
 *
 * Responsabilidade: expor a rota de teste protegida EXCLUSIVAMENTE pelo
 * middleware de domínio "rate-limit.advanced". Nenhuma rota deste arquivo
 * usa o middleware "throttle" nativo do Laravel.
 */

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

// Rota de teste do limitador. O nome "rate-limited.ping" é usado como
// {nomeRota} na chave de limitação e como índice das políticas em
// config/rate_limiting.php.
Route::post('/rate-limited/ping', PingController::class)
    ->name('rate-limited.ping')
    ->middleware('rate-limit.advanced');
