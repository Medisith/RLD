<?php

declare(strict_types=1);

/**
 * Harness HTTP multi-worker do domínio de rate limiting (Fase 10).
 *
 * Responsabilidade: expor os MESMOS algoritmos usados pelo middleware
 * através de HTTP real, servido por vários processos simultâneos, para que
 * a concorrência entre requisições HTTP possa ser medida SEM depender de
 * `composer install` (o servidor embutido do PHP com PHP_CLI_SERVER_WORKERS
 * forka N workers de verdade).
 *
 * Por que existe, ao lado do setup Compose + php-fpm/nginx: o Compose é a
 * topologia de produção e é o que a Fase 10 recomenda para medir com k6;
 * este harness é o caminho de MENOR dependência — roda em qualquer máquina
 * com PHP e Redis, sem vendor/, sem Docker e sem k6 — e serve tanto para
 * reproduzir a evidência da fase quanto para CI.
 *
 * O que ele NÃO é: não é a aplicação Laravel. Não passa por middleware,
 * roteamento, TrustProxies, métricas ou logs. Ele isola exatamente a
 * variável em estudo — decisão do algoritmo sob requisições HTTP paralelas.
 * Conclusões sobre latência da stack completa exigem o setup Compose.
 *
 * Uso:
 *   PHP_CLI_SERVER_WORKERS=8 ALGORITHM=naive CAPACITY=50 \
 *       php -S 127.0.0.1:8080 scripts/http_harness.php
 *
 *   php scripts/prove_race_condition.php --mode=http \
 *       --url=http://127.0.0.1:8080/ --algorithm=naive --capacity=50
 *
 * Variáveis de ambiente: ALGORITHM (naive|token_bucket|leaky_bucket),
 * CAPACITY, WINDOW_SECONDS, REFILL_RATE, LEAK_RATE, COST, TARGET_KEY,
 * REDIS_HOST, REDIS_PORT, REDIS_DB.
 */

$projectRoot = dirname(__DIR__);

// Autoloader mínimo App\ -> app/, idêntico ao da prova de concorrência:
// nenhuma dependência de vendor/ neste caminho.
spl_autoload_register(function (string $class) use ($projectRoot): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $path = $projectRoot.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';

    if (is_file($path)) {
        require $path;
    }
});

use App\RateLimiting\Algorithms\LeakyBucketRateLimiter;
use App\RateLimiting\Algorithms\NaiveRedisRateLimiter;
use App\RateLimiting\Algorithms\TokenBucketRateLimiter;
use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Infrastructure\NativeRedisClient;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;

/**
 * Recebe: nome da variável e valor padrão. Faz: lê do ambiente do processo.
 * Retorna: o valor como string. Efeitos colaterais: nenhum.
 */
function harnessEnv(string $name, string $default): string
{
    $value = getenv($name);

    return ($value === false || $value === '') ? $default : $value;
}

$algorithm = AvailableAlgorithm::tryFrom(harnessEnv('ALGORITHM', 'token_bucket'));

if ($algorithm === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'unknown ALGORITHM']);

    return true;
}

$cost = max(1, (int) harnessEnv('COST', '1'));

$policy = new RateLimitPolicy(
    name: 'http-harness',
    capacity: max(1, (int) harnessEnv('CAPACITY', '50')),
    windowSeconds: max(1, (int) harnessEnv('WINDOW_SECONDS', '60')),
    defaultCost: $cost,
    keyStrategy: KeyStrategy::Ip,
    algorithm: $algorithm,
    refillRate: $algorithm === AvailableAlgorithm::TokenBucket
        ? (float) harnessEnv('REFILL_RATE', '1.0')
        : null,
    leakRate: $algorithm === AvailableAlgorithm::LeakyBucket
        ? (float) harnessEnv('LEAK_RATE', '1.0')
        : null,
);

// Chave FIXA de propósito: todos os workers disputam o mesmo balde, que é
// exatamente o cenário de "N servidores atendendo o mesmo cliente".
$targetKey = harnessEnv('TARGET_KEY', 'rate-limit:ip:http-harness:'.$algorithm->value);

try {
    // Conexão por requisição: cada worker é um processo próprio e não deve
    // herdar socket de ninguém.
    $redisClient = new NativeRedisClient(
        host: harnessEnv('REDIS_HOST', '127.0.0.1'),
        port: (int) harnessEnv('REDIS_PORT', '6379'),
        password: null,
        database: (int) harnessEnv('REDIS_DB', '0'),
    );

    /** @var RateLimitAlgorithm $limiter */
    $limiter = match ($algorithm) {
        AvailableAlgorithm::Naive => new NaiveRedisRateLimiter($redisClient),
        AvailableAlgorithm::TokenBucket => new TokenBucketRateLimiter($redisClient),
        AvailableAlgorithm::LeakyBucket => new LeakyBucketRateLimiter($redisClient),
    };

    $result = $limiter->attempt($targetKey, $policy, $cost);
} catch (Throwable $failure) {
    // Falha explícita: 500 nunca deve ser confundido com decisão do
    // limitador (a prova conta 500 como "transport failure", não como allow).
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => $failure->getMessage()]);

    return true;
}

// Mesmos headers de contrato do middleware, para que a leitura do lado do
// cliente seja idêntica à da aplicação real.
header('Content-Type: application/json');
header('X-RateLimit-Limit: '.$result->limit);
header('X-RateLimit-Remaining: '.$result->remaining);
header('X-RateLimit-Reset: '.$result->resetAfter);

if (! $result->allowed) {
    http_response_code(429);
    header('Retry-After: '.$result->retryAfter);
    echo json_encode([
        'message' => 'Rate limit exceeded.',
        'code' => 'RATE_LIMIT_EXCEEDED',
        'scope' => 'client',
        'limit' => $result->limit,
        'retry_after' => $result->retryAfter,
    ]);

    return true;
}

echo json_encode(['message' => 'pong', 'algorithm' => $result->algorithm]);

return true;
