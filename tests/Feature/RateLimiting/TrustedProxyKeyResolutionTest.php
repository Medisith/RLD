<?php

declare(strict_types=1);

/**
 * Testes de feature da identidade por IP atrás de proxy (Fase 6).
 *
 * O que está em jogo: a chave de limitação usa request->ip(). Sem proxies
 * confiáveis, X-Forwarded-For DEVE ser ignorado (senão qualquer cliente
 * troca de balde trocando um header). Com o proxy confiável configurado
 * (produção: TRUSTED_PROXIES no bootstrap), o IP real do cliente vem do
 * header e cada cliente ganha seu próprio balde.
 *
 * Os testes manipulam Illuminate\Http\Middleware\TrustProxies::at() — o
 * MESMO mecanismo que o bootstrap usa via $middleware->trustProxies(at:) —
 * e restauram o estado em afterEach para não vazar entre testes.
 *
 * Exigem Redis real (banco 15); pulados com aviso quando não há conexão.
 */

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Redis;

const PROXY_PEER_KEY = 'rate-limit:ip:127.0.0.1:rate-limited.ping';
const PROXY_FORWARDED_KEY = 'rate-limit:ip:203.0.113.7:rate-limited.ping';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — trusted proxy tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    config()->set('rate_limiting.enabled', true);
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            'capacity' => 5,
            'window_seconds' => 60,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'naive',
        ],
    ]);

    Redis::connection()->del(PROXY_PEER_KEY);
    Redis::connection()->del(PROXY_FORWARDED_KEY);
});

afterEach(function (): void {
    // Restaura "nenhum proxy confiável" para os demais testes do processo.
    TrustProxies::at([]);

    try {
        Redis::connection()->del(PROXY_PEER_KEY);
        Redis::connection()->del(PROXY_FORWARDED_KEY);
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('without trusted proxies a spoofed X-Forwarded-For is ignored', function (): void {
    $this->postJson('/api/rate-limited/ping', [], ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk();

    // A contagem foi para o peer TCP (127.0.0.1), NÃO para o IP forjado:
    // cliente direto não escolhe o próprio balde.
    expect((bool) Redis::connection()->client()->exists(PROXY_PEER_KEY))->toBeTrue()
        ->and((bool) Redis::connection()->client()->exists(PROXY_FORWARDED_KEY))->toBeFalse();
});

test('with the proxy trusted the forwarded client ip becomes the bucket identity', function (): void {
    // Mesmo mecanismo do bootstrap ($middleware->trustProxies(at: ...)):
    // o peer 127.0.0.1 é o proxy confiável; o cliente real vem do header.
    TrustProxies::at(['127.0.0.1']);

    $this->postJson('/api/rate-limited/ping', [], ['X-Forwarded-For' => '203.0.113.7'])
        ->assertOk();

    expect((bool) Redis::connection()->client()->exists(PROXY_FORWARDED_KEY))->toBeTrue()
        ->and((bool) Redis::connection()->client()->exists(PROXY_PEER_KEY))->toBeFalse();
});
