<?php

declare(strict_types=1);

/**
 * Configuração do limitador de requisições customizado.
 *
 * Responsabilidade: única fonte de verdade das políticas de limitação.
 * Chaves em português por serem domínio do exercício (ver ADR 001).
 *
 * IMPORTANTE (escopo das Fases 0 e 1): apenas o algoritmo "ingenuo" existe.
 * Ele é INTENCIONALMENTE vulnerável a race condition (check-then-act sem
 * atomicidade) e será substituído por Token Bucket atômico via Lua em fase
 * futura. "modo_falha" está reservado e ainda NÃO é honrado pelo middleware
 * (falha de Redis hoje é explícita — ver docs/fases/fase-0-framing.md).
 */

return [

    // Liga/desliga global do limitador. Desabilitado, o middleware deixa
    // toda requisição passar sem tocar no Redis.
    'habilitado' => (bool) env('LIMITACAO_HABILITADO', true),

    // Estratégia padrão de identificação do cliente: usuario | ip | usuario_ou_ip.
    'estrategia_chave' => env('LIMITACAO_ESTRATEGIA_CHAVE', 'usuario_ou_ip'),

    // Algoritmo padrão. Nesta entrega somente "ingenuo" é aceito.
    // Reservados para fases futuras (NÃO implementados de propósito):
    // 'algoritmo' => 'token_bucket',  // Fase 2 — atômico via script Lua
    // 'algoritmo' => 'leaky_bucket',  // Fase 3 — vazão constante
    'algoritmo' => env('LIMITACAO_ALGORITMO', 'ingenuo'),

    // Quantidade máxima de consumos dentro da janela (limite exemplo: 50).
    'capacidade' => (int) env('LIMITACAO_CAPACIDADE', 50),

    // Tamanho da janela fixa / TTL da chave no Redis, em segundos.
    'janela_segundos' => (int) env('LIMITACAO_JANELA_SEGUNDOS', 60),

    // Custo consumido por requisição quando a política não especifica outro.
    'custo_padrao' => (int) env('LIMITACAO_CUSTO_PADRAO', 1),

    // RESERVADO (documentado na Fase 0, implementação futura):
    //   'aberto'  -> se o Redis cair, deixa a requisição passar (prioriza disponibilidade)
    //   'fechado' -> se o Redis cair, nega com 503 (prioriza proteção do backend)
    // Nas Fases 0 e 1 a falha de Redis é propagada de forma explícita.
    'modo_falha' => env('LIMITACAO_MODO_FALHA', 'fechado'),

    // Prefixo raiz de toda chave gravada no Redis pelo limitador.
    // Padrão completo: limitacao:{estrategia}:{identificador}:{nomeRota}
    'prefixo_chave' => 'limitacao',

    // Políticas por rota (indexadas pelo NOME da rota). Qualquer chave
    // omitida herda o valor global acima.
    'politicas' => [

        'limitado.ping' => [
            'capacidade' => 50,
            'janela_segundos' => 60,
            'custo_padrao' => 1,
            'estrategia_chave' => 'usuario_ou_ip',
            'algoritmo' => 'ingenuo',
        ],

    ],

];
