<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Excecoes;

/**
 * Lançada quando uma política de limitação viola invariantes de negócio ou
 * referencia estratégia/algoritmo inexistentes.
 *
 * Responsabilidade: transformar erro de configuração em falha explícita e
 * imediata (na construção da política), em vez de comportamento silencioso
 * errado em produção.
 */
final class ExcecaoPoliticaInvalida extends ExcecaoLimitacao
{
    /**
     * Recebe: motivo humano-legível em português. Faz: monta a exceção com
     * mensagem padronizada. Retorna: instância pronta para lançar. Efeitos
     * colaterais: nenhum.
     */
    public static function porMotivo(string $motivo): self
    {
        return new self("Política de limitação inválida: {$motivo}.");
    }
}
