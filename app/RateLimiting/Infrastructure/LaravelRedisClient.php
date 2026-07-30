<?php

declare(strict_types=1);

namespace App\RateLimiting\Infrastructure;

use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Contracts\RateLimitScriptRunner;
use App\RateLimiting\Exceptions\RateLimitException;
use App\RateLimiting\Exceptions\RedisUnavailableException;
use App\RateLimiting\Infrastructure\Concerns\ExecutesEvalSha;
use App\RateLimiting\Redis\LuaScript;
use App\RateLimiting\Support\RateLimitMetric;
use App\RateLimiting\Support\RateLimitMetrics;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Adaptador dos contratos RateLimitRedisClient (comandos individuais) e
 * RateLimitScriptRunner (EVAL atômico) sobre a conexão Redis do Laravel
 * (config/database.php, conexão "default").
 *
 * Responsabilidade: traduzir cada operação das portas em chamadas na
 * conexão gerenciada pelo framework, convertendo QUALQUER falha de
 * infraestrutura em RedisUnavailableException — o algoritmo nunca vê
 * RedisException crua. Implementar as DUAS portas na mesma classe é
 * intencional: é a mesma conexão física; a separação que importa está nos
 * CONTRATOS que cada algoritmo recebe (ver RateLimitScriptRunner).
 */
final readonly class LaravelRedisClient implements RateLimitRedisClient, RateLimitScriptRunner
{
    use ExecutesEvalSha;

    /**
     * Recebe: a fábrica de conexões Redis do framework e, opcionalmente, o
     * registrador de métricas (Fase 6 — nulo em contextos sem
     * observabilidade). Faz: guarda as dependências. Retorna: instância
     * imutável. Efeitos colaterais: nenhum (a conexão só é aberta no
     * primeiro comando).
     */
    public function __construct(
        private RedisFactory $redisFactory,
        private ?RateLimitMetrics $metrics = null,
    ) {
    }

    /**
     * Recebe: nada (hook do ExecutesEvalSha). Faz: conta a reidratação
     * NOSCRIPT na métrica evalsha_reload_total quando há registrador
     * disponível — increment() é best-effort por contrato, então este hook
     * jamais derruba a decisão em andamento. Retorna: void. Efeitos
     * colaterais: HINCRBY no Redis ou linha de log métrico.
     */
    protected function reportEvalShaReload(): void
    {
        $this->metrics?->increment(RateLimitMetric::EvalshaReloadTotal);
    }

    public function get(string $key): ?string
    {
        $value = $this->run(fn () => $this->connection()->get($key));

        // phpredis devolve false para chave inexistente; normaliza para null
        // para que o algoritmo trabalhe com um único "não existe".
        return ($value === null || $value === false) ? null : (string) $value;
    }

    public function setWithTtl(string $key, int $value, int $ttlSeconds): void
    {
        $this->run(fn () => $this->connection()->set($key, (string) $value, 'EX', $ttlSeconds));
    }

    public function increment(string $key, int $amount): int
    {
        return (int) $this->run(fn () => $this->connection()->incrby($key, $amount));
    }

    public function ttl(string $key): int
    {
        return (int) $this->run(fn () => $this->connection()->ttl($key));
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $this->run(fn () => $this->connection()->expire($key, $ttlSeconds));
    }

    public function delete(string $key): void
    {
        $this->run(fn () => $this->connection()->del($key));
    }

    /**
     * Recebe: o script (fonte + SHA-1 pré-computado), KEYS e ARGV. Faz:
     * EVALSHA na conexão do framework, com reidratação automática via
     * SCRIPT LOAD quando o servidor responde NOSCRIPT (rotina compartilhada
     * ExecutesEvalSha — Fase 4). Retorna: resposta bruta do Redis. Efeitos
     * colaterais: os do script; lança RedisUnavailableException em falha de
     * infraestrutura e LuaScriptFailureException para erro de script, SHA
     * divergente ou NOSCRIPT persistente.
     *
     * @param  list<string>  $keys
     * @param  list<int|float|string>  $arguments
     */
    public function evalScript(LuaScript $script, array $keys, array $arguments): mixed
    {
        return $this->run(function () use ($script, $keys, $arguments): mixed {
            // client() expõe o phpredis cru: EVALSHA/SCRIPT LOAD com
            // tratamento de getLastError() idêntico nos dois adaptadores.
            return $this->runEvalShaOnClient(
                client: $this->connection()->client(),
                script: $script,
                keys: $keys,
                arguments: $arguments,
            );
        });
    }

    /**
     * Recebe: nada. Faz: obtém a conexão "default" da fábrica. Retorna: a
     * conexão do framework. Efeitos colaterais: pode abrir socket na
     * primeira chamada.
     */
    private function connection(): mixed
    {
        return $this->redisFactory->connection();
    }

    /**
     * Recebe: um comando encapsulado em closure. Faz: executa e converte
     * falha de INFRAESTRUTURA em RedisUnavailableException; exceções de
     * domínio já tipadas (ex.: LuaScriptFailureException vinda do
     * evalScript) atravessam intactas — re-embrulhá-las esconderia um bug
     * de script atrás de um falso "Redis fora". Retorna: o resultado bruto
     * do comando. Efeitos colaterais: os do comando.
     */
    private function run(callable $command): mixed
    {
        try {
            return $command();
        } catch (RateLimitException $domainFailure) {
            throw $domainFailure;
        } catch (Throwable $failure) {
            throw RedisUnavailableException::from($failure);
        }
    }
}
