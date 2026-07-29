<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * DTO imutável com o veredito de uma tentativa de consumo.
 *
 * Responsabilidade: transportar do algoritmo para o middleware tudo que a
 * camada HTTP precisa para responder (permitir/negar, headers de limite e
 * instrução de retry), sem que o middleware conheça detalhes do algoritmo.
 */
final readonly class RateLimitResult
{
    /**
     * Recebe: veredito e contadores já calculados pelo algoritmo. Faz:
     * apenas transporta (normalização de negativos fica a cargo das
     * fábricas). Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        // true se a requisição pode prosseguir.
        public bool $allowed,
        // Consumos ainda disponíveis na janela APÓS esta decisão (>= 0).
        public int $remaining,
        // Capacidade total da política (espelhado em X-RateLimit-Limit).
        public int $limit,
        // Segundos até valer a pena tentar de novo (Retry-After quando negado;
        // 0 quando permitido).
        public int $retryAfter,
        // Valor string do algoritmo que decidiu (rastreabilidade em log).
        public string $algorithm,
        // Chave de limitação completa usada na decisão.
        public string $key,
    ) {
    }

    /**
     * Recebe: política, chave e quantidade restante após o consumo. Faz:
     * monta veredito de permissão com contadores saneados. Retorna:
     * resultado permitido. Efeitos colaterais: nenhum.
     */
    public static function allowed(
        RateLimitPolicy $policy,
        string $key,
        int $remaining,
    ): self {
        return new self(
            allowed: true,
            remaining: max(0, $remaining),
            limit: $policy->capacity,
            retryAfter: 0,
            algorithm: $policy->algorithm->value,
            key: $key,
        );
    }

    /**
     * Recebe: política, chave e segundos até a janela expirar. Faz: monta
     * veredito de negação; "remaining" é 0 por definição — se sobrasse saldo,
     * a requisição não teria sido negada. Retorna: resultado negado.
     * Efeitos colaterais: nenhum.
     */
    public static function denied(
        RateLimitPolicy $policy,
        string $key,
        int $retryAfter,
    ): self {
        return new self(
            allowed: false,
            remaining: 0,
            limit: $policy->capacity,
            // Nunca instruir retry imediato (mínimo de 1s) para não convidar
            // o cliente a martelar a API no instante da expiração.
            retryAfter: max(1, $retryAfter),
            algorithm: $policy->algorithm->value,
            key: $key,
        );
    }
}
