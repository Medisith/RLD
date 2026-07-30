<?php

declare(strict_types=1);

/**
 * Testes de unidade do RateLimitResult — classes puras, sem framework.
 * Cobrem as fábricas e as invariantes de saneamento, incluindo o
 * resetAfter introduzido na Fase 4 (X-RateLimit-Reset).
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

test('allowed result carries remaining, limit, reset and key', function (): void {
    $result = RateLimitResult::allowed(testPolicy(), 'rate-limit:ip:10.0.0.1:rate-limited.ping', 49, 60);

    expect($result->allowed)->toBeTrue()
        ->and($result->remaining)->toBe(49)
        ->and($result->limit)->toBe(50)
        ->and($result->retryAfter)->toBe(0)
        ->and($result->resetAfter)->toBe(60)
        ->and($result->algorithm)->toBe('naive')
        ->and($result->key)->toBe('rate-limit:ip:10.0.0.1:rate-limited.ping');
});

test('negative remaining is sanitized to zero on allowed result', function (): void {
    // O limitador ingênuo pode ultrapassar a capacidade sob corrida
    // (contador > capacidade). O DTO nunca expõe saldo negativo ao cliente.
    $result = RateLimitResult::allowed(testPolicy(), 'key', -3, 10);

    expect($result->remaining)->toBe(0);
});

test('negative reset is sanitized to zero on allowed result', function (): void {
    $result = RateLimitResult::allowed(testPolicy(), 'key', 5, -2);

    expect($result->resetAfter)->toBe(0);
});

test('denied result zeroes remaining and never instructs immediate retry', function (): void {
    $result = RateLimitResult::denied(testPolicy(), 'key', 0, 0);

    expect($result->allowed)->toBeFalse()
        ->and($result->remaining)->toBe(0)
        ->and($result->retryAfter)->toBe(1)
        // Invariante da Fase 4: reset nunca chega antes do primeiro retry.
        ->and($result->resetAfter)->toBe(1);
});

test('denied result keeps reset greater than or equal to retry', function (): void {
    // Token bucket: uma requisição volta a caber (retry) antes de o balde
    // encher por completo (reset).
    $result = RateLimitResult::denied(testPolicy(), 'key', 10, 50);

    expect($result->retryAfter)->toBe(10)
        ->and($result->resetAfter)->toBe(50);

    // Se o algoritmo reportar reset < retry (não deveria), a fábrica corrige.
    $inverted = RateLimitResult::denied(testPolicy(), 'key', 10, 3);

    expect($inverted->resetAfter)->toBe(10);
});
