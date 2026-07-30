<?php

declare(strict_types=1);

/**
 * Testes de feature do AdvancedRateLimiterMiddleware (Fases 1 a 3).
 *
 * Os cenários de contagem abaixo usam a política 'naive' de propósito — o
 * contrato HTTP (200/429/headers) independe do algoritmo, e a seleção
 * por rota dos algoritmos atômicos é coberta em
 * TokenBucketRateLimiterTest e LeakyBucketRateLimiterTest. Os dois últimos
 * testes cobrem o failure_mode (open/closed), honrado desde a Fase 2.
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
        ->assertHeader('X-RateLimit-Remaining', (string) (TEST_CAPACITY - 1))
        // Fase 4: janela recém-aberta no naive -> reset = janela inteira.
        ->assertHeader('X-RateLimit-Reset', '60');
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

    // Fase 4: Reset presente também no 429 e nunca antes do Retry-After
    // (na janela fixa do naive, os dois coincidem).
    expect((int) $deniedResponse->headers->get('X-RateLimit-Reset'))
        ->toBeGreaterThanOrEqual((int) $deniedResponse->headers->get('Retry-After'));

    expect($deniedResponse->json('message'))
        ->toContain('Rate limit exceeded');
});

test('disabled limiter lets the request through without remaining headers', function (): void {
    config()->set('rate_limiting.enabled', false);

    $response = $this->postJson('/api/rate-limited/ping');

    $response->assertOk()->assertJsonPath('message', 'pong');

    expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// failure_mode (honrado desde a Fase 2): os dois testes abaixo derrubam o
// Redis DE VERDADE para este processo — reapontam a conexão para uma porta
// morta e purgam a conexão memoizada do RedisManager — e verificam cada modo.
// ---------------------------------------------------------------------------

test('failure_mode open lets the request through uncounted when Redis is down', function (): void {
    config()->set('rate_limiting.failure_mode', 'open');
    config()->set('database.redis.default.port', 6390); // porta morta
    Redis::purge('default'); // força reconexão com a config quebrada

    $response = $this->postJson('/api/rate-limited/ping');

    // Passa sem contagem e SEM headers de saldo: sem Redis não há números
    // honestos a informar.
    $response->assertOk()->assertJsonPath('message', 'pong');

    expect($response->headers->has('X-RateLimit-Limit'))->toBeFalse();
});

test('failure_mode closed rejects with 503 when Redis is down', function (): void {
    config()->set('rate_limiting.failure_mode', 'closed');
    config()->set('database.redis.default.port', 6390); // porta morta
    Redis::purge('default'); // força reconexão com a config quebrada

    $response = $this->postJson('/api/rate-limited/ping');

    $response
        ->assertStatus(503)
        ->assertJsonPath('code', 'RATE_LIMITER_UNAVAILABLE');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);
});
