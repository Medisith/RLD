<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * Escopo do balde que produziu uma decisão de limitação (Fase 9).
 *
 * Responsabilidade: fechar em enum a resposta para "QUEM foi limitado" —
 * o cliente individual (IP ou usuário) ou o tenant inteiro. O valor aparece
 * no corpo JSON do 429 e nos logs, para que o consumidor da API saiba se
 * deve esperar sozinho ou se a cota compartilhada da organização acabou.
 */
enum RateLimitScope: string
{
    // Balde do cliente individual — chave rate-limit:{strategy}:{id}:{route}.
    // É o único escopo quando a quota de tenant está desligada (padrão).
    case Client = 'client';

    // Balde compartilhado do tenant — chave rate-limit:tenant:{id}:{route}.
    case Tenant = 'tenant';
}
