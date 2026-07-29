<?php

declare(strict_types=1);

/**
 * Configuração do limitador de requisições customizado.
 *
 * Responsabilidade: única fonte de verdade das políticas de limitação.
 *
 * IMPORTANTE (escopo das Fases 0 e 1): apenas o algoritmo "naive" existe.
 * Ele é INTENCIONALMENTE vulnerável a race condition (check-then-act sem
 * atomicidade) e será substituído por Token Bucket atômico via Lua em fase
 * futura. "failure_mode" está reservado e ainda NÃO é honrado pelo middleware
 * (falha de Redis hoje é explícita — ver docs/fases/fase-0-framing.md).
 */

return [

    // Liga/desliga global do limitador. Desabilitado, o middleware deixa
    // toda requisição passar sem tocar no Redis.
    'enabled' => (bool) env('RATE_LIMIT_ENABLED', true),

    // Estratégia padrão de identificação do cliente: user | ip | user_or_ip.
    'key_strategy' => env('RATE_LIMIT_KEY_STRATEGY', 'user_or_ip'),

    // Algoritmo padrão. Nesta entrega somente "naive" é aceito.
    // Reservados para fases futuras (NÃO implementados de propósito):
    // 'algorithm' => 'token_bucket',  // Fase 2 — atômico via script Lua
    // 'algorithm' => 'leaky_bucket',  // Fase 3 — vazão constante
    'algorithm' => env('RATE_LIMIT_ALGORITHM', 'naive'),

    // Quantidade máxima de consumos dentro da janela (limite exemplo: 50).
    'capacity' => (int) env('RATE_LIMIT_CAPACITY', 50),

    // Tamanho da janela fixa / TTL da chave no Redis, em segundos.
    'window_seconds' => (int) env('RATE_LIMIT_WINDOW_SECONDS', 60),

    // Custo consumido por requisição quando a política não especifica outro.
    'default_cost' => (int) env('RATE_LIMIT_DEFAULT_COST', 1),

    // RESERVADO (documentado na Fase 0, implementação futura):
    //   'open'   -> se o Redis cair, deixa a requisição passar (prioriza disponibilidade)
    //   'closed' -> se o Redis cair, nega com 503 (prioriza proteção do backend)
    // Nas Fases 0 e 1 a falha de Redis é propagada de forma explícita.
    'failure_mode' => env('RATE_LIMIT_FAILURE_MODE', 'closed'),

    // Prefixo raiz de toda chave gravada no Redis pelo limitador.
    // Padrão completo: rate-limit:{strategy}:{identifier}:{routeName}
    'key_prefix' => 'rate-limit',

    // Políticas por rota (indexadas pelo NOME da rota). Qualquer chave
    // omitida herda o valor global acima.
    'policies' => [

        'rate-limited.ping' => [
            'capacity' => 50,
            'window_seconds' => 60,
            'default_cost' => 1,
            'key_strategy' => 'user_or_ip',
            'algorithm' => 'naive',
        ],

    ],

];
