<?php

declare(strict_types=1);

/**
 * Configuração do limitador de requisições customizado.
 *
 * Responsabilidade: única fonte de verdade das políticas de limitação.
 *
 * Estado das fases: "naive" (Fase 1) permanece no projeto como artefato
 * didático — é INTENCIONALMENTE vulnerável a race condition e não deve ser
 * usado em produção (prova em docs/fases/fase-1-race-condition.md).
 * "token_bucket" (Fase 2) e "leaky_bucket" (Fase 3) são atômicos via script
 * Lua e são as escolhas corretas. "failure_mode" é HONRADO pelo middleware
 * desde a Fase 2.
 */

return [

    // Liga/desliga global do limitador. Desabilitado, o middleware deixa
    // toda requisição passar sem tocar no Redis.
    'enabled' => (bool) env('RATE_LIMIT_ENABLED', true),

    // Estratégia padrão de identificação do cliente: user | ip | user_or_ip.
    'key_strategy' => env('RATE_LIMIT_KEY_STRATEGY', 'user_or_ip'),

    // Algoritmo padrão (global). Cada rota pode sobrescrever em 'policies'.
    //   'naive'        -> Fase 1, check-then-act SEM atomicidade (didático)
    //   'token_bucket' -> Fase 2, burst de 'capacity' + recarga 'refill_rate'/s
    //   'leaky_bucket' -> Fase 3, vazão constante 'leak_rate'/s até 'capacity'
    'algorithm' => env('RATE_LIMIT_ALGORITHM', 'token_bucket'),

    // Significado de 'capacity' por algoritmo:
    //   naive        -> consumos máximos por janela fixa
    //   token_bucket -> burst máximo admitido de uma vez
    //   leaky_bucket -> volume máximo represado antes de negar
    'capacity' => (int) env('RATE_LIMIT_CAPACITY', 50),

    // Janela fixa / TTL, em segundos. Usada APENAS pelo 'naive'; os
    // algoritmos de balde derivam seus TTLs das taxas (higiene de chaves
    // calculada dentro dos scripts Lua).
    'window_seconds' => (int) env('RATE_LIMIT_WINDOW_SECONDS', 60),

    // Tokens recarregados por segundo — usado APENAS pelo 'token_bucket'.
    // Com capacity=50 e refill_rate=1.0: burst de 50 e regime sustentado de
    // 1 req/s (60/min), aproximando a política 50/60s das fases anteriores.
    'refill_rate' => (float) env('RATE_LIMIT_REFILL_RATE', 1.0),

    // Unidades drenadas por segundo — usado APENAS pelo 'leaky_bucket'.
    'leak_rate' => (float) env('RATE_LIMIT_LEAK_RATE', 1.0),

    // Custo consumido por requisição quando a política não especifica outro.
    'default_cost' => (int) env('RATE_LIMIT_DEFAULT_COST', 1),

    // HONRADO pelo middleware desde a Fase 2 (aplicado quando o Redis está
    // indisponível — RedisUnavailableException):
    //   'open'   -> requisição passa SEM contagem (prioriza disponibilidade)
    //   'closed' -> nega com 503 + Retry-After (prioriza proteção do backend)
    // Bug de script Lua nunca é absorvido pelo fail-open: propaga e falha alto.
    'failure_mode' => env('RATE_LIMIT_FAILURE_MODE', 'closed'),

    // Prefixo raiz de toda chave gravada no Redis pelo limitador.
    // Padrão completo: rate-limit:{strategy}:{identifier}:{routeName}
    'key_prefix' => 'rate-limit',

    // Políticas por rota (indexadas pelo NOME da rota). Qualquer chave
    // omitida herda o valor global acima. O 'algorithm' é selecionável POR
    // ROTA: naive | token_bucket | leaky_bucket.
    'policies' => [

        'rate-limited.ping' => [
            'capacity' => 50,
            'default_cost' => 1,
            'key_strategy' => 'user_or_ip',
            'algorithm' => 'token_bucket',
            'refill_rate' => 1.0,

            // Alternativas de demonstração (trocar 'algorithm' acima):
            //   'algorithm' => 'leaky_bucket', 'leak_rate' => 1.0,
            //   'algorithm' => 'naive', 'window_seconds' => 60,  // reproduz a Fase 1
        ],

    ],

];
