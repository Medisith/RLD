<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Controller da rota de teste do limitador (POST /api/limitado/ping).
 *
 * Responsabilidade: nenhuma regra de negócio — existe apenas para provar
 * que a requisição atravessou o MiddlewareLimitacaoAvancada e chegou à
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
            'mensagem' => 'pong',
            'horario_servidor' => now()->toIso8601String(),
        ]);
    }
}
