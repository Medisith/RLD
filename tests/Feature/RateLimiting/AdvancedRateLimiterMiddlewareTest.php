<?php

declare(strict_types=1);

/**
 * Testes de feature do AdvancedRateLimiterMiddleware (Fase 1).
 *
 * IMPORTANTE — limite honesto destes testes: tudo aqui é SEQUENCIAL (uma
 * requisição por vez). Teste sequencial NÃO prova nem refuta a race
 * condition do NaiveRedisRateLimiter — com um único processo, a janela
 * entre GET e SET/INCRBY nunca é disputada. A prova de concorrência é
 * exclusivamente scripts/prove_race_condition.php (ver
 * docs/fases/fase-1-race-condition.md).
 *
 * Estes testes exigem um Redis real (banco 15, ver phpunit.xml) e são
 * pulados com aviso claro quando não há conexão — sem números inventados,
 * sem falso verde.
 */

use Illuminate\Support\Facades\Redis;

// Capacidade pequena de propósito: o teste de estouro fica rápido sem
// alterar a semântica (a política de produto 50/60s segue na config real).
const TEST_CAPACITY = 5;
const TEST_KEY = 'rate-limit:ip:127.0.0.1:rate-limited.ping';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — rate limiter feature tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    config()->set('rate_limiting.enabled', true);
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            'capacity' => TEST_CAPACITY,
            'window_seconds' => 60,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'naive',
        ],
    ]);

    // Saldo zerado antes de cada teste: cada cenário começa em janela limpa.
    Redis::connection()->del(TEST_KEY);
});

afterEach(function (): void {
    try {
        Redis::connection()->del(TEST_KEY);
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('request under the limit returns 200 with remaining headers', function (): void {
    $response = $this->postJson('/api/rate-limited/ping');

    $response
        ->assertOk()
        ->assertJsonPath('message', 'pong')
        ->assertHeader('X-RateLimit-Limit', (string) TEST_CAPACITY)
        ->assertHeader('X-RateLimit-Remaining', (string) (TEST_CAPACITY - 1));
});

test('when capacity is exhausted, responds 429 with contract body and headers', function (): void {
    // Consome toda a capacidade SEQUENCIALMENTE (ver aviso no topo: isto não
    // exercita concorrência — apenas o contrato HTTP de negação).
    for ($requestNumber = 1; $requestNumber <= TEST_CAPACITY; $requestNumber++) {
        $this->postJson('/api/rate-limited/ping')->assertOk();
    }

    $deniedResponse = $this->postJson('/api/rate-limited/ping');

    $deniedResponse
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', TEST_CAPACITY)
        ->assertHeader('X-RateLimit-Limit', (string) TEST_CAPACITY)
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect((int) $deniedResponse->headers->get('Retry-After'))
        ->toBeGreaterThanOrEqual(1)
        ->toBeLessThanOrEqual(60);

    expect($deniedResponse->json('message'))
        ->toContain('Rate limit exceeded');
});

test('disabled limiter lets the request through without remaining headers', function (): void {
    config()->set('rate_limiting.enabled', false);

    $response = $this->postJson('/api/rate-limited/ping');

    $response->assertOk()->assertJsonPath('message', 'pong');

    expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});
