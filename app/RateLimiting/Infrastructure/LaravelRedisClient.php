<?php

declare(strict_types=1);

namespace App\RateLimiting\Infrastructure;

use App\RateLimiting\Contracts\RateLimitRedisClient;
use App\RateLimiting\Exceptions\RedisUnavailableException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Throwable;

/**
 * Adaptador do contrato RateLimitRedisClient sobre a conexão Redis do
 * Laravel (config/database.php, conexão "default").
 *
 * Responsabilidade: traduzir cada comando da porta em um comando individual
 * na conexão gerenciada pelo framework, convertendo QUALQUER falha de
 * infraestrutura em RedisUnavailableException — o algoritmo nunca vê
 * RedisException crua.
 */
final readonly class LaravelRedisClient implements RateLimitRedisClient
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
     * qualquer Throwable de infraestrutura em RedisUnavailableException
     * (falha explícita, sem fallback silencioso nesta fase). Retorna: o
     * resultado bruto do comando. Efeitos colaterais: os do comando.
     */
    private function run(callable $command): mixed
    {
        try {
            return $command();
        } catch (Throwable $failure) {
            throw RedisUnavailableException::from($failure);
        }
    }
}
