<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Controller da rota de teste do limitador (POST /api/rate-limited/ping).
 *
 * Responsabilidade: nenhuma regra de negócio — existe apenas para provar
 * que a requisição atravessou o AdvancedRateLimiterMiddleware e chegou à
 * camada de aplicação. Controller de ação única, propositalmente fino.
 */
class PingController extends Controller
{
    /**
     * Recebe: nada além da requisição já autorizada pelo middleware. Faz:
     * responde um pong com o horário do servidor. Retorna: JsonResponse 200.
     * Efeitos colaterais: nenhum.
     */
    public function __invoke(): JsonResponse
    {
        return new JsonResponse([
            'message' => 'pong',
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
