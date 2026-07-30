<?php

declare(strict_types=1);

/**
 * Testes de feature da quota composta por tenant (Fase 9).
 *
 * Cobrem: compatibilidade com as fases anteriores quando a flag está
 * desligada (padrão), o segundo check quando ligada, isolamento entre
 * tenants, tratamento de header ausente/inválido e o short-circuit que
 * impede um cliente abusivo de drenar a cota compartilhada.
 *
 * Exigem Redis real (banco 15, ver phpunit.xml); pulados com aviso claro
 * quando não há conexão.
 */

use Illuminate\Support\Facades\Redis;

const TENANT_CLIENT_KEY = 'rate-limit:ip:127.0.0.1:rate-limited.ping';
const TENANT_ACME_KEY = 'rate-limit:tenant:acme:rate-limited.ping';
const TENANT_GLOBEX_KEY = 'rate-limit:tenant:globex:rate-limited.ping';

/**
 * Recebe: nada. Faz: remove todas as chaves usadas por estes testes.
 * Retorna: void. Efeitos colaterais: DEL no Redis.
 */
function forgetTenantTestKeys(): void
{
    foreach ([TENANT_CLIENT_KEY, TENANT_ACME_KEY, TENANT_GLOBEX_KEY] as $key) {
        Redis::connection()->del($key);
    }
}

/**
 * Recebe: a capacidade desejada para o balde do cliente. Faz: reescreve o
 * array INTEIRO de políticas. Motivo de não usar notação de pontos: o nome
 * da rota contém "." ("rate-limited.ping") e config()->set com caminho
 * pontilhado criaria uma árvore aninhada nova em vez de alterar a política
 * — a mesma armadilha que o middleware evita ao ler policies por acesso
 * direto ao array. Retorna: void. Efeitos colaterais: altera a config.
 */
function setClientCapacity(int $capacity): void
{
    config()->set('rate_limiting.policies', [
        'rate-limited.ping' => [
            'capacity' => $capacity,
            'window_seconds' => 60,
            'default_cost' => 1,
            'key_strategy' => 'ip',
            'algorithm' => 'naive',
        ],
    ]);
}

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis unavailable — tenant quota tests require a real Redis (127.0.0.1:6379, database 15).'
        );
    }

    config()->set('rate_limiting.enabled', true);

    // Cliente generoso: nos testes de tenant quem deve barrar é o balde
    // compartilhado, não o individual.
    setClientCapacity(10);

    // Ponto de partida: quota de tenant DESLIGADA (padrão do projeto).
    config()->set('rate_limiting.tenant', [
        'enabled' => false,
        'header' => 'X-Tenant-Id',
        'capacity' => 3,
        'algorithm' => 'naive',
        'refill_rate' => 1.0,
        'leak_rate' => 1.0,
    ]);

    forgetTenantTestKeys();
});

afterEach(function (): void {
    try {
        forgetTenantTestKeys();
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('with the feature disabled the tenant header is completely ignored', function (): void {
    // Compatibilidade: config padrão + header presente deve se comportar
    // exatamente como nas fases 0-8 — nenhuma chave de tenant é criada.
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '10')
        ->assertHeader('X-RateLimit-Remaining', '9');

    expect((bool) Redis::connection()->client()->exists(TENANT_ACME_KEY))->toBeFalse()
        ->and((bool) Redis::connection()->client()->exists(TENANT_CLIENT_KEY))->toBeTrue();
});

test('with the feature enabled but no header only the client bucket is consumed', function (): void {
    config()->set('rate_limiting.tenant.enabled', true);

    $this->postJson('/api/rate-limited/ping')->assertOk();

    expect((bool) Redis::connection()->client()->exists(TENANT_ACME_KEY))->toBeFalse()
        ->and((bool) Redis::connection()->client()->exists(TENANT_CLIENT_KEY))->toBeTrue();
});

test('a malformed tenant identifier is treated as absent', function (): void {
    config()->set('rate_limiting.tenant.enabled', true);

    // ":" quebraria o padrão de chave; identificador é rejeitado e a
    // requisição segue apenas com o balde do cliente.
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme:evil'])
        ->assertOk();

    $tenantKeys = (array) Redis::connection()->client()->keys('rate-limit:tenant:*');

    expect($tenantKeys)->toBeEmpty();
});

test('enabled with a valid header consumes both buckets and denies on the tenant quota', function (): void {
    config()->set('rate_limiting.tenant.enabled', true);

    // Capacidade do tenant = 3; do cliente = 10. A 4a requisicao deve ser
    // barrada pelo TENANT, com scope explicito no corpo.
    for ($request = 1; $request <= 3; $request++) {
        $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])->assertOk();
    }

    $denied = $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme']);

    $denied
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
        ->assertJsonPath('scope', 'tenant')
        ->assertJsonPath('limit', 3)
        ->assertHeader('X-RateLimit-Limit', '3')
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect((bool) Redis::connection()->client()->exists(TENANT_ACME_KEY))->toBeTrue();
});

test('tenants are isolated from each other', function (): void {
    config()->set('rate_limiting.tenant.enabled', true);

    // Esgota o balde do tenant "acme".
    for ($request = 1; $request <= 3; $request++) {
        $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])->assertOk();
    }
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])->assertStatus(429);

    // "globex" tem balde próprio e continua passando (o balde do CLIENTE
    // ainda tem saldo: capacity 10, consumidas 4).
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'globex'])
        ->assertOk()
        ->assertJsonPath('message', 'pong');
});

test('a client denied by its own bucket never touches the tenant bucket', function (): void {
    config()->set('rate_limiting.tenant.enabled', true);
    // Cliente restritíssimo, tenant folgado: o short-circuit deve preservar
    // a cota compartilhada mesmo com o cliente martelando a API.
    setClientCapacity(1);
    config()->set('rate_limiting.tenant.capacity', 50);

    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])->assertOk();

    $denied = $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme']);
    $denied->assertStatus(429)->assertJsonPath('scope', 'client');

    // Uma única unidade consumida no balde do tenant: a da requisição que
    // passou. As negadas pelo cliente não chegaram ao segundo check.
    expect((int) Redis::connection()->get(TENANT_ACME_KEY))->toBe(1);
});

test('headers report the most restrictive bucket when the tenant quota is tighter', function (): void {
    config()->set('rate_limiting.tenant.enabled', true);
    setClientCapacity(10);
    config()->set('rate_limiting.tenant.capacity', 3);

    // Cliente: 10 - 1 = 9 restantes. Tenant: 3 - 1 = 2 restantes.
    // O balde vinculante (menor saldo) é o do tenant.
    $this->postJson('/api/rate-limited/ping', [], ['X-Tenant-Id' => 'acme'])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '3')
        ->assertHeader('X-RateLimit-Remaining', '2');
});
