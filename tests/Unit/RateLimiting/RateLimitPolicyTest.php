<?php

declare(strict_types=1);

/**
 * Testes de unidade da RateLimitPolicy — classes puras, sem framework.
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
];

test('route config overrides global values and inherits the rest', function (): void {
    $policy = RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['capacity' => 10],
        globalConfig: GLOBAL_TEST_CONFIG,
    );

    expect($policy->capacity)->toBe(10)
        ->and($policy->windowSeconds)->toBe(60)
        ->and($policy->defaultCost)->toBe(1)
        ->and($policy->keyStrategy)->toBe(KeyStrategy::UserOrIp)
        ->and($policy->algorithm)->toBe(AvailableAlgorithm::Naive);
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

test('unknown algorithm is rejected — only naive exists in phases 0 and 1', function (): void {
    RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['algorithm' => 'token_bucket'],
        globalConfig: GLOBAL_TEST_CONFIG,
    );
})->throws(InvalidRateLimitPolicyException::class, 'token_bucket');

test('unknown key strategy is rejected', function (): void {
    RateLimitPolicy::fromConfig(
        name: 'rate-limited.ping',
        routeConfig: ['key_strategy' => 'api_key'],
        globalConfig: GLOBAL_TEST_CONFIG,
    );
})->throws(InvalidRateLimitPolicyException::class, 'api_key');
