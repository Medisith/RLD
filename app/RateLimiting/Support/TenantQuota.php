<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * DTO imutável com a quota de tenant aplicável a uma requisição (Fase 9).
 *
 * Responsabilidade: transportar o par (política do tenant, chave do tenant)
 * já resolvido e validado, para que o middleware apenas execute o segundo
 * check sem conhecer regras de resolução de tenant. A ausência deste objeto
 * (null vindo do TenantQuotaResolver) significa, sem ambiguidade, "nenhuma
 * quota de tenant se aplica a esta requisição".
 */
final readonly class TenantQuota
{
    /**
     * Recebe: identificador do tenant já sanitizado, a política do balde do
     * tenant e a chave canônica correspondente. Faz: apenas transporta.
     * Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        public string $tenantId,
        public RateLimitPolicy $policy,
        public string $key,
    ) {
    }
}
