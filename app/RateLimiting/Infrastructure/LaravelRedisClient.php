<?php

declare(strict_types=1);

namespace App\RateLimiting\Infrastructure;

use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Contracts\RateLimitScriptRunner;
use App\RateLimiting\Exceptions\LuaScriptFailureException;
use App\RateLimiting\Exceptions\RateLimitException;
use App\RateLimiting\Exceptions\RedisUnavailableException;
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
    /**
     * Recebe: a fábrica de conexões Redis do framework. Faz: guarda a
     * dependência. Retorna: instância imutável. Efeitos colaterais: nenhum
     * (a conexão só é aberta no primeiro comando).
     */
    public function __construct(
        private RedisFactory $redisFactory,
    ) {
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
     * Recebe: código Lua, KEYS e ARGV. Faz: EVAL na conexão do framework
     * (execução atômica no servidor). Retorna: resposta bruta do Redis.
     * Efeitos colaterais: os do script; lança RedisUnavailableException em
     * falha de infraestrutura e LuaScriptFailureException quando o servidor
     * reporta erro de script (phpredis devolve false e registra o erro em
     * getLastError() em vez de lançar — a checagem explícita abaixo impede
     * que um bug de Lua vire um "negado" silencioso).
     *
     * @param  list<string>  $keys
     * @param  list<int|float|string>  $arguments
     */
    public function evalScript(string $script, array $keys, array $arguments): mixed
    {
        return $this->run(function () use ($script, $keys, $arguments): mixed {
            $connection = $this->connection();

            $reply = $connection->eval($script, count($keys), ...$keys, ...$arguments);

            if ($reply === false) {
                $serverError = $connection->client()->getLastError();

                if ($serverError !== null) {
                    $connection->client()->clearLastError();

                    throw LuaScriptFailureException::serverError($serverError);
                }
            }

            return $reply;
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
