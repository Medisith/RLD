<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;

/**
 * DTO imutável que descreve a política de limitação aplicada a uma rota.
 *
 * Responsabilidade: transportar, já validados, os parâmetros de negócio do
 * limitador. Uma vez construída, a política nunca muda — qualquer combinação
 * inválida falha na construção, nunca em tempo de decisão.
 *
 * Parâmetros por algoritmo (validação condicionada ao algoritmo escolhido):
 *   naive        -> capacity + window_seconds (janela fixa)
 *   token_bucket -> capacity (burst máximo) + refill_rate (tokens/segundo)
 *   leaky_bucket -> capacity (volume máximo) + leak_rate (drenagem/segundo)
 */
final readonly class RateLimitPolicy
{
    /**
     * Recebe: nome da política (normalmente o nome da rota) e parâmetros de
     * negócio. Faz: valida invariantes gerais (capacidade, janela e custo
     * positivos; custo <= capacidade) e as invariantes específicas do
     * algoritmo (refill_rate > 0 para token_bucket; leak_rate > 0 para
     * leaky_bucket). Retorna: instância imutável. Efeitos colaterais:
     * nenhum; lança InvalidRateLimitPolicyException se qualquer invariante
     * for violada.
     */
    public function __construct(
        public string $name,
        public int $capacity,
        public int $windowSeconds,
        public int $defaultCost,
        public KeyStrategy $keyStrategy,
        public AvailableAlgorithm $algorithm,
        // Tokens recarregados por segundo — obrigatório (> 0) quando
        // algorithm = token_bucket; ignorado pelos demais.
        public ?float $refillRate = null,
        // Unidades drenadas por segundo — obrigatório (> 0) quando
        // algorithm = leaky_bucket; ignorado pelos demais.
        public ?float $leakRate = null,
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

        // Invariantes específicas por algoritmo: falha na CONSTRUÇÃO, nunca
        // dentro do script Lua — um EVAL com taxa inválida produziria
        // divisão por zero ou TTL sem sentido silenciosamente.
        if ($this->algorithm === AvailableAlgorithm::TokenBucket
            && ($this->refillRate === null || $this->refillRate <= 0.0)) {
            throw InvalidRateLimitPolicyException::forReason(
                sprintf(
                    "refill_rate must be > 0 (received: %s) on token_bucket policy '%s'",
                    $this->refillRate === null ? 'null' : (string) $this->refillRate,
                    $this->name,
                )
            );
        }

        if ($this->algorithm === AvailableAlgorithm::LeakyBucket
            && ($this->leakRate === null || $this->leakRate <= 0.0)) {
            throw InvalidRateLimitPolicyException::forReason(
                sprintf(
                    "leak_rate must be > 0 (received: %s) on leaky_bucket policy '%s'",
                    $this->leakRate === null ? 'null' : (string) $this->leakRate,
                    $this->name,
                )
            );
        }
    }

    /**
     * Recebe: nome da política, array de configuração específico da rota e
     * array de configuração global (valores padrão). Faz: mescla rota sobre
     * global, converte strings da config em enums e valida tudo no
     * construtor. Retorna: RateLimitPolicy pronta para uso. Efeitos
     * colaterais: nenhum; lança InvalidRateLimitPolicyException para
     * estratégia ou algoritmo desconhecidos — falha explícita em vez de
     * assumir default silencioso.
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
                "unknown algorithm '{$rawAlgorithm}' on policy '{$name}' — valid values: naive, token_bucket, leaky_bucket"
            );

        return new self(
            name: $name,
            capacity: (int) ($merged['capacity'] ?? 0),
            windowSeconds: (int) ($merged['window_seconds'] ?? 0),
            defaultCost: (int) ($merged['default_cost'] ?? 0),
            keyStrategy: $keyStrategy,
            algorithm: $algorithm,
            refillRate: isset($merged['refill_rate']) ? (float) $merged['refill_rate'] : null,
            leakRate: isset($merged['leak_rate']) ? (float) $merged['leak_rate'] : null,
        );
    }
}
