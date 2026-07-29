<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Infraestrutura;

use App\LimitacaoRequisicoes\Contratos\ClienteRedisLimitacao;
use App\LimitacaoRequisicoes\Excecoes\ExcecaoRedisIndisponivel;
use Redis;
use Throwable;

/**
 * Adaptador do contrato ClienteRedisLimitacao sobre a extensão phpredis
 * pura, SEM nenhuma dependência do framework.
 *
 * Responsabilidade: permitir que o MESMO LimitadorIngenuoRedis usado pelo
 * middleware rode em processos PHP avulsos — é o que a prova de race
 * (scripts/provar_race_condition.php) usa para disparar dezenas de
 * processos concorrentes sem subir a aplicação inteira. Comportamento de
 * comandos idêntico ao ClienteRedisLaravel por contrato.
 */
final class ClienteRedisNativo implements ClienteRedisLimitacao
{
    private Redis $conexao;

    /**
     * Recebe: parâmetros de conexão. Faz: conecta IMEDIATAMENTE (conexão
     * tardia esconderia erro de infraestrutura no meio da medição). Retorna:
     * instância conectada. Efeitos colaterais: abre socket; lança
     * ExcecaoRedisIndisponivel se o Redis não responder — falha clara, sem
     * números inventados.
     */
    public function __construct(
        string $host,
        int $porta,
        ?string $senha = null,
        int $banco = 0,
        float $tempoLimiteSegundos = 2.0,
    ) {
        try {
            $this->conexao = new Redis();
            $this->conexao->connect($host, $porta, $tempoLimiteSegundos);

            if ($senha !== null && $senha !== '') {
                $this->conexao->auth($senha);
            }

            if ($banco !== 0) {
                $this->conexao->select($banco);
            }
        } catch (Throwable $falha) {
            throw ExcecaoRedisIndisponivel::aPartirDe($falha);
        }
    }

    public function obterValor(string $chave): ?string
    {
        $valor = $this->executar(fn () => $this->conexao->get($chave));

        return $valor === false ? null : (string) $valor;
    }

    public function definirValorComTtl(string $chave, int $valor, int $ttlSegundos): void
    {
        $this->executar(fn () => $this->conexao->set($chave, (string) $valor, ['ex' => $ttlSegundos]));
    }

    public function incrementar(string $chave, int $quantidade): int
    {
        return (int) $this->executar(fn () => $this->conexao->incrBy($chave, $quantidade));
    }

    public function tempoRestanteTtl(string $chave): int
    {
        return (int) $this->executar(fn () => $this->conexao->ttl($chave));
    }

    public function expirarEm(string $chave, int $ttlSegundos): void
    {
        $this->executar(fn () => $this->conexao->expire($chave, $ttlSegundos));
    }

    public function remover(string $chave): void
    {
        $this->executar(fn () => $this->conexao->del($chave));
    }

    /**
     * Recebe: comando em closure. Faz: executa convertendo falha de
     * infraestrutura em ExcecaoRedisIndisponivel. Retorna: resultado bruto.
     * Efeitos colaterais: os do comando.
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
