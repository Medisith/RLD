<?php

declare(strict_types=1);

namespace App\RateLimiting\Exceptions;

/**
 * Lançada quando uma política de limitação viola invariantes de negócio ou
 * referencia estratégia/algoritmo inexistentes.
 *
 * Responsabilidade: transformar erro de configuração em falha explícita e
 * imediata (na construção da política), em vez de comportamento silencioso
 * errado em produção.
 */
final class InvalidRateLimitPolicyException extends RateLimitException
{
    /**
     * Recebe: motivo humano-legível. Faz: monta a exceção com mensagem
     * padronizada. Retorna: instância pronta para lançar. Efeitos
     * colaterais: nenhum.
     */
    public static function forReason(string $reason): self
    {
        return new self("Invalid rate limit policy: {$reason}.");
    }
}
