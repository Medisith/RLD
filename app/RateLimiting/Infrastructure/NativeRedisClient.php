<?php

declare(strict_types=1);

namespace App\RateLimiting\Infrastructure;

use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Contracts\RateLimitScriptRunner;
use App\RateLimiting\Exceptions\LuaScriptFailureException;
use App\RateLimiting\Exceptions\RateLimitException;
use App\RateLimiting\Exceptions\RedisUnavailableException;
use Redis;
use Throwable;

/**
 * Adaptador dos contratos RateLimitRedisClient (comandos individuais) e
 * RateLimitScriptRunner (EVAL atômico) sobre a extensão phpredis pura,
 * SEM nenhuma dependência do framework.
 *
 * Responsabilidade: permitir que os MESMOS algoritmos usados pelo
 * middleware (naive, token_bucket, leaky_bucket) rodem em processos PHP
 * avulsos — é o que a prova de concorrência
 * (scripts/prove_race_condition.php) usa para disparar dezenas de
 * processos concorrentes sem subir a aplicação inteira. Comportamento
 * idêntico ao LaravelRedisClient por contrato.
 */
final class NativeRedisClient implements RateLimitRedisClient, RateLimitScriptRunner
{
    private Redis $connection;

    /**
     * Recebe: parâmetros de conexão. Faz: conecta IMEDIATAMENTE (conexão
     * tardia esconderia erro de infraestrutura no meio da medição). Retorna:
     * instância conectada. Efeitos colaterais: abre socket; lança
     * RedisUnavailableException se o Redis não responder — falha clara, sem
     * números inventados.
     */
    public function __construct(
        string $host,
        int $port,
        ?string $password = null,
        int $database = 0,
        float $timeoutSeconds = 2.0,
    ) {
        try {
            $this->connection = new Redis();
            $this->connection->connect($host, $port, $timeoutSeconds);

            if ($password !== null && $password !== '') {
                $this->connection->auth($password);
            }

            if ($database !== 0) {
                $this->connection->select($database);
            }
        } catch (Throwable $failure) {
            throw RedisUnavailableException::from($failure);
        }
    }

    public function get(string $key): ?string
    {
        $value = $this->run(fn () => $this->connection->get($key));

        return $value === false ? null : (string) $value;
    }

    public function setWithTtl(string $key, int $value, int $ttlSeconds): void
    {
        $this->run(fn () => $this->connection->set($key, (string) $value, ['ex' => $ttlSeconds]));
    }

    public function increment(string $key, int $amount): int
    {
        return (int) $this->run(fn () => $this->connection->incrBy($key, $amount));
    }

    public function ttl(string $key): int
    {
        return (int) $this->run(fn () => $this->connection->ttl($key));
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        $this->run(fn () => $this->connection->expire($key, $ttlSeconds));
    }

    public function delete(string $key): void
    {
        $this->run(fn () => $this->connection->del($key));
    }

    /**
     * Recebe: código Lua, KEYS e ARGV. Faz: EVAL via phpredis (execução
     * atômica no servidor). Retorna: resposta bruta do Redis. Efeitos
     * colaterais: os do script; lança RedisUnavailableException em falha de
     * infraestrutura e LuaScriptFailureException quando o servidor reporta
     * erro de script (phpredis devolve false e registra o erro em
     * getLastError() em vez de lançar).
     *
     * @param  list<string>  $keys
     * @param  list<int|float|string>  $arguments
     */
    public function evalScript(string $script, array $keys, array $arguments): mixed
    {
        return $this->run(function () use ($script, $keys, $arguments): mixed {
            $this->connection->clearLastError();

            $reply = $this->connection->eval($script, [...$keys, ...$arguments], count($keys));

            if ($reply === false) {
                $serverError = $this->connection->getLastError();

                if ($serverError !== null) {
                    $this->connection->clearLastError();

                    throw LuaScriptFailureException::serverError($serverError);
                }
            }

            return $reply;
        });
    }

    /**
     * Recebe: comando em closure. Faz: executa convertendo falha de
     * INFRAESTRUTURA em RedisUnavailableException; exceções de domínio já
     * tipadas (ex.: LuaScriptFailureException do evalScript) atravessam
     * intactas. Retorna: resultado bruto. Efeitos colaterais: os do comando.
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
