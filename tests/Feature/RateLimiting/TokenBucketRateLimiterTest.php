<?php

declare(strict_types=1);

/**
 * Testes de feature do TokenBucketRateLimiter (Fase 2).
 *
 * IMPORTANTE — limite honesto destes testes: tudo aqui é SEQUENCIAL. O que
 * eles cobrem é a SEMÂNTICA do algoritmo (burst, negação com retry
 * instruído, recarga com o passar do tempo) e o contrato HTTP. A prova de
 * ATOMICIDADE sob concorrência é exclusivamente
 * scripts/prove_race_condition.php --algorithm=token_bucket (resultados em
 * docs/fases/fase-2-token-bucket.md).
 *
 * Exigem Redis real (banco 15, ver phpunit.xml); pulados com aviso claro
 * quando não há conexão.
 */

use App\RateLimiting\Algorithms\RateLimitAlgorithmFactory;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;
use Illuminate\Support\Facades\Redis;

const TOKEN_BUCKET_TEST_KEY = 'rate-limit:test:token-bucket';
const TOKEN_BUCKET_HTTP_TEST_KEY = 'rate-limit:ip:127.0.0.1:rate-limited.ping';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — token bucket feature tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    Redis::connection()->del(TOKEN_BUCKET_TEST_KEY);
    Redis::connection()->del(TOKEN_BUCKET_HTTP_TEST_KEY);
});

afterEach(function (): void {
    try {
        Redis::connection()->del(TOKEN_BUCKET_TEST_KEY);
        Redis::connection()->del(TOKEN_BUCKET_HTTP_TEST_KEY);
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('burst consumes the whole capacity and then denies with honest retry instruction', function (): void {
    // refill_rate baixíssimo (0.1/s) para a recarga não interferir na
    // aritmética do teste dentro da sua duração (< 1s).
    $policy = new RateLimitPolicy(
        name: 'token-bucket-test',
        capacity: 3,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::TokenBucket,
        refillRate: 0.1,
    );

    $limiter = app(RateLimitAlgorithmFactory::class)
        ->forAlgorithm(AvailableAlgorithm::TokenBucket);

    // Burst inteiro admitido de uma vez, com saldo decrescendo.
    foreach ([2, 1, 0] as $expectedRemaining) {
        $result = $limiter->attempt(TOKEN_BUCKET_TEST_KEY, $policy, 1);

        expect($result->allowed)->toBeTrue()
            ->and($result->remaining)->toBe($expectedRemaining);
    }

    // Balde vazio: negação com retry ~= deficit / refill_rate = 1/0.1 = 10s.
    // Faixa 9..10 tolera a recarga de microssegundos entre as chamadas.
    $denied = $limiter->attempt(TOKEN_BUCKET_TEST_KEY, $policy, 1);

    expect($denied->allowed)->toBeFalse()
        ->and($denied->remaining)->toBe(0)
        ->and($denied->retryAfter)->toBeGreaterThanOrEqual(9)
        ->and($denied->retryAfter)->toBeLessThanOrEqual(10);

    // Fase 4 — X-RateLimit-Reset: tempo até o balde ENCHER por completo
    // (~ capacity/refill_rate = 3/0.1 = 30s), sempre >= retryAfter.
    expect($denied->resetAfter)->toBeGreaterThanOrEqual($denied->retryAfter)
        ->and($denied->resetAfter)->toBeGreaterThanOrEqual(29)
        ->and($denied->resetAfter)->toBeLessThanOrEqual(30);
});

test('bucket refills over elapsed time and allows again', function (): void {
    // refill_rate alto (5/s) para o teste ficar rápido: 0.5s repõe >= 2 tokens.
    $policy = new RateLimitPolicy(
        name: 'token-bucket-test',
        capacity: 2,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::TokenBucket,
        refillRate: 5.0,
    );

    $limiter = app(RateLimitAlgorithmFactory::class)
        ->forAlgorithm(AvailableAlgorithm::TokenBucket);

    expect($limiter->attempt(TOKEN_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeTrue();
    expect($limiter->attempt(TOKEN_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeTrue();
    expect($limiter->attempt(TOKEN_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeFalse();

    // 0.5s * 5 tokens/s = 2.5 tokens repostos (saturado em capacity=2).
    usleep(500_000);

    expect($limiter->attempt(TOKEN_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeTrue();
});

test('http route honors a token_bucket policy end to end', function (): void {
    config()->set('rate_limiting.enabled', true);
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            'capacity' => 2,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'token_bucket',
            'refill_rate' => 0.1,
        ],
    ]);

    $this->postJson('/api/rate-limited/ping')
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '2')
        ->assertHeader('X-RateLimit-Remaining', '1');

    $this->postJson('/api/rate-limited/ping')->assertOk();

    $denied = $this->postJson('/api/rate-limited/ping');

    $denied
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect((int) $denied->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});
