<?php

declare(strict_types=1);

/**
 * Configuração de banco e Redis.
 *
 * Responsabilidade: nesta entrega o único armazenamento que importa é o
 * Redis (estado compartilhado do limitador). O sqlite em memória existe
 * apenas para satisfazer o boot do framework — nenhuma migration é usada.
 */

return [

    'default' => env('DB_CONNECTION', 'sqlite'),

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        // phpredis (extensão nativa) — mesma extensão usada pelo script de
        // prova de race, garantindo comportamento idêntico de conexão.
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            // Prefixo vazio de propósito: a chave de limitação é montada por
            // inteiro pelo RateLimitKeyResolver (padrão documentado no
            // ADR), sem prefixo implícito do framework escondendo parte dela.
            'prefix' => env('REDIS_PREFIX', ''),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
