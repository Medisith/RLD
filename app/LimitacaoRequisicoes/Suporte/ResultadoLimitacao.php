<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Suporte;

/**
 * DTO imutável com o veredito de uma tentativa de consumo.
 *
 * Responsabilidade: transportar do algoritmo para o middleware tudo que a
 * camada HTTP precisa para responder (permitir/negar, headers de limite e
 * instrução de retry), sem que o middleware conheça detalhes do algoritmo.
 */
final readonly class ResultadoLimitacao
{
    /**
     * Recebe: veredito e contadores já calculados pelo algoritmo. Faz:
     * apenas transporta (normalização de negativos fica a cargo das
     * fábricas). Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        // true se a requisição pode prosseguir.
        public bool $permitido,
        // Consumos ainda disponíveis na janela APÓS esta decisão (>= 0).
        public int $restante,
        // Capacidade total da política (espelhado em X-RateLimit-Limit).
        public int $limite,
        // Segundos até valer a pena tentar de novo (Retry-After quando negado;
        // 0 quando permitido).
        public int $tentarNovamenteEm,
        // Valor string do algoritmo que decidiu (rastreabilidade em log).
        public string $algoritmo,
        // Chave de limitação completa usada na decisão.
        public string $chave,
    ) {
    }

    /**
     * Recebe: política, chave e quantidade restante após o consumo. Faz:
     * monta veredito de permissão com contadores saneados. Retorna:
     * resultado permitido. Efeitos colaterais: nenhum.
     */
    public static function permitido(
        PoliticaLimitacao $politica,
        string $chave,
        int $restante,
    ): self {
        return new self(
            permitido: true,
            restante: max(0, $restante),
            limite: $politica->capacidade,
            tentarNovamenteEm: 0,
            algoritmo: $politica->algoritmo->value,
            chave: $chave,
        );
    }

    /**
     * Recebe: política, chave e segundos até a janela expirar. Faz: monta
     * veredito de negação; "restante" é 0 por definição — se sobrasse saldo,
     * a requisição não teria sido negada. Retorna: resultado negado.
     * Efeitos colaterais: nenhum.
     */
    public static function negado(
        PoliticaLimitacao $politica,
        string $chave,
        int $tentarNovamenteEm,
    ): self {
        return new self(
            permitido: false,
            restante: 0,
            limite: $politica->capacidade,
            // Nunca instruir retry imediato (mínimo de 1s) para não convidar
            // o cliente a martelar a API no instante da expiração.
            tentarNovamenteEm: max(1, $tentarNovamenteEm),
            algoritmo: $politica->algoritmo->value,
            chave: $chave,
        );
    }
}
