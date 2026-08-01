<?php

declare(strict_types=1);

/**
 * Testes de feature dos planos de cota por tenant (Fase 11).
 *
 * Cobrem: planos diferentes produzindo capacidades diferentes, queda para o
 * plano padrão, falha explícita em plano inexistente, estabilidade da chave
 * quando o plano muda e não regressão com a flag desligada.
 *
 * Exigem Redis real (banco 15, ver phpunit.xml); pulados com aviso claro
 * quando não há conexão.
 */

use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Resolvers\TenantQuotaResolver;
use Illuminate\Support\Facades\Redis;

const PLAN_CLIENT_KEY = 'rate-limit:ip:127.0.0.1:rate-limited.ping';
const PLAN_ACME_KEY = 'rate-limit:tenant:acme:rate-limited.ping';
const PLAN_GLOBEX_KEY = 'rate-limit:tenant:globex:rate-limited.ping';

/**
 * Recebe: nada. Faz: remove as chaves usadas por estes testes. Retorna:
 * void. Efeitos colaterais: DEL no Redis.
 */
function forgetPlanTestKeys(): void
{
    foreach ([PLAN_CLIENT_KEY, PLAN_ACME_KEY, PLAN_GLOBEX_KEY] as $key) {
        Redis::connection()->del($key);
    }
}

/**
 * Recebe: sobreposições da seção de tenant. Faz: escreve a configuração de
 * tenant inteira (nunca notação de pontos com nome de rota, que contém ".").
 * Retorna: void. Efeitos colaterais: altera a config em memória.
 *
 * @param  array<string, mixed>  $overrides
 */
function setTenantConfig(array $overrides = []): void
{
    config()->set('rate_limiting.tenant', array_merge([
        'enabled' => true,
        'header' => 'X-Tenant-Id',
        'capacity' => 200,
        'algorithm' => 'naive',
        'refill_rate' => 4.0,
        'leak_rate' => 4.0,
        'default_plan' => 'free',
        'plans' => [
            // naive nos testes: capacidade fixa por janela, sem recarga
            // durante a asserção — a contagem fica determinística.
            'free' => ['capacity' => 2, 'algorithm' => 'naive'],
            'pro' => ['capacity' => 5, 'algorithm' => 'naive'],
        ],
        'assignments' => ['acme' => 'pro'],
    ], $overrides));
}

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — tenant plan tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    config()->set('rate_limiting.enabled', true);
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            // Cliente folgado: quem barra nestes testes é sempre o plano.
            'capacity' => 100,
            'window_seconds' => 60,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'naive',
        ],
    ]);

    setTenantConfig();
    forgetPlanTestKeys();
});

afterEach(function (): void {
    try {
        forgetPlanTestKeys();
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('an assigned tenant gets the capacity of its plan', function (): void {
    // "acme" está atribuído ao plano pro (capacidade 5).
    for ($request = 1; $request <= 5; $request++) {
        $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])->assertOk();
    }

    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])
        ->assertStatus(429)
        ->assertJsonPath('scope', 'tenant')
        ->assertJsonPath('limit', 5);
});

test('an unassigned tenant falls back to the default plan', function (): void {
    // "globex" não tem atribuição: cai em free (capacidade 2).
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'globex'])->assertOk();
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'globex'])->assertOk();

    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'globex'])
        ->assertStatus(429)
        ->assertJsonPath('scope', 'tenant')
        ->assertJsonPath('limit', 2);
});

test('two tenants on different plans get different limits at the same time', function (): void {
    // Prova direta do recurso: mesma rota, mesma janela, limites distintos.
    $freeResponse = $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'globex']);
    $proResponse = $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme']);

    $freeResponse->assertOk()->assertHeader('X-RateLimit-Limit', '2');
    $proResponse->assertOk()->assertHeader('X-RateLimit-Limit', '5');
});

test('the plan never comes from the client — only the server mapping decides', function (): void {
    // Mesmo enviando um header de plano forjado, "globex" continua em free.
    $this->postJson('/api/rate-limited/ping', [], [
        'X-Tenant-Id' => 'globex',
        'X-Tenant-Plan' => 'pro',
    ])->assertOk()->assertHeader('X-RateLimit-Limit', '2');
});

test('the tenant key does not include the plan, so a plan change keeps the bucket', function (): void {
    $resolver = app(TenantQuotaResolver::class);

    $onFree = $resolver->resolve(
        Illuminate\Http\Request::create('/', 'POST', server: ['HTTP_X_TENANT_ID' => 'globex']),
        'rate-limited.ping',
    );

    setTenantConfig(['assignments' => ['globex' => 'pro']]);

    $onPro = app(TenantQuotaResolver::class)->resolve(
        Illuminate\Http\Request::create('/', 'POST', server: ['HTTP_X_TENANT_ID' => 'globex']),
        'rate-limited.ping',
    );

    expect($onFree?->planName)->toBe('free')
        ->and($onPro?->planName)->toBe('pro')
        ->and($onFree?->policy->capacity)->toBe(2)
        ->and($onPro?->policy->capacity)->toBe(5)
        // Mesma chave: trocar de plano ajusta a cota sem dar um balde novo.
        ->and($onPro?->key)->toBe($onFree?->key);
});

test('an unknown plan name fails loudly instead of silently granting the base quota', function (): void {
    setTenantConfig(['assignments' => ['acme' => 'enterprise']]);

    app(TenantQuotaResolver::class)->resolve(
        Illuminate\Http\Request::create('/', 'POST', server: ['HTTP_X_TENANT_ID' => 'acme']),
        'rate-limited.ping',
    );
})->throws(InvalidRateLimitPolicyException::class, 'enterprise');

test('with plans declared but the feature disabled nothing changes', function (): void {
    setTenantConfig(['enabled' => false]);

    // Comportamento das fases anteriores: só o balde do cliente (capacidade 100).
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '100')
        ->assertHeader('X-RateLimit-Remaining', '99');

    expect((bool) Redis::connection()->client()->exists(PLAN_ACME_KEY))->toBeFalse();
});

test('without declared plans the phase 9 behaviour is preserved', function (): void {
    // Retrocompatibilidade: config de tenant sem 'plans' usa a cota-base.
    setTenantConfig(['plans' => [], 'assignments' => [], 'capacity' => 3]);

    for ($request = 1; $request <= 3; $request++) {
        $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])->assertOk();
    }

    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])
        ->assertStatus(429)
        ->assertJsonPath('limit', 3);
});
