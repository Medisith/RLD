<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Redis;

/**
 * Comando de operação: inspeciona o estado bruto de uma chave de limitação.
 *
 * Responsabilidade: dar visão de ops/demo sobre o que o limitador guardou no
 * Redis para uma chave — contador ingênuo (string), Token Bucket (hash com
 * tokens/last_refill_ms) ou Leaky Bucket (hash com level/last_leak_ms) —
 * com TTL e interpretação humana. Somente LEITURA: nunca consome saldo.
 *
 * Nota de arquitetura: comandos de operação acessam a conexão Redis do
 * framework diretamente (fora das portas do domínio) de propósito — as
 * portas RateLimitRedisClient/RateLimitScriptRunner existem para fechar o
 * CAMINHO DE DECISÃO, e ganhar TYPE/HGETALL ali só para introspecção
 * afrouxaria o desenho que a Fase 2 endureceu.
 *
 * Não vaza segredos: imprime apenas o conteúdo da própria chave.
 */
class RateLimitInspectCommand extends Command
{
    protected $signature = 'rate-limit:inspect
        {key : Chave completa de limitação (ex.: rate-limit:ip:203.0.113.10:rate-limited.ping)}';

    protected $description = 'Inspects the raw Redis state of a rate limit key (read-only)';

    /**
     * Recebe: a chave via argumento e a fábrica de conexões Redis. Faz:
     * identifica o tipo da chave e imprime estado + interpretação por
     * algoritmo. Retorna: SUCCESS (inclusive para chave ausente — ausência
     * é o estado de repouso, não um erro); FAILURE apenas para tipo de
     * chave que o limitador não reconhece. Efeitos colaterais: nenhum no
     * Redis (somente leitura).
     */
    public function handle(RedisFactory $redis): int
    {
        $key = (string) $this->argument('key');

        /** @var Redis $client */
        $client = $redis->connection()->client();

        if (! (bool) $client->exists($key)) {
            $this->info("Key '{$key}' not found — state at rest (full bucket / empty bucket / expired window).");

            return self::SUCCESS;
        }

        $type = $client->type($key);
        $ttlSeconds = (int) $client->ttl($key);

        return match ($type) {
            Redis::REDIS_STRING => $this->renderNaiveCounter($client, $key, $ttlSeconds),
            Redis::REDIS_HASH => $this->renderBucket($client, $key, $ttlSeconds),
            default => $this->failUnknownType($key, $type),
        };
    }

    /**
     * Recebe: cliente, chave string e TTL. Faz: imprime o contador de
     * janela fixa do algoritmo naive. Retorna: SUCCESS. Efeitos: nenhum.
     */
    private function renderNaiveCounter(Redis $client, string $key, int $ttlSeconds): int
    {
        $consumed = (string) $client->get($key);

        $this->line("Algorithm guess: naive (fixed window counter)");
        $this->table(['Field', 'Value'], [
            ['key', $key],
            ['consumed in window', $consumed],
            ['window TTL (s)', $ttlSeconds >= 0 ? (string) $ttlSeconds : "{$ttlSeconds} (no TTL — orphan counter, known naive flaw)"],
        ]);

        return self::SUCCESS;
    }

    /**
     * Recebe: cliente, chave hash e TTL. Faz: distingue Token Bucket de
     * Leaky Bucket pelos campos do hash e imprime estado + interpretação.
     * Retorna: SUCCESS, ou FAILURE para hash sem os campos esperados.
     * Efeitos: nenhum.
     */
    private function renderBucket(Redis $client, string $key, int $ttlSeconds): int
    {
        /** @var array<string, string> $state */
        $state = (array) $client->hGetAll($key);

        if (isset($state['tokens'], $state['last_refill_ms'])) {
            $ageSeconds = $this->millisecondsSince((int) $state['last_refill_ms']);

            $this->line('Algorithm guess: token_bucket');
            $this->table(['Field', 'Value'], [
                ['key', $key],
                ['tokens available', $state['tokens']],
                ['last refill (ms epoch)', $state['last_refill_ms']],
                ['seconds since last refill', number_format($ageSeconds, 3)],
                ['hygiene TTL (s)', (string) $ttlSeconds],
            ]);

            return self::SUCCESS;
        }

        if (isset($state['level'], $state['last_leak_ms'])) {
            $ageSeconds = $this->millisecondsSince((int) $state['last_leak_ms']);

            $this->line('Algorithm guess: leaky_bucket');
            $this->table(['Field', 'Value'], [
                ['key', $key],
                ['queued level', $state['level']],
                ['last leak (ms epoch)', $state['last_leak_ms']],
                ['seconds since last leak', number_format($ageSeconds, 3)],
                ['hygiene TTL (s)', (string) $ttlSeconds],
            ]);

            return self::SUCCESS;
        }

        $this->error("Key '{$key}' is a hash but has none of the expected field sets (tokens/level).");

        return self::FAILURE;
    }

    /**
     * Recebe: chave e tipo numérico do phpredis. Faz: reporta tipo não
     * gerenciado pelo limitador. Retorna: FAILURE. Efeitos: nenhum.
     */
    private function failUnknownType(string $key, int $type): int
    {
        $this->error("Key '{$key}' has Redis type #{$type}, which this rate limiter never writes — not one of ours.");

        return self::FAILURE;
    }

    /**
     * Recebe: um instante em milissegundos de epoch. Faz: calcula a idade
     * em segundos usando o relógio LOCAL — informativo apenas (o relógio de
     * decisão é sempre o TIME do Redis; divergência aqui é aceitável).
     * Retorna: idade em segundos. Efeitos: nenhum.
     */
    private function millisecondsSince(int $epochMilliseconds): float
    {
        return max(0.0, (microtime(true) * 1000 - $epochMilliseconds) / 1000);
    }
}
