<?php

declare(strict_types=1);

/**
 * Testes de feature da observabilidade do limitador (Fase 6): logs
 * estruturados sem PII crua, request_id propagado e contadores de métricas.
 *
 * Exigem Redis real (banco 15); pulados com aviso quando não há conexão.
 */

use App\RateLimiting\Support\RateLimitMetrics;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

const OBSERVABILITY_TEST_KEY = 'rate-limit:ip:127.0.0.1:rate-limited.ping';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — observability tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    config()->set('rate_limiting.enabled', true);
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            // Capacidade 1: a 2ª requisição nega — deny barato de provocar.
            'capacity' => 1,
            'window_seconds' => 60,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'naive',
        ],
    ]);

    Redis::connection()->del(OBSERVABILITY_TEST_KEY);
    app(RateLimitMetrics::class)->reset();
});

afterEach(function (): void {
    try {
        Redis::connection()->del(OBSERVABILITY_TEST_KEY);
        app(RateLimitMetrics::class)->reset();
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('deny produces a structured log without raw ip and with request id', function (): void {
    Log::spy();

    $this->postJson('/api/rate-limited/ping')->assertOk();
    $this->postJson('/api/rate-limited/ping', [], ['X-Request-Id' => 'req-abc-123'])
        ->assertStatus(429);

    Log::shouldHaveReceived('info')
        ->withArgs(function (string $message, array $context): bool {
            if (! str_contains($message, 'denied')) {
                return false;
            }

            // Chave presente e pseudonimizada: estrutura legível, PII não.
            return str_starts_with((string) $context['key'], 'rate-limit:ip:')
                && ! str_contains((string) $context['key'], '127.0.0.1')
                && ($context['request_id'] ?? null) === 'req-abc-123'
                && ($context['algorithm'] ?? null) === 'naive';
        })
        ->once();
});

test('metric counters reflect allowed and denied decisions', function (): void {
    $this->postJson('/api/rate-limited/ping')->assertOk();
    $this->postJson('/api/rate-limited/ping')->assertStatus(429);

    $snapshot = app(RateLimitMetrics::class)->snapshot();

    expect($snapshot['allowed_total'])->toBe(1)
        ->and($snapshot['denied_total'])->toBe(1)
        ->and($snapshot['redis_errors_total'])->toBe(0);
});

test('metrics command renders every counter and supports reset', function (): void {
    $this->postJson('/api/rate-limited/ping')->assertOk();

    $this->artisan('rate-limit:metrics')
        ->expectsOutputToContain('allowed_total')
        ->expectsOutputToContain('denied_total')
        ->expectsOutputToContain('redis_errors_total')
        ->expectsOutputToContain('evalsha_reload_total')
        ->assertSuccessful();

    $this->artisan('rate-limit:metrics', ['--reset' => true])
        ->expectsOutputToContain('Metric counters reset.')
        ->assertSuccessful();

    expect(app(RateLimitMetrics::class)->snapshot()['allowed_total'])->toBe(0);
});

test('evalsha rehydration is counted after a script flush', function (): void {
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            'capacity' => 5,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'token_bucket',
            'refill_rate' => 0.1,
        ],
    ]);

    // Garante caminho frio: o cache de scripts do servidor é esvaziado.
    Redis::connection()->client()->script('flush');

    $this->postJson('/api/rate-limited/ping')->assertOk();

    expect(app(RateLimitMetrics::class)->snapshot()['evalsha_reload_total'])->toBe(1);
});
