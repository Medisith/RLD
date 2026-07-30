<?php

declare(strict_types=1);

namespace App\Providers;

use App\RateLimiting\Algorithms\RateLimitAlgorithmFactory;
use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Contracts\RateLimitKeyResolver;
use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Contracts\RateLimitScriptRunner;
use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Infrastructure\LaravelRedisClient;
use App\RateLimiting\Resolvers\DefaultKeyResolver;
use App\RateLimiting\Resolvers\TenantQuotaResolver;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyAnonymizer;
use App\RateLimiting\Support\RateLimitMetrics;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Provider do domínio de limitação de requisições.
 *
 * Responsabilidade: único ponto de wiring do limitador — amarra os
 * contratos (RateLimitAlgorithm, RateLimitKeyResolver, RateLimitRedisClient,
 * RateLimitScriptRunner) às implementações das Fases 0 a 3. O middleware e
 * os algoritmos nunca instanciam dependências concretas por conta própria.
 *
 * A partir da Fase 2 o mapa algoritmo->implementação vive no
 * RateLimitAlgorithmFactory (match exaustivo lá); este provider só liga as
 * portas de infraestrutura e delega.
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
        // Observabilidade (Fase 6): métricas best-effort e pseudonimização
        // de chave para logs. Registradas antes do adaptador Redis, que
        // conta reidratações EVALSHA através delas.
        $this->app->singleton(RateLimitMetrics::class, function (Application $app): RateLimitMetrics {
            return new RateLimitMetrics(
                redisFactory: $app->make(RedisFactory::class),
            );
        });

        $this->app->singleton(KeyAnonymizer::class, function (Application $app): KeyAnonymizer {
            // APP_KEY como segredo do HMAC: pseudônimo estável por app, não
            // reversível sem o segredo. Fallback fixo apenas para contextos
            // sem chave (ex.: comandos muito precoces) — documentado.
            $secret = (string) $app->make('config')->get('app.key', '');

            return new KeyAnonymizer(
                secret: $secret !== '' ? $secret : 'rate-limiter-portfolio-fallback-secret',
            );
        });

        // Adaptador concreto único: a MESMA conexão física serve as duas
        // portas (comandos individuais e EVAL). A separação que protege o
        // desenho está nos contratos que cada algoritmo recebe.
        $this->app->singleton(LaravelRedisClient::class, function (Application $app): LaravelRedisClient {
            return new LaravelRedisClient(
                redisFactory: $app->make(RedisFactory::class),
                metrics: $app->make(RateLimitMetrics::class),
            );
        });

        $this->app->singleton(
            RateLimitRedisClient::class,
            fn (Application $app): RateLimitRedisClient => $app->make(LaravelRedisClient::class),
        );

        $this->app->singleton(
            RateLimitScriptRunner::class,
            fn (Application $app): RateLimitScriptRunner => $app->make(LaravelRedisClient::class),
        );

        $this->app->singleton(RateLimitKeyResolver::class, function (Application $app): RateLimitKeyResolver {
            return new DefaultKeyResolver(
                keyPrefix: (string) $app->make('config')->get('rate_limiting.key_prefix', 'rate-limit'),
            );
        });

        // Quota composta por tenant (Fase 9). NÃO é singleton: a config
        // inteira é lida na construção, e os testes trocam
        // rate_limiting.tenant.* em tempo de execução — memoizar aqui
        // congelaria a flag e daria falso verde.
        $this->app->bind(TenantQuotaResolver::class, function (Application $app): TenantQuotaResolver {
            $config = $app->make('config');

            return new TenantQuotaResolver(
                globalConfig: (array) $config->get('rate_limiting', []),
                keyPrefix: (string) $config->get('rate_limiting.key_prefix', 'rate-limit'),
            );
        });

        $this->app->singleton(RateLimitAlgorithmFactory::class, function (Application $app): RateLimitAlgorithmFactory {
            return new RateLimitAlgorithmFactory(
                redisClient: $app->make(RateLimitRedisClient::class),
                scriptRunner: $app->make(RateLimitScriptRunner::class),
            );
        });

        // Binding do contrato "algoritmo global padrão": honra
        // rate_limiting.algorithm para quem injeta RateLimitAlgorithm
        // diretamente. O middleware NÃO usa este binding — ele resolve por
        // política (por rota) através da fábrica.
        $this->app->singleton(RateLimitAlgorithm::class, function (Application $app): RateLimitAlgorithm {
            $rawAlgorithm = (string) $app->make('config')->get('rate_limiting.algorithm', '');

            $algorithm = AvailableAlgorithm::tryFrom($rawAlgorithm)
                ?? throw InvalidRateLimitPolicyException::forReason(
                    "unknown global algorithm '{$rawAlgorithm}' — valid values: naive, token_bucket, leaky_bucket"
                );

            return $app->make(RateLimitAlgorithmFactory::class)->forAlgorithm($algorithm);
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
