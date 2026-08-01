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
            // Env-driven (Fase 8) para facilitar a comparação de carga sem
            // editar código: RATE_LIMIT_PING_ALGORITHM=naive|token_bucket|
            // leaky_bucket. window/refill/leak herdam da config global.
            'algorithm' => env('RATE_LIMIT_PING_ALGORITHM', 'token_bucket'),
            'refill_rate' => 1.0,
        ],

    ],

    // ------------------------------------------------------------------
    // Quota composta por TENANT (Fase 9) — DESLIGADA por padrão.
    //
    // Com enabled=true e o header presente, cada requisição passa por DOIS
    // checks: o do cliente (acima) e o do balde do tenant, compartilhado
    // por todos os clientes daquele tenant na mesma rota. Chave:
    // rate-limit:tenant:{tenantId}:{routeName}. A ordem é CLIENTE-primeiro
    // (justificativa em docs/fases/fase-9-tenant-quotas-and-runbook.md).
    // Os campos abaixo são mesclados sobre a config global (mesma regra
    // das políticas por rota). Planos por tenant (Fase 11) ficam em
    // 'plans' / 'assignments'; overrides por rota de tenant ficam fora.
    // ------------------------------------------------------------------
    'tenant' => [
        'enabled' => (bool) env('RATE_LIMIT_TENANT_ENABLED', false),
        'header' => env('RATE_LIMIT_TENANT_HEADER', 'X-Tenant-Id'),

        // Valores-base do balde de tenant. Um plano (abaixo) sobrescreve o
        // que quiser destes campos; o que ele omitir é herdado daqui.
        'capacity' => (int) env('RATE_LIMIT_TENANT_CAPACITY', 200),
        'algorithm' => env('RATE_LIMIT_TENANT_ALGORITHM', 'token_bucket'),
        'refill_rate' => (float) env('RATE_LIMIT_TENANT_REFILL_RATE', 4.0),
        'leak_rate' => (float) env('RATE_LIMIT_TENANT_LEAK_RATE', 4.0),

        // ------------------------------------------------------------------
        // Planos de cota (Fase 11) — sem billing real.
        //
        // O plano é resolvido SEMPRE no servidor: 'assignments' mapeia
        // tenantId -> nome do plano. O cliente NUNCA escolhe o próprio plano
        // (não há header de plano, de propósito) — ele apenas se identifica,
        // e mesmo essa identificação depende de um gateway confiável.
        // Tenant sem atribuição cai em 'default_plan'.
        // ------------------------------------------------------------------
        'default_plan' => env('RATE_LIMIT_TENANT_DEFAULT_PLAN', 'free'),

        'plans' => [
            'free' => [
                'capacity' => 60,
                'algorithm' => 'token_bucket',
                'refill_rate' => 1.0,
            ],
            'pro' => [
                'capacity' => 600,
                'algorithm' => 'token_bucket',
                'refill_rate' => 10.0,
            ],
        ],

        // Mapa estático tenantId -> plano. Um cadastro real (banco, painel de
        // billing) substituiria este array sem mudar o resto do desenho:
        // basta outra fonte alimentar a mesma resolução.
        'assignments' => [
            // 'acme' => 'pro',
        ],
    ],

];
