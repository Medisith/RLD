<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Contratos;

use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;
use Illuminate\Http\Request;

/**
 * Contrato do resolvedor de chave de limitação.
 *
 * Responsabilidade: definir como uma requisição HTTP vira uma chave única
 * de contagem no Redis. Isolado em contrato para permitir estratégias
 * futuras (por API key, por tenant) sem tocar no middleware.
 */
interface ResolvedorChaveLimitacao
{
    /**
     * Recebe: a requisição HTTP corrente e a política vigente. Faz: aplica a
     * estratégia da política (usuario | ip | usuario_ou_ip) para identificar
     * o cliente e monta a chave no padrão
     * limitacao:{estrategia}:{identificador}:{nomeRota}. Retorna: a chave
     * resolvida. Efeitos colaterais: nenhum (não toca no Redis).
     */
    public function resolver(Request $request, PoliticaLimitacao $politica): string;
}
