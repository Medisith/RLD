<?php

declare(strict_types=1);

/**
 * Rotas de API do exercício.
 *
 * Responsabilidade: expor a rota de teste protegida EXCLUSIVAMENTE pelo
 * middleware de domínio "limitacao.avancada". Nenhuma rota deste arquivo
 * usa o middleware "throttle" nativo do Laravel.
 */

use App\Http\Controllers\PingController;
use Illuminate\Support\Facades\Route;

// Rota de teste do limitador. O nome "limitado.ping" é usado como
// {nomeRota} na chave de limitação e como índice das políticas em
// config/limitacao_requisicoes.php.
Route::post('/limitado/ping', PingController::class)
    ->name('limitado.ping')
    ->middleware('limitacao.avancada');
