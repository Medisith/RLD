<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * Algoritmos de limitação registrados no exercício.
 *
 * Responsabilidade: fechar o conjunto de algoritmos aceitos pela config.
 * Nas Fases 0 e 1 existe apenas o caso Naive. Os casos futuros ficam
 * comentados de propósito: mantê-los fora do enum garante que nenhuma
 * config consiga apontar para um algoritmo que ainda não existe.
 */
enum AvailableAlgorithm: string
{
    // Contador em janela fixa com check-then-act NÃO atômico.
    // Existe para falhar sob concorrência (prova da Fase 1) e será
    // substituído na fase futura.
    case Naive = 'naive';

    // Reservado — Fase 2 (Token Bucket atômico via script Lua no Redis):
    // case TokenBucket = 'token_bucket';

    // Reservado — Fase 3 (Leaky Bucket, vazão constante):
    // case LeakyBucket = 'leaky_bucket';
}
