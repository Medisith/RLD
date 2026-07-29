<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Excecoes;

use Throwable;

/**
 * Lançada quando o Redis não responde durante uma decisão de limitação.
 *
 * Responsabilidade: converter falhas de infraestrutura (conexão, timeout)
 * em um tipo de domínio explícito. Nas Fases 0 e 1 esta exceção NÃO é
 * tratada pelo middleware de propósito: a política de falha (modo_falha
 * aberto/fechado) está apenas documentada e será implementada em fase
 * futura — ver docs/fases/fase-0-framing.md.
 */
final class ExcecaoRedisIndisponivel extends ExcecaoLimitacao
{
    /**
     * Recebe: causa original da infraestrutura. Faz: embrulha em mensagem de
     * negócio em português preservando a causa para diagnóstico. Retorna:
     * instância pronta para lançar. Efeitos colaterais: nenhum.
     */
    public static function aPartirDe(Throwable $causa): self
    {
        return new self(
            'Redis indisponível para decisão de limitação: '.$causa->getMessage(),
            previous: $causa,
        );
    }
}
