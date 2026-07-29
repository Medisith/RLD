<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Algoritmos;

use App\LimitacaoRequisicoes\Contratos\AlgoritmoLimitacao;
use App\LimitacaoRequisicoes\Contratos\ClienteRedisLimitacao;
use App\LimitacaoRequisicoes\Excecoes\ExcecaoPoliticaInvalida;
use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;
use App\LimitacaoRequisicoes\Suporte\ResultadoLimitacao;

/**
 * Limitador ingênuo por contador em janela fixa — check-then-act SEM
 * atomicidade.
 *
 * ============================ AVISO DE PROJETO ============================
 * ESTA CLASSE É PROPOSITALMENTE INCORRETA SOB CONCORRÊNCIA. Ela existe para
 * a Fase 1 do exercício: demonstrar, com números reais, por que "ler no
 * Redis, decidir no PHP e escrever no Redis" NÃO funciona como rate limiter
 * distribuído. A prova empírica está em scripts/provar_race_condition.php e
 * os resultados em docs/fases/fase-1-race-condition.md. A versão correta
 * (Token Bucket atômico via script Lua) virá em fase futura e substituirá
 * esta implementação atrás do MESMO contrato AlgoritmoLimitacao.
 * NÃO USE ESTA CLASSE EM PRODUÇÃO.
 * ==========================================================================
 *
 * Responsabilidade: manter um contador de consumo por chave com TTL igual à
 * janela da política, decidindo permitir/negar pela comparação
 * contador + custo <= capacidade.
 */
final readonly class LimitadorIngenuoRedis implements AlgoritmoLimitacao
{
    /**
     * Recebe: a porta de acesso ao Redis (somente comandos individuais —
     * ver ClienteRedisLimitacao). Faz: guarda a dependência. Retorna:
     * instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        private ClienteRedisLimitacao $clienteRedis,
    ) {
    }

    /**
     * Recebe: chave resolvida, política vigente e custo da requisição.
     * Faz: executa o ciclo check-then-act descrito abaixo. Retorna:
     * ResultadoLimitacao com o veredito. Efeitos colaterais: lê e escreve o
     * contador no Redis em COMANDOS SEPARADOS (esta é a falha estudada);
     * lança ExcecaoPoliticaInvalida para custo < 1 e propaga
     * ExcecaoRedisIndisponivel da infraestrutura.
     */
    public function tentar(string $chave, PoliticaLimitacao $politica, int $custo): ResultadoLimitacao
    {
        if ($custo < 1) {
            throw ExcecaoPoliticaInvalida::porMotivo(
                "custo deve ser >= 1 (recebido: {$custo}) ao consumir a chave '{$chave}'"
            );
        }

        // ------------------------------------------------------------------
        // PASSO 1 — CHECK: leitura do contador (comando GET isolado).
        // ------------------------------------------------------------------
        $valorBruto = $this->clienteRedis->obterValor($chave);
        $quantidadeConsumida = $valorBruto === null ? 0 : (int) $valorBruto;

        // ------------------------------------------------------------------
        // PASSO 2 — DECISÃO: tomada AQUI, no PHP, sobre um valor que já pode
        // estar obsoleto.
        //
        // >>> NÃO É ATÔMICO — JANELA DE CORRIDA <<<
        // Entre o GET acima e o SET/INCRBY abaixo, N outros processos podem
        // executar o MESMO GET e ler o MESMO valor. Todos comparam contra a
        // capacidade usando um retrato desatualizado, todos concluem "ainda
        // cabe" e todos escrevem. Resultado: mais consumos admitidos do que
        // a capacidade permite (prova na Fase 1). Nenhuma ordenação de
        // comandos individuais elimina isso; a correção exige que
        // leitura+decisão+escrita virem UMA operação atômica no servidor
        // (script Lua — fase futura).
        // ------------------------------------------------------------------
        if ($quantidadeConsumida + $custo > $politica->capacidade) {
            // Mais um comando separado (TTL): o valor pode mudar entre a
            // decisão e esta leitura — aceitável apenas porque é informativo
            // (Retry-After), não decisório.
            $ttlRestante = $this->clienteRedis->tempoRestanteTtl($chave);

            return ResultadoLimitacao::negado(
                politica: $politica,
                chave: $chave,
                // TTL -2 (chave expirou entre os comandos) ou -1 (sem TTL,
                // fruto de outra corrida documentada abaixo): instrui a
                // janela cheia por honestidade conservadora.
                tentarNovamenteEm: $ttlRestante > 0 ? $ttlRestante : $politica->janelaSegundos,
            );
        }

        // ------------------------------------------------------------------
        // PASSO 3 — ACT: escrita em comando separado do GET (o "buraco").
        // ------------------------------------------------------------------
        if ($valorBruto === null) {
            // Primeira requisição da janela (segundo este processo): SET com
            // TTL. >>> VULNERÁVEL <<< Se dois processos entram aqui ao mesmo
            // tempo, o segundo SET SOBRESCREVE o contador do primeiro
            // (consumo perdido) e reinicia o TTL (janela alongada). Ambos são
            // admitidos como se fossem "o primeiro".
            $this->clienteRedis->definirValorComTtl(
                chave: $chave,
                valor: $custo,
                ttlSegundos: $politica->janelaSegundos,
            );

            $quantidadeAposConsumo = $custo;
        } else {
            // Chave já existia: INCRBY. O incremento em si é atômico no
            // Redis, mas a DECISÃO que o autorizou foi tomada sobre leitura
            // velha — o contador pode ultrapassar a capacidade neste exato
            // comando (é o excesso que a prova da Fase 1 mede).
            $quantidadeAposConsumo = $this->clienteRedis->incrementar($chave, $custo);

            // Reparo de TTL órfão: se a chave expirou entre o GET e o INCRBY,
            // o INCRBY a recriou SEM TTL (contador eterno). O EXPIRE abaixo
            // remenda — em MAIS um comando separado, ele próprio sujeito a
            // corrida. A necessidade deste remendo é sintoma do desenho
            // errado, não solução.
            if ($this->clienteRedis->tempoRestanteTtl($chave) < 0) {
                $this->clienteRedis->expirarEm($chave, $politica->janelaSegundos);
            }
        }

        return ResultadoLimitacao::permitido(
            politica: $politica,
            chave: $chave,
            restante: $politica->capacidade - $quantidadeAposConsumo,
        );
    }
}
