<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Provider padrão da aplicação.
 *
 * Responsabilidade: intencionalmente vazio nas Fases 0 e 1 — todo o wiring
 * do limitador vive em LimitacaoRequisicoesServiceProvider para manter o
 * domínio isolado e fácil de auditar.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Recebe: nada. Faz: nada nesta fase. Retorna: void. Efeitos: nenhum.
     */
    public function register(): void
    {
        //
    }

    /**
     * Recebe: nada. Faz: nada nesta fase. Retorna: void. Efeitos: nenhum.
     */
    public function boot(): void
    {
        //
    }
}
