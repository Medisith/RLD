<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * Algoritmos de limitação registrados no exercício.
 *
 * Responsabilidade: fechar o conjunto de algoritmos aceitos pela config.
 * A seleção pode ser global (rate_limiting.algorithm) ou por rota
 * (policies.{rota}.algorithm); o RateLimitAlgorithmFactory faz match
 * exaustivo sobre este enum — adicionar um caso aqui sem registrar a
 * implementação na fábrica quebra em tempo de execução de forma explícita.
 */
enum AvailableAlgorithm: string
{
    // Fase 1 — contador em janela fixa com check-then-act NÃO atômico.
    // PROPOSITALMENTE INSEGURO: existe para falhar sob concorrência (prova
    // em docs/fases/fase-1-race-condition.md) e permanece no projeto como
    // artefato didático. Não usar em produção.
    case Naive = 'naive';

    // Fase 2 — Token Bucket atômico via script Lua (EVAL). Burst máximo de
    // "capacity" e recarga contínua de "refill_rate" tokens/segundo.
    // Prova de atomicidade em docs/fases/fase-2-token-bucket.md.
    case TokenBucket = 'token_bucket';

    // Fase 3 — Leaky Bucket atômico via script Lua (EVAL). Vazão constante
    // de "leak_rate" unidades/segundo com represamento até "capacity".
    // Comparativo com o Token Bucket em docs/fases/fase-3-leaky-bucket.md.
    case LeakyBucket = 'leaky_bucket';
}
