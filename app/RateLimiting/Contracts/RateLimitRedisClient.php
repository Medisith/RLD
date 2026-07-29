<?php

declare(strict_types=1);

namespace App\RateLimiting\Contracts;

/**
 * Porta mínima de acesso ao Redis usada pelos algoritmos de limitação.
 *
 * Responsabilidade: expor SOMENTE comandos individuais (GET, SET, INCRBY,
 * TTL, EXPIRE, DEL). A ausência proposital de qualquer operação composta ou
 * atômica aqui é parte do exercício da Fase 1: o algoritmo ingênuo só tem
 * acesso a comandos separados, e é exatamente isso que o torna vulnerável a
 * race condition. Scripts Lua/EVAL entrarão neste contrato (ou em um novo)
 * apenas na fase futura.
 *
 * A porta também desacopla o algoritmo do framework: em produção a
 * implementação usa a conexão Redis do Laravel; na prova de race o mesmo
 * algoritmo roda com a extensão phpredis pura, sem vendor/.
 */
interface RateLimitRedisClient
{
    /**
     * Recebe: chave. Faz: GET simples. Retorna: valor bruto como string, ou
     * null quando a chave não existe. Efeitos colaterais: nenhum; lança
     * RedisUnavailableException em falha de infraestrutura.
     */
    public function get(string $key): ?string;

    /**
     * Recebe: chave, valor inteiro e TTL em segundos. Faz: SET valor EX ttl
     * (sobrescreve valor E TTL existentes — comportamento relevante para a
     * race documentada). Retorna: void. Efeitos colaterais: escreve no
     * Redis; lança RedisUnavailableException em falha de infraestrutura.
     */
    public function setWithTtl(string $key, int $value, int $ttlSeconds): void;

    /**
     * Recebe: chave e quantidade. Faz: INCRBY. Retorna: o valor do contador
     * APÓS o incremento. Efeitos colaterais: escreve no Redis; cria a chave
     * SEM TTL se ela não existir (outro buraco do desenho ingênuo); lança
     * RedisUnavailableException em falha de infraestrutura.
     */
    public function increment(string $key, int $amount): int;

    /**
     * Recebe: chave. Faz: TTL. Retorna: segundos restantes; -1 se a chave
     * existe sem expiração; -2 se a chave não existe. Efeitos colaterais:
     * nenhum; lança RedisUnavailableException em falha de infraestrutura.
     */
    public function ttl(string $key): int;

    /**
     * Recebe: chave e TTL em segundos. Faz: EXPIRE. Retorna: void. Efeitos
     * colaterais: altera expiração no Redis; lança
     * RedisUnavailableException em falha de infraestrutura.
     */
    public function expire(string $key, int $ttlSeconds): void;

    /**
     * Recebe: chave. Faz: DEL (usado por testes e pela prova de race para
     * zerar o estado entre rodadas). Retorna: void. Efeitos colaterais:
     * remove a chave; lança RedisUnavailableException em falha de
     * infraestrutura.
     */
    public function delete(string $key): void;
}
