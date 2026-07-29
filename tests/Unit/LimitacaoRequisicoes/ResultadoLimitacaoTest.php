<?php

declare(strict_types=1);

/**
 * Testes de unidade do ResultadoLimitacao — classes puras, sem framework.
 */

use App\LimitacaoRequisicoes\Suporte\AlgoritmoDisponivel;
use App\LimitacaoRequisicoes\Suporte\EstrategiaChave;
use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;
use App\LimitacaoRequisicoes\Suporte\ResultadoLimitacao;

function politicaDeTeste(): PoliticaLimitacao
{
    return new PoliticaLimitacao(
        nome: 'limitado.ping',
        capacidade: 50,
        janelaSegundos: 60,
        custoPadrao: 1,
        estrategiaChave: EstrategiaChave::Ip,
        algoritmo: AlgoritmoDisponivel::Ingenuo,
    );
}

test('resultado permitido carrega saldo, limite e chave', function (): void {
    $resultado = ResultadoLimitacao::permitido(politicaDeTeste(), 'limitacao:ip:10.0.0.1:limitado.ping', 49);

    expect($resultado->permitido)->toBeTrue()
        ->and($resultado->restante)->toBe(49)
        ->and($resultado->limite)->toBe(50)
        ->and($resultado->tentarNovamenteEm)->toBe(0)
        ->and($resultado->algoritmo)->toBe('ingenuo')
        ->and($resultado->chave)->toBe('limitacao:ip:10.0.0.1:limitado.ping');
});

test('saldo negativo é saneado para zero no resultado permitido', function (): void {
    // O limitador ingênuo pode ultrapassar a capacidade sob corrida
    // (contador > capacidade). O DTO nunca expõe saldo negativo ao cliente.
    $resultado = ResultadoLimitacao::permitido(politicaDeTeste(), 'chave', -3);

    expect($resultado->restante)->toBe(0);
});

test('resultado negado zera o saldo e nunca instrui retry imediato', function (): void {
    $resultado = ResultadoLimitacao::negado(politicaDeTeste(), 'chave', 0);

    expect($resultado->permitido)->toBeFalse()
        ->and($resultado->restante)->toBe(0)
        ->and($resultado->tentarNovamenteEm)->toBe(1);
});
