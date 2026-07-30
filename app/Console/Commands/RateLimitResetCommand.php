<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Redis;

/**
 * Comando de operação: zera o estado de uma chave de limitação.
 *
 * Responsabilidade: remover a chave no Redis, devolvendo o cliente ao
 * estado de repouso (balde cheio no token_bucket, balde vazio no
 * leaky_bucket, janela nova no naive). Uso típico: destravar um cliente
 * legítimo em demo/suporte. A remoção é segura por desenho: chave ausente
 * é um estado VÁLIDO e bem definido para os três algoritmos.
 *
 * Mesma nota de arquitetura do rate-limit:inspect: ferramenta de ops fala
 * com a conexão do framework diretamente; as portas do domínio continuam
 * fechadas ao caminho de decisão.
 */
class RateLimitResetCommand extends Command
{
    protected $signature = 'rate-limit:reset
        {key : Chave completa de limitação (ex.: rate-limit:ip:203.0.113.10:rate-limited.ping)}';

    protected $description = 'Deletes a rate limit key, returning that client to the rest state';

    /**
     * Recebe: a chave via argumento e a fábrica de conexões Redis. Faz:
     * DEL na chave. Retorna: SUCCESS sempre que o comando executou (tanto
     * "removida" quanto "já não existia" são desfechos corretos de um
     * reset). Efeitos colaterais: remove a chave do Redis.
     */
    public function handle(RedisFactory $redis): int
    {
        $key = (string) $this->argument('key');

        /** @var Redis $client */
        $client = $redis->connection()->client();

        $removedCount = (int) $client->del($key);

        if ($removedCount > 0) {
            $this->info("Key '{$key}' removed — client back to rest state (full/empty bucket, fresh window).");
        } else {
            $this->info("Key '{$key}' did not exist — client was already at rest state. Nothing to do.");
        }

        return self::SUCCESS;
    }
}
