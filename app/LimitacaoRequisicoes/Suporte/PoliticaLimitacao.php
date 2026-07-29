<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Suporte;

use App\LimitacaoRequisicoes\Excecoes\ExcecaoPoliticaInvalida;

/**
 * DTO imutável que descreve a política de limitação aplicada a uma rota.
 *
 * Responsabilidade: transportar, já validados, os parâmetros de negócio do
 * limitador (capacidade, janela, custo, estratégia de chave e algoritmo).
 * Uma vez construída, a política nunca muda — qualquer combinação inválida
 * falha na construção, nunca em tempo de decisão.
 */
final readonly class PoliticaLimitacao
{
    /**
     * Recebe: nome da política (normalmente o nome da rota) e parâmetros de
     * negócio. Faz: valida invariantes (capacidade, janela e custo devem ser
     * positivos; custo não pode exceder a capacidade). Retorna: instância
     * imutável. Efeitos colaterais: nenhum; lança ExcecaoPoliticaInvalida
     * se qualquer invariante for violada.
     */
    public function __construct(
        public string $nome,
        public int $capacidade,
        public int $janelaSegundos,
        public int $custoPadrao,
        public EstrategiaChave $estrategiaChave,
        public AlgoritmoDisponivel $algoritmo,
    ) {
        if ($this->nome === '') {
            throw ExcecaoPoliticaInvalida::porMotivo('o nome da política não pode ser vazio');
        }

        if ($this->capacidade < 1) {
            throw ExcecaoPoliticaInvalida::porMotivo(
                "capacidade deve ser >= 1 (recebido: {$this->capacidade}) na política '{$this->nome}'"
            );
        }

        if ($this->janelaSegundos < 1) {
            throw ExcecaoPoliticaInvalida::porMotivo(
                "janela_segundos deve ser >= 1 (recebido: {$this->janelaSegundos}) na política '{$this->nome}'"
            );
        }

        if ($this->custoPadrao < 1) {
            throw ExcecaoPoliticaInvalida::porMotivo(
                "custo_padrao deve ser >= 1 (recebido: {$this->custoPadrao}) na política '{$this->nome}'"
            );
        }

        if ($this->custoPadrao > $this->capacidade) {
            throw ExcecaoPoliticaInvalida::porMotivo(
                "custo_padrao ({$this->custoPadrao}) não pode exceder a capacidade ({$this->capacidade}) na política '{$this->nome}'"
            );
        }
    }

    /**
     * Recebe: nome da política, array de configuração específico da rota e
     * array de configuração global (valores padrão). Faz: mescla rota sobre
     * global, converte strings da config em enums e valida tudo no
     * construtor. Retorna: PoliticaLimitacao pronta para uso. Efeitos
     * colaterais: nenhum; lança ExcecaoPoliticaInvalida para estratégia ou
     * algoritmo desconhecidos — falha explícita em vez de assumir default
     * silencioso.
     *
     * @param array<string, mixed> $configuracaoRota
     * @param array<string, mixed> $configuracaoGlobal
     */
    public static function daConfiguracao(
        string $nome,
        array $configuracaoRota,
        array $configuracaoGlobal,
    ): self {
        $mesclada = array_merge($configuracaoGlobal, $configuracaoRota);

        $estrategiaBruta = (string) ($mesclada['estrategia_chave'] ?? '');
        $estrategia = EstrategiaChave::tryFrom($estrategiaBruta)
            ?? throw ExcecaoPoliticaInvalida::porMotivo(
                "estrategia_chave desconhecida '{$estrategiaBruta}' na política '{$nome}'"
            );

        $algoritmoBruto = (string) ($mesclada['algoritmo'] ?? '');
        $algoritmo = AlgoritmoDisponivel::tryFrom($algoritmoBruto)
            ?? throw ExcecaoPoliticaInvalida::porMotivo(
                "algoritmo desconhecido '{$algoritmoBruto}' na política '{$nome}' — apenas 'ingenuo' existe nas Fases 0 e 1"
            );

        return new self(
            nome: $nome,
            capacidade: (int) ($mesclada['capacidade'] ?? 0),
            janelaSegundos: (int) ($mesclada['janela_segundos'] ?? 0),
            custoPadrao: (int) ($mesclada['custo_padrao'] ?? 0),
            estrategiaChave: $estrategia,
            algoritmo: $algoritmo,
        );
    }
}
