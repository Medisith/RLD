<?php

declare(strict_types=1);

namespace App\RateLimiting\Exceptions;

use RuntimeException;

/**
 * Exceção base do domínio de limitação de requisições.
 *
 * Responsabilidade: dar um tipo único capturável para toda falha originada
 * no limitador, permitindo que camadas superiores tratem "qualquer erro de
 * limitação" sem capturar RuntimeException genérica.
 */
abstract class RateLimitException extends RuntimeException
{
}
