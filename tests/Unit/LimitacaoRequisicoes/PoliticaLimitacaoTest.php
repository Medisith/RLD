<?php

declare(strict_types=1);

/**
 * Testes de unidade da PoliticaLimitacao — classes puras, sem framework.
 */

use App\LimitacaoRequisicoes\Excecoes\ExcecaoPoliticaInvalida;
use App\LimitacaoRequisicoes\Suporte\AlgoritmoDisponivel;
use App\LimitacaoRequisicoes\Suporte\EstrategiaChave;
use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;

const CONFIGURACAO_GLOBAL_TESTE = [
    'capacidade' => 50,
    'janela_segundos' => 60,
    'custo_padrao' => 1,
    'estrategia_chave' => 'usuario_ou_ip',
    'algoritmo' => 'ingenuo',
];

test('config da rota sobrepõe a global e o restante é herdado', function (): void {
    $politica = PoliticaLimitacao::daConfiguracao(
        nome: 'limitado.ping',
        configuracaoRota: ['capacidade' => 10],
        configuracaoGlobal: CONFIGURACAO_GLOBAL_TESTE,
    );

    expect($politica->capacidade)->toBe(10)
        ->and($politica->janelaSegundos)->toBe(60)
        ->and($politica->custoPadrao)->toBe(1)
        ->and($politica->estrategiaChave)->toBe(EstrategiaChave::UsuarioOuIp)
        ->and($politica->algoritmo)->toBe(AlgoritmoDisponivel::Ingenuo);
});

test('capacidade menor que 1 falha explicitamente na construção', function (): void {
    PoliticaLimitacao::daConfiguracao(
        nome: 'limitado.ping',
        configuracaoRota: ['capacidade' => 0],
        configuracaoGlobal: CONFIGURACAO_GLOBAL_TESTE,
    );
})->throws(ExcecaoPoliticaInvalida::class, 'capacidade');

test('custo padrão maior que a capacidade é rejeitado', function (): void {
    PoliticaLimitacao::daConfiguracao(
        nome: 'limitado.ping',
        configuracaoRota: ['capacidade' => 2, 'custo_padrao' => 3],
        configuracaoGlobal: CONFIGURACAO_GLOBAL_TESTE,
    );
})->throws(ExcecaoPoliticaInvalida::class, 'custo_padrao');

test('algoritmo desconhecido é rejeitado — só o ingênuo existe nas Fases 0 e 1', function (): void {
    PoliticaLimitacao::daConfiguracao(
        nome: 'limitado.ping',
        configuracaoRota: ['algoritmo' => 'token_bucket'],
        configuracaoGlobal: CONFIGURACAO_GLOBAL_TESTE,
    );
})->throws(ExcecaoPoliticaInvalida::class, 'token_bucket');

test('estratégia de chave desconhecida é rejeitada', function (): void {
    PoliticaLimitacao::daConfiguracao(
        nome: 'limitado.ping',
        configuracaoRota: ['estrategia_chave' => 'api_key'],
        configuracaoGlobal: CONFIGURACAO_GLOBAL_TESTE,
    );
})->throws(ExcecaoPoliticaInvalida::class, 'api_key');
