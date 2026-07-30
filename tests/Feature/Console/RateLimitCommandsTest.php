<?php

declare(strict_types=1);

/**
 * Testes de feature dos comandos Artisan de operação (Fase 4):
 * rate-limit:inspect, rate-limit:reset e rate-limit:dry-run.
 *
 * Exigem Redis real (banco 15, ver phpunit.xml); pulados com aviso claro
 * quando não há conexão.
 */

use Illuminate\Support\Facades\Redis;

const COMMANDS_TEST_KEY = 'rate-limit:ip:203.0.113.10:rate-limited.ping';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — command tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    Redis::connection()->del(COMMANDS_TEST_KEY);
});

afterEach(function (): void {
    try {
        Redis::connection()->del(COMMANDS_TEST_KEY);
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('inspect reports rest state for a missing key', function (): void {
    $this->artisan('rate-limit:inspect', ['key' => COMMANDS_TEST_KEY])
        ->expectsOutputToContain('state at rest')
        ->assertSuccessful();
});

test('inspect renders a token bucket hash with tokens and ttl', function (): void {
    // Estado plantado diretamente (mesmo formato que o token_bucket.lua
    // grava): o comando deve reconhecer e interpretar.
    Redis::connection()->client()->hMSet(COMMANDS_TEST_KEY, [
        'tokens' => '47.5',
        'last_refill_ms' => (string) (int) (microtime(true) * 1000),
    ]);
    Redis::connection()->client()->expire(COMMANDS_TEST_KEY, 30);

    $this->artisan('rate-limit:inspect', ['key' => COMMANDS_TEST_KEY])
        ->expectsOutputToContain('token_bucket')
        ->expectsOutputToContain('47.5')
        ->assertSuccessful();
});

test('inspect renders a naive string counter', function (): void {
    Redis::connection()->client()->set(COMMANDS_TEST_KEY, '12', ['ex' => 60]);

    $this->artisan('rate-limit:inspect', ['key' => COMMANDS_TEST_KEY])
        ->expectsOutputToContain('naive')
        ->expectsOutputToContain('12')
        ->assertSuccessful();
});

test('reset deletes the key and reports the rest state', function (): void {
    Redis::connection()->client()->set(COMMANDS_TEST_KEY, '12', ['ex' => 60]);

    $this->artisan('rate-limit:reset', ['key' => COMMANDS_TEST_KEY])
        ->expectsOutputToContain('removed')
        ->assertSuccessful();

    expect((bool) Redis::connection()->client()->exists(COMMANDS_TEST_KEY))->toBeFalse();
});

test('reset on a missing key is a calm no-op', function (): void {
    $this->artisan('rate-limit:reset', ['key' => COMMANDS_TEST_KEY])
        ->expectsOutputToContain('already at rest')
        ->assertSuccessful();
});

test('dry-run resolves the effective policy without consuming budget', function (): void {
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            'capacity' => 50,
            'default_cost' => 1,
            'key_strategy' => 'user_or_ip',
            'algorithm' => 'token_bucket',
            'refill_rate' => 1.0,
        ],
    ]);

    $this->artisan('rate-limit:dry-run', [
        'route' => 'rate-limited.ping',
        '--identifier' => '203.0.113.10',
    ])
        ->expectsOutputToContain('token_bucket')
        ->expectsOutputToContain(COMMANDS_TEST_KEY)
        ->expectsOutputToContain('no budget was consumed')
        ->assertSuccessful();

    // Dry-run é leitura: nenhuma chave pode ter sido criada.
    expect((bool) Redis::connection()->client()->exists(COMMANDS_TEST_KEY))->toBeFalse();
});

test('dry-run fails loudly on invalid route configuration', function (): void {
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            // token_bucket sem refill_rate valido: mesma falha que o
            // middleware sofreria em producao.
            'algorithm' => 'token_bucket',
            'refill_rate' => 0,
        ],
    ]);

    $this->artisan('rate-limit:dry-run', ['route' => 'rate-limited.ping'])
        ->expectsOutputToContain('Invalid policy configuration')
        ->assertFailed();
});
