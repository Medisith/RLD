<?php

declare(strict_types=1);

namespace App\RateLimiting\Exceptions;

use Throwable;

/**
 * Lançada quando o Redis não responde durante uma decisão de limitação.
 *
 * Responsabilidade: converter falhas de infraestrutura (conexão, timeout)
 * em um tipo de domínio explícito. Nas Fases 0 e 1 esta exceção NÃO é
 * tratada pelo middleware de propósito: a política de falha (failure_mode
 * open/closed) está apenas documentada e será implementada em fase
 * futura — ver docs/fases/fase-0-framing.md.
 */
final class RedisUnavailableException extends RateLimitException
{
    /**
     * Recebe: causa original da infraestrutura. Faz: embrulha em mensagem de
     * negócio preservando a causa para diagnóstico. Retorna: instância
     * pronta para lançar. Efeitos colaterais: nenhum.
     */
    public static function from(Throwable $previous): self
    {
        return new self(
            'Redis unavailable for rate-limit decision: '.$previous->getMessage(),
            previous: $previous,
        );
    }
}
