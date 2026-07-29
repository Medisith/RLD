<?php

declare(strict_types=1);

/**
 * Testes de feature do MiddlewareLimitacaoAvancada (Fase 1).
 *
 * IMPORTANTE — limite honesto destes testes: tudo aqui é SEQUENCIAL (uma
 * requisição por vez). Teste sequencial NÃO prova nem refuta a race
 * condition do LimitadorIngenuoRedis — com um único processo, a janela
 * entre GET e SET/INCRBY nunca é disputada. A prova de concorrência é
 * exclusivamente scripts/provar_race_condition.php (ver
 * docs/fases/fase-1-race-condition.md).
 *
 * Estes testes exigem um Redis real (banco 15, ver phpunit.xml) e são
 * pulados com aviso claro quando não há conexão — sem números inventados,
 * sem falso verde.
 */

use Illuminate\Support\Facades\Redis;

// Capacidade pequena de propósito: o teste de estouro fica rápido sem
// alterar a semântica (a política de produto 50/60s segue na config real).
const CAPACIDADE_TESTE = 5;
const CHAVE_TESTE = 'limitacao:ip:127.0.0.1:limitado.ping';

beforeEach(function (): void {
    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped(
            'Redis indisponível — testes de feature do limitador exigem Redis real (127.0.0.1:6379, banco 15).'
        );
    }

    config()->set('limitacao_requisicoes.habilitado', true);
    config()->set('limitacao_requisicoes.politicas', [
        'limitado.ping' => [
            'capacidade' => CAPACIDADE_TESTE,
            'janela_segundos' => 60,
            'custo_padrao' => 1,
            'estrategia_chave' => 'usuario_ou_ip',
            'algoritmo' => 'ingenuo',
        ],
    ]);

    // Saldo zerado antes de cada teste: cada cenário começa em janela limpa.
    Redis::connection()->del(CHAVE_TESTE);
});

afterEach(function (): void {
    try {
        Redis::connection()->del(CHAVE_TESTE);
    } catch (Throwable) {
        // Redis caiu no meio do teste: nada a limpar.
    }
});

test('requisição abaixo do limite responde 200 com headers de saldo', function (): void {
    $resposta = $this->postJson('/api/limitado/ping');

    $resposta
        ->assertOk()
        ->assertJsonPath('mensagem', 'pong')
        ->assertHeader('X-RateLimit-Limit', (string) CAPACIDADE_TESTE)
        ->assertHeader('X-RateLimit-Remaining', (string) (CAPACIDADE_TESTE - 1));
});

test('estourada a capacidade, responde 429 com corpo e headers do contrato', function (): void {
    // Consome toda a capacidade SEQUENCIALMENTE (ver aviso no topo: isto não
    // exercita concorrência — apenas o contrato HTTP de negação).
    for ($requisicao = 1; $requisicao <= CAPACIDADE_TESTE; $requisicao++) {
        $this->postJson('/api/limitado/ping')->assertOk();
    }

    $respostaNegada = $this->postJson('/api/limitado/ping');

    $respostaNegada
        ->assertStatus(429)
        ->assertJsonPath('codigo', 'LIMITE_REQUISICOES_EXCEDIDO')
        ->assertJsonPath('limite', CAPACIDADE_TESTE)
        ->assertHeader('X-RateLimit-Limit', (string) CAPACIDADE_TESTE)
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect((int) $respostaNegada->headers->get('Retry-After'))
        ->toBeGreaterThanOrEqual(1)
        ->toBeLessThanOrEqual(60);

    expect($respostaNegada->json('mensagem'))
        ->toContain('Limite de requisições excedido');
});

test('limitador desabilitado deixa a requisição passar sem headers de saldo', function (): void {
    config()->set('limitacao_requisicoes.habilitado', false);

    $resposta = $this->postJson('/api/limitado/ping');

    $resposta->assertOk()->assertJsonPath('mensagem', 'pong');

    expect($resposta->headers->has('X-RateLimit-Limit'))->toBeFalse();
});
