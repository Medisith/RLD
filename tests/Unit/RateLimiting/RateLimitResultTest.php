<?php

declare(strict_types=1);

/**
 * Testes de unidade do RateLimitResult — classes puras, sem framework.
 */

use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;
use App\RateLimiting\Support\RateLimitResult;

function testPolicy(): RateLimitPolicy
{
    return new RateLimitPolicy(
        name: 'rate-limited.ping',
        capacity: 50,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::Naive,
    );
}

test('allowed result carries remaining balance, limit and key', function (): void {
    $result = RateLimitResult::allowed(testPolicy(), 'rate-limit:ip:10.0.0.1:rate-limited.ping', 49);

    expect($result->allowed)->toBeTrue()
        ->and($result->remaining)->toBe(49)
        ->and($result->limit)->toBe(50)
        ->and($result->retryAfter)->toBe(0)
        ->and($result->algorithm)->toBe('naive')
        ->and($result->key)->toBe('rate-limit:ip:10.0.0.1:rate-limited.ping');
});

test('negative remaining is sanitized to zero on allowed result', function (): void {
    $result = RateLimitResult::allowed(testPolicy(), 'key', -3);

    expect($result->remaining)->toBe(0);
});

test('denied result zeroes remaining and never instructs immediate retry', function (): void {
    $result = RateLimitResult::denied(testPolicy(), 'key', 0);

    expect($result->allowed)->toBeFalse()
        ->and($result->remaining)->toBe(0)
        ->and($result->retryAfter)->toBe(1);
});
