<?php

declare(strict_types=1);

namespace App\Providers;

use App\RateLimiting\Algorithms\NaiveRedisRateLimiter;
use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Contracts\RateLimitKeyResolver;
use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Infrastructure\LaravelRedisClient;
use App\RateLimiting\Resolvers\DefaultKeyResolver;
use App\RateLimiting\Support\AvailableAlgorithm;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do domínio de limitação de requisições.
 *
 * Responsabilidade: único ponto de wiring do limitador — amarra os
 * contratos (RateLimitAlgorithm, RateLimitKeyResolver,
 * RateLimitRedisClient) às implementações das Fases 0 e 1. O middleware e
 * os algoritmos nunca instanciam dependências concretas por conta própria.
 */
class RateLimitingServiceProvider extends ServiceProvider
{
    /**
     * Recebe: nada. Faz: registra os bindings singleton do domínio no
     * container. Retorna: void. Efeitos colaterais: registros no container;
     * config de algoritmo desconhecido falha explicitamente na resolução.
     */
    public function register(): void
    {
        $this->app->singleton(RateLimitRedisClient::class, function (Application $app): RateLimitRedisClient {
            return new LaravelRedisClient(
                redisFactory: $app->make(RedisFactory::class),
            );
        });

        $this->app->singleton(RateLimitKeyResolver::class, function (Application $app): RateLimitKeyResolver {
            return new DefaultKeyResolver(
                keyPrefix: (string) $app->make('config')->get('rate_limiting.key_prefix', 'rate-limit'),
            );
        });

        $this->app->singleton(RateLimitAlgorithm::class, function (Application $app): RateLimitAlgorithm {
            $rawAlgorithm = (string) $app->make('config')->get('rate_limiting.algorithm', '');

            $algorithm = AvailableAlgorithm::tryFrom($rawAlgorithm)
                ?? throw InvalidRateLimitPolicyException::forReason(
                    "unknown global algorithm '{$rawAlgorithm}' — only 'naive' exists in phases 0 and 1"
                );

            // match exaustivo de propósito: quando o Token Bucket (Fase 2)
            // entrar no enum, o compilador de casos obriga a registrar a nova
            // implementação aqui — impossível esquecer o wiring.
            return match ($algorithm) {
                AvailableAlgorithm::Naive => new NaiveRedisRateLimiter(
                    redisClient: $app->make(RateLimitRedisClient::class),
                ),
            };
        });
    }

    /**
     * Recebe: nada. Faz: nada — a config vive em config/rate_limiting.php
     * e o alias do middleware é declarado em bootstrap/app.php. Retorna:
     * void. Efeitos colaterais: nenhum.
     */
    public function boot(): void
    {
        //
    }
}
