<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Contratos;

use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;
use App\LimitacaoRequisicoes\Suporte\ResultadoLimitacao;

/**
 * Contrato de todo algoritmo de limitação do exercício.
 *
 * Responsabilidade: definir a única porta entre a camada HTTP e a lógica de
 * decisão. O middleware conhece apenas este contrato — trocar o limitador
 * ingênuo pelo Token Bucket atômico (fase futura) não altera o middleware.
 */
interface AlgoritmoLimitacao
{
    /**
     * Recebe: chave de limitação completa (padrão
     * limitacao:{estrategia}:{identificador}:{nomeRota}), a política vigente
     * e o custo desta requisição. Faz: tenta consumir "custo" unidades do
     * saldo da chave dentro da janela da política. Retorna:
     * ResultadoLimitacao com veredito, saldo restante e instrução de retry.
     * Efeitos colaterais: lê e escreve contadores no Redis; lança
     * ExcecaoRedisIndisponivel se a infraestrutura não responder.
     */
    public function tentar(string $chave, PoliticaLimitacao $politica, int $custo): ResultadoLimitacao;
}
