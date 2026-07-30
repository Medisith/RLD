<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Redis;

/**
 * Comando de operação: simula a resolução de política de uma rota SEM
 * consumir saldo.
 *
 * Responsabilidade: responder, para demo/ops, "se uma requisição chegasse
 * nesta rota agora, qual política valeria e qual chave seria contada?" —
 * exatamente a mesma mescla rota-sobre-global e a mesma validação
 * (RateLimitPolicy::fromConfig) que o middleware executa, além do estado
 * atual da chave hipotética. NENHUM consumo acontece: dry-run é leitura.
 *
 * Config quebrada aparece aqui do mesmo jeito que apareceria em produção
 * (InvalidRateLimitPolicyException), o que torna o comando um verificador
 * barato de configuração por rota.
 */
class RateLimitDryRunCommand extends Command
{
    protected $signature = 'rate-limit:dry-run
        {route : Nome da rota (ex.: rate-limited.ping)}
        {--identifier=203.0.113.10 : Identificador hipotético do cliente (ip ou id de usuário)}';

    protected $description = 'Resolves the effective policy and hypothetical key for a route without consuming budget';

    /**
     * Recebe: nome da rota, identificador opcional e a fábrica Redis. Faz:
     * monta a política efetiva (rota sobre global), monta a chave
     * hipotética e mostra o estado atual dela. Retorna: SUCCESS; FAILURE
     * quando a config da rota é inválida (a mensagem da exceção de domínio
     * é impressa na íntegra). Efeitos colaterais: nenhum no Redis.
     */
    public function handle(RedisFactory $redis): int
    {
        $routeName = (string) $this->argument('route');
        $identifier = (string) $this->option('identifier');

        $globalConfig = (array) config('rate_limiting', []);
        /** @var array<string, mixed> $routeConfig */
        $routeConfig = (array) (($globalConfig['policies'] ?? [])[$routeName] ?? []);

        if ($routeConfig === []) {
            $this->warn("Route '{$routeName}' has no dedicated policy — global defaults apply (protect-by-default).");
        }

        try {
            $policy = RateLimitPolicy::fromConfig(
                name: $routeName,
                routeConfig: $routeConfig,
                globalConfig: $globalConfig,
            );
        } catch (InvalidRateLimitPolicyException $failure) {
            $this->error('Invalid policy configuration: '.$failure->getMessage());

            return self::FAILURE;
        }

        $this->line('Effective policy (route config merged over global config):');
        $this->table(['Field', 'Value'], [
            ['route / policy name', $policy->name],
            ['algorithm', $policy->algorithm->value],
            ['capacity', (string) $policy->capacity],
            ['window_seconds (naive only)', (string) $policy->windowSeconds],
            ['refill_rate (token_bucket only)', $policy->refillRate === null ? '—' : (string) $policy->refillRate],
            ['leak_rate (leaky_bucket only)', $policy->leakRate === null ? '—' : (string) $policy->leakRate],
            ['default_cost', (string) $policy->defaultCost],
            ['key_strategy', $policy->keyStrategy->value],
            ['failure_mode (global)', (string) ($globalConfig['failure_mode'] ?? '—')],
        ]);

        // Estratégia EFETIVA para a chave hipotética: user_or_ip sem
        // autenticação cai para ip — mesmo comportamento do DefaultKeyResolver.
        $effectiveStrategy = $policy->keyStrategy === KeyStrategy::UserOrIp
            ? KeyStrategy::Ip
            : $policy->keyStrategy;

        $hypotheticalKey = sprintf(
            '%s:%s:%s:%s',
            (string) ($globalConfig['key_prefix'] ?? 'rate-limit'),
            $effectiveStrategy->value,
            $identifier,
            $policy->name,
        );

        $this->newLine();
        $this->line("Hypothetical key for identifier '{$identifier}': {$hypotheticalKey}");

        if ($policy->keyStrategy === KeyStrategy::UserOrIp) {
            $this->line('(strategy user_or_ip shown as "ip": unauthenticated request assumed in dry-run)');
        }

        /** @var Redis $client */
        $client = $redis->connection()->client();

        if ((bool) $client->exists($hypotheticalKey)) {
            $this->line(sprintf(
                'Current state: key EXISTS (ttl %ss) — run "rate-limit:inspect %s" for details.',
                (int) $client->ttl($hypotheticalKey),
                $hypotheticalKey,
            ));
        } else {
            $this->line('Current state: key absent — client at rest ('.$this->restStateDescription($policy->algorithm).').');
        }

        $this->newLine();
        $this->info('Dry-run only: no budget was consumed.');

        return self::SUCCESS;
    }

    /**
     * Recebe: o algoritmo da política. Faz: descreve o significado de
     * "chave ausente" para ele. Retorna: descrição curta. Efeitos: nenhum.
     */
    private function restStateDescription(AvailableAlgorithm $algorithm): string
    {
        return match ($algorithm) {
            AvailableAlgorithm::Naive => 'fresh window, full capacity',
            AvailableAlgorithm::TokenBucket => 'full bucket, whole burst available',
            AvailableAlgorithm::LeakyBucket => 'empty bucket, nothing queued',
        };
    }
}
