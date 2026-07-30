<?php

declare(strict_types=1);

/**
 * Testes de feature do LeakyBucketRateLimiter (Fase 3).
 *
 * IMPORTANTE — limite honesto destes testes: tudo aqui é SEQUENCIAL. O que
 * eles cobrem é a SEMÂNTICA do algoritmo (represamento até a capacidade,
 * negação com instrução de drenagem, drenagem com o passar do tempo) e o
 * contrato HTTP. A prova de ATOMICIDADE sob concorrência é exclusivamente
 * scripts/prove_race_condition.php --algorithm=leaky_bucket (resultados em
 * docs/fases/fase-3-leaky-bucket.md).
 *
 * Exigem Redis real (banco 15, ver phpunit.xml); pulados com aviso claro
 * quando não há conexão.
 */

use App\RateLimiting\Algorithms\RateLimitAlgorithmFactory;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;
use Illuminate\Support\Facades\Redis;

const LEAKY_BUCKET_TEST_KEY = 'rate-limit:test:leaky-bucket';
const LEAKY_BUCKET_HTTP_TEST_KEY = 'rate-limit:ip:127.0.0.1:rate-limited.ping';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — leaky bucket feature tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    Redis::connection()->del(LEAKY_BUCKET_TEST_KEY);
    Redis::connection()->del(LEAKY_BUCKET_HTTP_TEST_KEY);
});

afterEach(function (): void {
    try {
        Redis::connection()->del(LEAKY_BUCKET_TEST_KEY);
        Redis::connection()->del(LEAKY_BUCKET_HTTP_TEST_KEY);
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('bucket fills up to capacity and then denies with drain instruction', function (): void {
    // leak_rate baixíssimo (0.1/s) para a drenagem não interferir na
    // aritmética do teste dentro da sua duração (< 1s).
    $policy = new RateLimitPolicy(
        name: 'leaky-bucket-test',
        capacity: 3,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::LeakyBucket,
        leakRate: 0.1,
    );

    $limiter = app(RateLimitAlgorithmFactory::class)
        ->forAlgorithm(AvailableAlgorithm::LeakyBucket);

    // O balde aceita até o volume máximo, com espaço livre decrescendo.
    foreach ([2, 1, 0] as $expectedRemaining) {
        $result = $limiter->attempt(LEAKY_BUCKET_TEST_KEY, $policy, 1);

        expect($result->allowed)->toBeTrue()
            ->and($result->remaining)->toBe($expectedRemaining);
    }

    // Balde cheio: negação com retry ~= overflow / leak_rate = 1/0.1 = 10s.
    // Faixa 9..10 tolera a drenagem de microssegundos entre as chamadas.
    $denied = $limiter->attempt(LEAKY_BUCKET_TEST_KEY, $policy, 1);

    expect($denied->allowed)->toBeFalse()
        ->and($denied->remaining)->toBe(0)
        ->and($denied->retryAfter)->toBeGreaterThanOrEqual(9)
        ->and($denied->retryAfter)->toBeLessThanOrEqual(10);

    // Fase 4 — X-RateLimit-Reset: tempo até o balde DRENAR por completo
    // (~ level/leak_rate = 3/0.1 = 30s), sempre >= retryAfter.
    expect($denied->resetAfter)->toBeGreaterThanOrEqual($denied->retryAfter)
        ->and($denied->resetAfter)->toBeGreaterThanOrEqual(29)
        ->and($denied->resetAfter)->toBeLessThanOrEqual(30);
});

test('bucket drains at constant rate and accepts again', function (): void {
    // leak_rate alto (5/s) para o teste ficar rápido: 0.5s drena >= 2 unidades.
    $policy = new RateLimitPolicy(
        name: 'leaky-bucket-test',
        capacity: 2,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::LeakyBucket,
        leakRate: 5.0,
    );

    $limiter = app(RateLimitAlgorithmFactory::class)
        ->forAlgorithm(AvailableAlgorithm::LeakyBucket);

    expect($limiter->attempt(LEAKY_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeTrue();
    expect($limiter->attempt(LEAKY_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeTrue();
    expect($limiter->attempt(LEAKY_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeFalse();

    // 0.5s * 5 unidades/s = 2.5 unidades drenadas (saturado em nível 0).
    usleep(500_000);

    expect($limiter->attempt(LEAKY_BUCKET_TEST_KEY, $policy, 1)->allowed)->toBeTrue();
});

test('http route honors a leaky_bucket policy end to end', function (): void {
    config()->set('rate_limiting.enabled', true);
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            'capacity' => 2,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'leaky_bucket',
            'leak_rate' => 0.1,
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
