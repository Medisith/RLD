<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * DTO imutável com o veredito de uma tentativa de consumo.
 *
 * Responsabilidade: transportar do algoritmo para o middleware tudo que a
 * camada HTTP precisa para responder (permitir/negar, headers de limite,
 * instrução de retry e previsão de reset), sem que o middleware conheça
 * detalhes do algoritmo.
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
        // Consumos ainda disponíveis APÓS esta decisão (>= 0).
        public int $remaining,
        // Capacidade total da política (espelhado em X-RateLimit-Limit).
        public int $limit,
        // Segundos até valer a pena tentar de novo (Retry-After quando negado;
        // 0 quando permitido).
        public int $retryAfter,
        // Segundos até o estado da chave voltar ao REPOUSO (X-RateLimit-Reset,
        // Fase 4): janela expirar (naive), balde encher (token_bucket) ou
        // balde esvaziar (leaky_bucket). Delta em segundos, como Retry-After —
        // consistente entre si e imune a clock skew do cliente. Invariante:
        // resetAfter >= retryAfter quando negado.
        public int $resetAfter,
        // Valor string do algoritmo que decidiu (rastreabilidade em log).
        public string $algorithm,
        // Chave de limitação completa usada na decisão.
        public string $key,
    ) {
    }

    /**
     * Recebe: política, chave, quantidade restante após o consumo e segundos
     * até o estado voltar ao repouso. Faz: monta veredito de permissão com
     * contadores saneados. Retorna: resultado permitido. Efeitos
     * colaterais: nenhum.
     */
    public static function allowed(
        RateLimitPolicy $policy,
        string $key,
        int $remaining,
        int $resetAfter,
    ): self {
        return new self(
            allowed: true,
            remaining: max(0, $remaining),
            limit: $policy->capacity,
            retryAfter: 0,
            resetAfter: max(0, $resetAfter),
            algorithm: $policy->algorithm->value,
            key: $key,
        );
    }

    /**
     * Recebe: política, chave, segundos até valer a pena tentar de novo e
     * segundos até o repouso total. Faz: monta veredito de negação;
     * "remaining" é 0 por definição — se sobrasse saldo, a requisição não
     * teria sido negada. Garante retry >= 1s (nunca convidar o cliente a
     * martelar no instante da expiração) e reset >= retry (o repouso total
     * nunca chega antes da primeira folga). Retorna: resultado negado.
     * Efeitos colaterais: nenhum.
     */
    public static function denied(
        RateLimitPolicy $policy,
        string $key,
        int $retryAfter,
        int $resetAfter,
    ): self {
        $sanitizedRetryAfter = max(1, $retryAfter);

        return new self(
            allowed: false,
            remaining: 0,
            limit: $policy->capacity,
            retryAfter: $sanitizedRetryAfter,
            resetAfter: max($sanitizedRetryAfter, $resetAfter),
            algorithm: $policy->algorithm->value,
            key: $key,
        );
    }
}
