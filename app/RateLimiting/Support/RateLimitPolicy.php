<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;

/**
 * DTO imutável que descreve a política de limitação aplicada a uma rota.
 *
 * Responsabilidade: transportar, já validados, os parâmetros de negócio do
 * limitador (capacidade, janela, custo, estratégia de chave e algoritmo).
 * Uma vez construída, a política nunca muda — qualquer combinação inválida
 * falha na construção, nunca em tempo de decisão.
 */
final readonly class RateLimitPolicy
{
    /**
     * Recebe: nome da política (normalmente o nome da rota) e parâmetros de
     * negócio. Faz: valida invariantes (capacidade, janela e custo devem ser
     * positivos; custo não pode exceder a capacidade). Retorna: instância
     * imutável. Efeitos colaterais: nenhum; lança InvalidRateLimitPolicyException
     * se qualquer invariante for violada.
     */
    public function __construct(
        public string $name,
        public int $capacity,
        public int $windowSeconds,
        public int $defaultCost,
        public KeyStrategy $keyStrategy,
        public AvailableAlgorithm $algorithm,
    ) {
        if ($this->name === '') {
            throw InvalidRateLimitPolicyException::forReason('policy name cannot be empty');
        }

        if ($this->capacity < 1) {
            throw InvalidRateLimitPolicyException::forReason(
                "capacity must be >= 1 (received: {$this->capacity}) on policy '{$this->name}'"
            );
        }

        if ($this->windowSeconds < 1) {
            throw InvalidRateLimitPolicyException::forReason(
                "window_seconds must be >= 1 (received: {$this->windowSeconds}) on policy '{$this->name}'"
            );
        }

        if ($this->defaultCost < 1) {
            throw InvalidRateLimitPolicyException::forReason(
                "default_cost must be >= 1 (received: {$this->defaultCost}) on policy '{$this->name}'"
            );
        }

        if ($this->defaultCost > $this->capacity) {
            throw InvalidRateLimitPolicyException::forReason(
                "default_cost ({$this->defaultCost}) cannot exceed capacity ({$this->capacity}) on policy '{$this->name}'"
            );
        }
    }

    /**
     * Recebe: nome da política, array de configuração específico da rota e
     * array de configuração global (valores padrão). Faz: mescla rota sobre
     * global, converte strings da config em enums e valida tudo no
     * construtor. Retorna: RateLimitPolicy pronta para uso. Efeitos
     * colaterais: nenhum; lança InvalidRateLimitPolicyException para estratégia ou
     * algoritmo desconhecidos — falha explícita em vez de assumir default
     * silencioso.
     *
     * @param  array<string, mixed>  $routeConfig
     * @param  array<string, mixed>  $globalConfig
     */
    public static function fromConfig(
        string $name,
        array $routeConfig,
        array $globalConfig,
    ): self {
        $merged = array_merge($globalConfig, $routeConfig);

        $rawStrategy = (string) ($merged['key_strategy'] ?? '');
        $keyStrategy = KeyStrategy::tryFrom($rawStrategy)
            ?? throw InvalidRateLimitPolicyException::forReason(
                "unknown key_strategy '{$rawStrategy}' on policy '{$name}'"
            );

        $rawAlgorithm = (string) ($merged['algorithm'] ?? '');
        $algorithm = AvailableAlgorithm::tryFrom($rawAlgorithm)
            ?? throw InvalidRateLimitPolicyException::forReason(
                "unknown algorithm '{$rawAlgorithm}' on policy '{$name}' — only 'naive' exists in phases 0 and 1"
            );

        return new self(
            name: $name,
            capacity: (int) ($merged['capacity'] ?? 0),
            windowSeconds: (int) ($merged['window_seconds'] ?? 0),
            defaultCost: (int) ($merged['default_cost'] ?? 0),
            keyStrategy: $keyStrategy,
            algorithm: $algorithm,
        );
    }
}
