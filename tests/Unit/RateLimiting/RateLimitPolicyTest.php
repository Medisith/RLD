<?php

declare(strict_types=1);

/**
 * Testes de unidade da RateLimitPolicy — classes puras, sem framework.
 *
 * Cobrem as invariantes gerais (Fases 0 e 1) e as invariantes por algoritmo
 * introduzidas nas Fases 2 e 3 (refill_rate para token_bucket, leak_rate
 * para leaky_bucket).
 */

use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;

const GLOBAL_TEST_CONFIG = [
    'capacity' => 50,
    'window_seconds' => 60,
    'default_cost' => 1,
    'key_strategy' => 'user_or_ip',
    'algorithm' => 'naive',
    'refill_rate' => 1.0,
    'leak_rate' => 1.0,
];

test('route config overrides global values and inherits the rest', function (): void {
    $policy = RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        // default_cost por rota incluído de propósito (Fase 7): o cost
        // override é contrato de config, não acidente da mescla.
        routeConfig: ['capacity' => 10, 'default_cost' => 3, 'algorithm' => 'token_bucket', 'refill_rate' => 2.5],
        globalConfig: GLOBAL_TEST_CONFIG,
    );

    expect($policy->capacity)->toBe(10)
        ->and($policy->windowSeconds)->toBe(60)
        ->and($policy->defaultCost)->toBe(3)
        ->and($policy->keyStrategy)->toBe(KeyStrategy::UserOrIp)
        ->and($policy->algorithm)->toBe(AvailableAlgorithm::TokenBucket)
        ->and($policy->refillRate)->toBe(2.5);
});

test('capacity lower than 1 fails explicitly at construction', function (): void {
    RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['capacity' => 0],
        globalConfig: GLOBAL_TEST_CONFIG,
    );
})->throws(InvalidRateLimitPolicyException::class, 'capacity');

test('default cost greater than capacity is rejected', function (): void {
    RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['capacity' => 2, 'default_cost' => 3],
        globalConfig: GLOBAL_TEST_CONFIG,
    );
})->throws(InvalidRateLimitPolicyException::class, 'default_cost');

test('unknown algorithm is rejected with the list of valid values', function (): void {
    RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['algorithm' => 'sliding_window'],
        globalConfig: GLOBAL_TEST_CONFIG,
    );
})->throws(InvalidRateLimitPolicyException::class, 'sliding_window');

test('unknown key strategy is rejected', function (): void {
    RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['key_strategy' => 'api_key'],
        globalConfig: GLOBAL_TEST_CONFIG,
    );
})->throws(InvalidRateLimitPolicyException::class, 'api_key');

test('token_bucket policy without refill_rate is rejected', function (): void {
    // Constrói direto (sem fromConfig) para simular config global sem taxa.
    new RateLimitPolicy(
        name: 'rate-limited.ping',
        capacity: 50,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::TokenBucket,
        refillRate: null,
    );
})->throws(InvalidRateLimitPolicyException::class, 'refill_rate');

test('token_bucket policy with non-positive refill_rate is rejected', function (): void {
    RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['algorithm' => 'token_bucket', 'refill_rate' => 0],
        globalConfig: GLOBAL_TEST_CONFIG,
    );
})->throws(InvalidRateLimitPolicyException::class, 'refill_rate');

test('leaky_bucket policy without leak_rate is rejected', function (): void {
    new RateLimitPolicy(
        name: 'rate-limited.ping',
        capacity: 50,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::LeakyBucket,
        leakRate: null,
    );
})->throws(InvalidRateLimitPolicyException::class, 'leak_rate');

test('naive policy constructs without any rate — rates are bucket-only concerns', function (): void {
    $policy = new RateLimitPolicy(
        name: 'rate-limited.ping',
        capacity: 50,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::Naive,
    );

    expect($policy->refillRate)->toBeNull()
        ->and($policy->leakRate)->toBeNull();
});
