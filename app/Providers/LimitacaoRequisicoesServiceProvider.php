<?php

declare(strict_types=1);

namespace App\Providers;

use App\LimitacaoRequisicoes\Algoritmos\LimitadorIngenuoRedis;
use App\LimitacaoRequisicoes\Contratos\AlgoritmoLimitacao;
use App\LimitacaoRequisicoes\Contratos\ClienteRedisLimitacao;
use App\LimitacaoRequisicoes\Contratos\ResolvedorChaveLimitacao;
use App\LimitacaoRequisicoes\Excecoes\ExcecaoPoliticaInvalida;
use App\LimitacaoRequisicoes\Infraestrutura\ClienteRedisLaravel;
use App\LimitacaoRequisicoes\Resolvedores\ResolvedorChavePadrao;
use App\LimitacaoRequisicoes\Suporte\AlgoritmoDisponivel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as FabricaConexoesRedis;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do domínio de limitação de requisições.
 *
 * Responsabilidade: único ponto de wiring do limitador — amarra os
 * contratos (AlgoritmoLimitacao, ResolvedorChaveLimitacao,
 * ClienteRedisLimitacao) às implementações das Fases 0 e 1. O middleware e
 * os algoritmos nunca instanciam dependências concretas por conta própria.
 */
class LimitacaoRequisicoesServiceProvider extends ServiceProvider
{
    /**
     * Recebe: nada. Faz: registra os bindings singleton do domínio no
     * container. Retorna: void. Efeitos colaterais: registros no container;
     * config de algoritmo desconhecido falha explicitamente na resolução.
     */
    public function register(): void
    {
        $this->app->singleton(ClienteRedisLimitacao::class, function (Application $app): ClienteRedisLimitacao {
            return new ClienteRedisLaravel(
                fabricaConexoes: $app->make(FabricaConexoesRedis::class),
            );
        });

        $this->app->singleton(ResolvedorChaveLimitacao::class, function (Application $app): ResolvedorChaveLimitacao {
            return new ResolvedorChavePadrao(
                prefixoChave: (string) $app->make('config')->get('limitacao_requisicoes.prefixo_chave', 'limitacao'),
            );
        });

        $this->app->singleton(AlgoritmoLimitacao::class, function (Application $app): AlgoritmoLimitacao {
            $algoritmoBruto = (string) $app->make('config')->get('limitacao_requisicoes.algoritmo', '');

            $algoritmo = AlgoritmoDisponivel::tryFrom($algoritmoBruto)
                ?? throw ExcecaoPoliticaInvalida::porMotivo(
                    "algoritmo global desconhecido '{$algoritmoBruto}' — apenas 'ingenuo' existe nas Fases 0 e 1"
                );

            // match exaustivo de propósito: quando o Token Bucket (Fase 2)
            // entrar no enum, o compilador de casos obriga a registrar a nova
            // implementação aqui — impossível esquecer o wiring.
            return match ($algoritmo) {
                AlgoritmoDisponivel::Ingenuo => new LimitadorIngenuoRedis(
                    clienteRedis: $app->make(ClienteRedisLimitacao::class),
                ),
            };
        });
    }

    /**
     * Recebe: nada. Faz: nada — a config vive em config/limitacao_requisicoes.php
     * e o alias do middleware é declarado em bootstrap/app.php. Retorna:
     * void. Efeitos colaterais: nenhum.
     */
    public function boot(): void
    {
        //
    }
}
