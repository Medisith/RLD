<?php

declare(strict_types=1);

/**
 * Testes de feature do fluxo EVALSHA com reidratação NOSCRIPT (Fase 4).
 *
 * Cobrem o caminho FRIO do RateLimitScriptRunner: depois de um SCRIPT FLUSH
 * real (que simula restart/failover do Redis, quando o cache de scripts do
 * servidor é perdido), a próxima decisão deve reidratar o script via
 * SCRIPT LOAD de forma TRANSPARENTE — sem erro, sem decisão perdida e com
 * o estado do balde preservado (o FLUSH apaga scripts, não chaves).
 *
 * Exigem Redis real (banco 15, ver phpunit.xml); pulados com aviso claro
 * quando não há conexão.
 */

use App\RateLimiting\Algorithms\RateLimitAlgorithmFactory;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;
use Illuminate\Support\Facades\Redis;

const EVALSHA_TEST_KEY = 'rate-limit:test:evalsha-reload';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — EVALSHA reload tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    Redis::connection()->del(EVALSHA_TEST_KEY);
});

afterEach(function (): void {
    try {
        Redis::connection()->del(EVALSHA_TEST_KEY);
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('decision survives SCRIPT FLUSH transparently and keeps bucket state', function (): void {
    $policy = new RateLimitPolicy(
        name: 'evalsha-reload-test',
        capacity: 5,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::TokenBucket,
        refillRate: 0.1,
    );

    $limiter = app(RateLimitAlgorithmFactory::class)
        ->forAlgorithm(AvailableAlgorithm::TokenBucket);

    // 1ª decisão: garante o script carregado no servidor (caminho frio ou
    // quente, tanto faz) e consome 1 token.
    $first = $limiter->attempt(EVALSHA_TEST_KEY, $policy, 1);

    expect($first->allowed)->toBeTrue()
        ->and($first->remaining)->toBe(4);

    // Simula restart/failover: o cache de scripts do servidor é perdido,
    // mas as CHAVES (estado dos baldes) permanecem.
    Redis::connection()->client()->script('flush');

    // 2ª decisão: deve reidratar via SCRIPT LOAD sem nenhum erro visível e
    // continuar a contagem de onde parou (remaining 4 -> 3), provando que
    // o estado não foi tocado pela reidratação.
    $second = $limiter->attempt(EVALSHA_TEST_KEY, $policy, 1);

    expect($second->allowed)->toBeTrue()
        ->and($second->remaining)->toBe(3);
});

test('after rehydration the script is registered on the server again', function (): void {
    $policy = new RateLimitPolicy(
        name: 'evalsha-reload-test',
        capacity: 5,
        windowSeconds: 60,
        defaultCost: 1,
        keyStrategy: KeyStrategy::Ip,
        algorithm: AvailableAlgorithm::TokenBucket,
        refillRate: 0.1,
    );

    $limiter = app(RateLimitAlgorithmFactory::class)
        ->forAlgorithm(AvailableAlgorithm::TokenBucket);

    Redis::connection()->client()->script('flush');

    $limiter->attempt(EVALSHA_TEST_KEY, $policy, 1);

    // O SHA-1 local do arquivo versionado deve constar no cache do servidor
    // após a decisão — é o mesmo hash usado pelo EVALSHA de todo request.
    $localSha = sha1((string) file_get_contents(
        base_path('app/RateLimiting/Redis/scripts/token_bucket.lua')
    ));

    $existsReply = (array) Redis::connection()->client()->script('exists', $localSha);

    expect((bool) ($existsReply[0] ?? false))->toBeTrue();
});
