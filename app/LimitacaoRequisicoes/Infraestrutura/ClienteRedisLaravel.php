<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Infraestrutura;

use App\LimitacaoRequisicoes\Contratos\ClienteRedisLimitacao;
use App\LimitacaoRequisicoes\Excecoes\ExcecaoRedisIndisponivel;
use Illuminate\Contracts\Redis\Factory as FabricaConexoesRedis;
use Throwable;

/**
 * Adaptador do contrato ClienteRedisLimitacao sobre a conexão Redis do
 * Laravel (config/database.php, conexão "default").
 *
 * Responsabilidade: traduzir cada comando da porta em um comando individual
 * na conexão gerenciada pelo framework, convertendo QUALQUER falha de
 * infraestrutura em ExcecaoRedisIndisponivel — o algoritmo nunca vê
 * RedisException crua.
 */
final readonly class ClienteRedisLaravel implements ClienteRedisLimitacao
{
    /**
     * Recebe: a fábrica de conexões Redis do framework. Faz: guarda a
     * dependência. Retorna: instância imutável. Efeitos colaterais: nenhum
     * (a conexão só é aberta no primeiro comando).
     */
    public function __construct(
        private FabricaConexoesRedis $fabricaConexoes,
    ) {
    }

    public function obterValor(string $chave): ?string
    {
        $valor = $this->executar(fn () => $this->conexao()->get($chave));

        // phpredis devolve false para chave inexistente; normaliza para null
        // para que o algoritmo trabalhe com um único "não existe".
        return ($valor === null || $valor === false) ? null : (string) $valor;
    }

    public function definirValorComTtl(string $chave, int $valor, int $ttlSegundos): void
    {
        $this->executar(fn () => $this->conexao()->set($chave, (string) $valor, 'EX', $ttlSegundos));
    }

    public function incrementar(string $chave, int $quantidade): int
    {
        return (int) $this->executar(fn () => $this->conexao()->incrby($chave, $quantidade));
    }

    public function tempoRestanteTtl(string $chave): int
    {
        return (int) $this->executar(fn () => $this->conexao()->ttl($chave));
    }

    public function expirarEm(string $chave, int $ttlSegundos): void
    {
        $this->executar(fn () => $this->conexao()->expire($chave, $ttlSegundos));
    }

    public function remover(string $chave): void
    {
        $this->executar(fn () => $this->conexao()->del($chave));
    }

    /**
     * Recebe: nada. Faz: obtém a conexão "default" da fábrica. Retorna: a
     * conexão do framework. Efeitos colaterais: pode abrir socket na
     * primeira chamada.
     */
    private function conexao(): mixed
    {
        return $this->fabricaConexoes->connection();
    }

    /**
     * Recebe: um comando encapsulado em closure. Faz: executa e converte
     * qualquer Throwable de infraestrutura em ExcecaoRedisIndisponivel
     * (falha explícita, sem fallback silencioso nesta fase). Retorna: o
     * resultado bruto do comando. Efeitos colaterais: os do comando.
     */
    private function executar(callable $comando): mixed
    {
        try {
            return $comando();
        } catch (Throwable $falha) {
            throw ExcecaoRedisIndisponivel::aPartirDe($falha);
        }
    }
}
