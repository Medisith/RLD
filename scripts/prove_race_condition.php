<?php

declare(strict_types=1);

/**
 * Prova empírica de concorrência dos algoritmos de limitação (Fases 1 a 3).
 *
 * Responsabilidade: disparar MUITAS tentativas de consumo CONCORRENTES
 * contra uma única chave de limitação e comparar "expected allowed" com
 * "obtained allowed".
 *
 *   --algorithm=naive (padrão — Fase 1)
 *       Espera-se SOBRE-ADMISSÃO: o check-then-act em comandos separados
 *       admite mais que a capacidade. É a prova de que o desenho é errado.
 *
 *   --algorithm=token_bucket (Fase 2) | --algorithm=leaky_bucket (Fase 3)
 *       Espera-se ZERO sobre-admissão: a decisão inteira roda num script
 *       Lua atômico no servidor. Nota de honestidade estatística: enquanto
 *       a rodada executa, o balde recarrega/drena (taxa x duração da
 *       rodada) — o relatório imprime essa margem legítima em separado;
 *       violação de atomicidade é apenas o que exceder capacity + margem.
 *
 * Dois modos de execução:
 *
 *   --mode=algorithm (padrão)
 *       Fork de N processos PHP (ext-pcntl); cada um instancia o MESMO
 *       algoritmo usado pelo middleware, sobre o adaptador phpredis puro
 *       (NativeRedisClient) — não requer vendor/ nem aplicação de pé. Uma
 *       barreira de largada via chave no Redis garante que todos os
 *       processos batam no algoritmo ao mesmo tempo.
 *
 *   --mode=http --url=http://localhost:8000/api/rate-limited/ping
 *       Requisições HTTP reais e concorrentes (curl_multi) contra a rota
 *       protegida. O ALGORITMO, nesse modo, é o que a config da aplicação
 *       define para a rota — os parâmetros --algorithm/--capacity daqui só
 *       calculam o "expected" do relatório.
 *
 * Uso típico (documentado em docs/fases/fase-1..3):
 *   php scripts/prove_race_condition.php --processes=40 --attempts=5 --rounds=3
 *   php scripts/prove_race_condition.php --algorithm=token_bucket --refill-rate=1
 *   php scripts/prove_race_condition.php --algorithm=leaky_bucket --leak-rate=1
 *
 * O script NUNCA inventa números: sem Redis (ou sem aplicação no modo http)
 * a saída é PENDENTE DE EXECUÇÃO com falha explícita.
 */

$projectRoot = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Autoloader mínimo: mapeia App\ -> app/ para rodar o domínio SEM vendor/.
// Somente classes puras (algoritmos, DTOs, contratos, adaptador nativo) são
// carregadas neste script — nada de Illuminate aqui.
// ---------------------------------------------------------------------------
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
use App\RateLimiting\Exceptions\RedisUnavailableException;
use App\RateLimiting\Infrastructure\NativeRedisClient;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;

// ---------------------------------------------------------------------------
// Leitura de opções de linha de comando (tudo com padrão sensato).
// ---------------------------------------------------------------------------
$options = getopt('', [
    'mode::', 'algorithm::', 'processes::', 'attempts::', 'capacity::',
    'window::', 'cost::', 'refill-rate::', 'leak-rate::', 'rounds::',
    'redis-host::', 'redis-port::', 'redis-db::', 'url::', 'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Usage: php scripts/prove_race_condition.php [options]
  --mode=algorithm|http   (default: algorithm)
  --algorithm=naive|token_bucket|leaky_bucket   (default: naive)
  --processes=N           concurrent processes (algorithm) /
                          simultaneous connections (http) (default: 40)
  --attempts=N            attempts per process (default: 5)
  --capacity=N            policy capacity (default: 50)
  --window=N              window/TTL in seconds — naive only (default: 60)
  --refill-rate=F         tokens per second — token_bucket only (default: 1.0)
  --leak-rate=F           drained units per second — leaky_bucket only (default: 1.0)
  --cost=N                cost per attempt (default: 1)
  --rounds=N              experiment repetitions (default: 3)
  --redis-host=HOST       (default: 127.0.0.1)   [algorithm mode]
  --redis-port=PORT       (default: 6379)        [algorithm mode]
  --redis-db=N            (default: 0)           [algorithm mode]
  --url=URL               protected route         [http mode]

HELP;
    exit(0);
}

$mode = (string) ($options['mode'] ?? 'algorithm');
$rawAlgorithm = (string) ($options['algorithm'] ?? 'naive');
$processCount = max(2, (int) ($options['processes'] ?? 40));
$attemptsPerProcess = max(1, (int) ($options['attempts'] ?? 5));
$capacity = max(1, (int) ($options['capacity'] ?? 50));
$windowSeconds = max(1, (int) ($options['window'] ?? 60));
$cost = max(1, (int) ($options['cost'] ?? 1));
$refillRate = max(0.001, (float) ($options['refill-rate'] ?? 1.0));
$leakRate = max(0.001, (float) ($options['leak-rate'] ?? 1.0));
$rounds = max(1, (int) ($options['rounds'] ?? 3));
$redisHost = (string) ($options['redis-host'] ?? '127.0.0.1');
$redisPort = (int) ($options['redis-port'] ?? 6379);
$redisDatabase = (int) ($options['redis-db'] ?? 0);
$targetUrl = (string) ($options['url'] ?? 'http://localhost:8000/api/rate-limited/ping');

$algorithm = AvailableAlgorithm::tryFrom($rawAlgorithm);
if ($algorithm === null) {
    fwrite(STDERR, "ERROR: unknown --algorithm '{$rawAlgorithm}' (use naive, token_bucket or leaky_bucket).\n");
    exit(1);
}

// Taxa de reposição efetiva por algoritmo: usada para calcular a margem
// LEGÍTIMA de admissões extras durante a própria rodada (recarga/drenagem).
$replenishRatePerSecond = match ($algorithm) {
    AvailableAlgorithm::Naive => 0.0,
    AvailableAlgorithm::TokenBucket => $refillRate,
    AvailableAlgorithm::LeakyBucket => $leakRate,
};

$totalAttempts = $processCount * $attemptsPerProcess;
$expectedAllowed = min($capacity, $totalAttempts);

echo "=== Concurrency proof — {$algorithm->value} ===\n";
echo "mode={$mode} | processes={$processCount} | attempts/process={$attemptsPerProcess} | ";
echo "total attempts={$totalAttempts}\n";
echo match ($algorithm) {
    AvailableAlgorithm::Naive => "policy: capacity={$capacity}, window={$windowSeconds}s, cost={$cost}\n",
    AvailableAlgorithm::TokenBucket => "policy: capacity={$capacity} (burst), refill_rate={$refillRate}/s, cost={$cost}\n",
    AvailableAlgorithm::LeakyBucket => "policy: capacity={$capacity} (volume), leak_rate={$leakRate}/s, cost={$cost}\n",
};
echo "expected allowed per round (correct): {$expectedAllowed}\n\n";

/**
 * Recebe: o algoritmo escolhido e as linhas de resultado por rodada (com a
 * margem legítima de reposição medida em cada rodada). Faz: imprime a
 * tabela em Markdown (pronta para colar em docs/fases/) e o veredito
 * adequado ao algoritmo — para o naive, excesso PROVA a race; para os
 * atômicos, excesso além da margem legítima denunciaria violação de
 * atomicidade. Retorna: void. Efeitos colaterais: escrita em stdout.
 *
 * @param  list<array{round: int, expected: int, obtained: int, legitMargin: int}>  $rows
 */
function printReport(AvailableAlgorithm $algorithm, array $rows): void
{
    echo "\n| Round | Expected allowed | Obtained allowed | Over-admission | Legit replenish margin |\n";
    echo "|------:|-----------------:|-----------------:|---------------:|-----------------------:|\n";

    $hadExcess = false;
    $hadViolation = false;

    foreach ($rows as $row) {
        $excess = $row['obtained'] - $row['expected'];
        $hadExcess = $hadExcess || $excess > 0;
        $hadViolation = $hadViolation || $excess > $row['legitMargin'];
        $percent = $row['expected'] > 0
            ? sprintf('%+d (%+.0f%%)', $excess, 100 * $excess / $row['expected'])
            : (string) $excess;
        echo sprintf(
            "| %5d | %16d | %16d | %14s | %23s |\n",
            $row['round'],
            $row['expected'],
            $row['obtained'],
            $percent,
            $algorithm === AvailableAlgorithm::Naive ? 'n/a' : '+'.$row['legitMargin'],
        );
    }

    echo "\nVerdict: ";

    if ($algorithm === AvailableAlgorithm::Naive) {
        echo $hadExcess
            ? "RACE CONDITION DEMONSTRATED — the naive limiter admitted more consumptions than capacity.\n"
            : "excess not observed in THIS run (concurrency is probabilistic — increase --processes/--attempts and retry).\n";

        return;
    }

    echo $hadViolation
        ? "ATOMICITY VIOLATION — over-admission beyond the legitimate replenish margin. Investigate.\n"
        : "NO OVER-ADMISSION — obtained allowed <= capacity (+ legitimate replenish during the round): atomic by construction, confirmed empirically.\n";
}

/**
 * Recebe: lista de durações em segundos. Faz: calcula o percentil 95 pelo
 * método do índice mais próximo (sem interpolação — simples e suficiente
 * para o volume destas baterias). Retorna: o p95 em segundos, ou 0.0 para
 * lista vazia. Efeitos colaterais: nenhum.
 *
 * @param  list<float>  $durationsSeconds
 */
function percentile95(array $durationsSeconds): float
{
    if ($durationsSeconds === []) {
        return 0.0;
    }

    sort($durationsSeconds);

    $index = (int) ceil(0.95 * count($durationsSeconds)) - 1;

    return $durationsSeconds[max(0, $index)];
}

/**
 * Recebe: algoritmo escolhido, cliente Redis nativo e parâmetros de taxa.
 * Faz: instancia a MESMA implementação usada pelo middleware em produção.
 * Retorna: RateLimitAlgorithm pronto. Efeitos colaterais: token/leaky leem
 * o arquivo .lua na construção (falha explícita se ausente).
 */
function makeLimiter(
    AvailableAlgorithm $algorithm,
    NativeRedisClient $client,
): RateLimitAlgorithm {
    return match ($algorithm) {
        AvailableAlgorithm::Naive => new NaiveRedisRateLimiter($client),
        AvailableAlgorithm::TokenBucket => new TokenBucketRateLimiter($client),
        AvailableAlgorithm::LeakyBucket => new LeakyBucketRateLimiter($client),
    };
}

// ===========================================================================
// MODO ALGORITHM — processos paralelos direto no algoritmo escolhido.
// ===========================================================================
if ($mode === 'algorithm') {
    foreach (['redis', 'pcntl'] as $extension) {
        if (! extension_loaded($extension)) {
            fwrite(STDERR, "ERROR: PHP extension '{$extension}' missing — required in algorithm mode.\n");
            exit(1);
        }
    }

    // Falha explícita e imediata se o Redis não estiver de pé.
    try {
        $controlClient = new NativeRedisClient($redisHost, $redisPort, null, $redisDatabase);
    } catch (RedisUnavailableException $failure) {
        fwrite(STDERR, "ERROR: {$failure->getMessage()}\n");
        fwrite(STDERR, "Start a local Redis (e.g. redis-server --daemonize yes) and run again.\n");
        fwrite(STDERR, "Proof result: PENDING EXECUTION — no numbers were invented.\n");
        exit(1);
    }

    $policy = new RateLimitPolicy(
        name: 'race_proof',
        capacity: $capacity,
        windowSeconds: $windowSeconds,
        defaultCost: $cost,
        keyStrategy: KeyStrategy::Ip,
        algorithm: $algorithm,
        refillRate: $algorithm === AvailableAlgorithm::TokenBucket ? $refillRate : null,
        leakRate: $algorithm === AvailableAlgorithm::LeakyBucket ? $leakRate : null,
    );

    // Chave única de contagem: TODOS os processos disputam o mesmo saldo,
    // como fariam N workers atendendo o mesmo cliente.
    $targetKey = "rate-limit:ip:race-proof:{$algorithm->value}";
    $startGateKey = 'rate-limit:proof:start-gate';

    $resultsDirectory = sys_get_temp_dir().'/race_proof_'.getmypid();
    if (! is_dir($resultsDirectory) && ! mkdir($resultsDirectory, 0777, true)) {
        fwrite(STDERR, "ERROR: could not create {$resultsDirectory}.\n");
        exit(1);
    }

    $reportRows = [];

    for ($round = 1; $round <= $rounds; $round++) {
        // Estado zerado a cada rodada: contador/balde e barreira removidos.
        $controlClient->delete($targetKey);
        $controlClient->delete($startGateKey);
        array_map(unlink(...), glob($resultsDirectory.'/*.result') ?: []);

        $childPids = [];

        for ($index = 0; $index < $processCount; $index++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                fwrite(STDERR, "ERROR: pcntl_fork failed on process {$index}.\n");
                exit(1);
            }

            if ($pid === 0) {
                // -------------------- PROCESSO FILHO --------------------
                // Conexão própria (jamais herdar o socket do pai) e o MESMO
                // algoritmo usado pelo middleware em produção.
                try {
                    $childClient = new NativeRedisClient($redisHost, $redisPort, null, $redisDatabase);
                    $limiter = makeLimiter($algorithm, $childClient);

                    // Barreira de largada: espera ocupada até o pai autorizar,
                    // maximizando a sobreposição de decisões entre os filhos.
                    $waitDeadline = microtime(true) + 10.0;
                    while ($childClient->get($startGateKey) === null) {
                        if (microtime(true) > $waitDeadline) {
                            exit(2); // largada nunca veio — aborta sem poluir a medição
                        }
                        usleep(200);
                    }

                    $allowedInChild = 0;
                    for ($attempt = 0; $attempt < $attemptsPerProcess; $attempt++) {
                        $result = $limiter->attempt($targetKey, $policy, $cost);
                        if ($result->allowed) {
                            $allowedInChild++;
                        }
                    }

                    file_put_contents(
                        $resultsDirectory.'/'.getmypid().'.result',
                        (string) $allowedInChild,
                    );
                    exit(0);
                } catch (Throwable $childFailure) {
                    fwrite(STDERR, 'child '.getmypid().": {$childFailure->getMessage()}\n");
                    exit(3);
                }
                // ------------------ FIM PROCESSO FILHO ------------------
            }

            $childPids[] = $pid;
        }

        // Pequena folga para todos os filhos conectarem e ficarem na barreira,
        // então: largada (cronometrada — a duração da rodada dimensiona a
        // margem legítima de recarga/drenagem dos algoritmos de balde).
        usleep(300_000);
        $roundStartedAt = microtime(true);
        $controlClient->setWithTtl($startGateKey, 1, 30);

        foreach ($childPids as $childPid) {
            pcntl_waitpid($childPid, $status);
        }

        $roundElapsedSeconds = microtime(true) - $roundStartedAt;
        $legitMargin = (int) ceil($roundElapsedSeconds * $replenishRatePerSecond);

        // Agregação por arquivos (um por filho): a métrica não passa pelo
        // Redis para não interferir no fenômeno que está sendo medido.
        $obtainedAllowed = 0;
        foreach (glob($resultsDirectory.'/*.result') ?: [] as $resultFile) {
            $obtainedAllowed += (int) file_get_contents($resultFile);
        }

        echo sprintf(
            "round %d: expected=%d, obtained=%d, round duration=%.3fs, legit replenish margin=%d\n",
            $round,
            $expectedAllowed,
            $obtainedAllowed,
            $roundElapsedSeconds,
            $legitMargin,
        );

        $reportRows[] = [
            'round' => $round,
            'expected' => $expectedAllowed,
            'obtained' => $obtainedAllowed,
            'legitMargin' => $legitMargin,
        ];
    }

    // Limpeza final: nada de lixo no Redis depois da prova.
    $controlClient->delete($targetKey);
    $controlClient->delete($startGateKey);

    printReport($algorithm, $reportRows);
    exit(0);
}

// ===========================================================================
// MODO HTTP — requisições concorrentes reais contra a rota protegida.
// O algoritmo efetivo é o da CONFIG da aplicação para a rota alvo.
// ===========================================================================
if ($mode === 'http') {
    if (! extension_loaded('curl')) {
        fwrite(STDERR, "ERROR: PHP extension 'curl' missing — required in http mode.\n");
        exit(1);
    }

    $reportRows = [];

    for ($round = 1; $round <= $rounds; $round++) {
        // No modo http o estado fica no Redis da APLICAÇÃO; para isolar as
        // rodadas o operador deve limpar a chave (FLUSHDB do banco usado ou
        // DEL da chave) OU aguardar recarga/janela. Documentado nas fases.
        $multi = curl_multi_init();
        $handles = [];

        $roundStartedAt = microtime(true);

        for ($index = 0; $index < $totalAttempts; $index++) {
            $handle = curl_init($targetUrl);
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        // Dispara tudo de uma vez e drena até terminar.
        do {
            $execCode = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0 && $execCode === CURLM_OK);

        $roundElapsedSeconds = microtime(true) - $roundStartedAt;
        $legitMargin = (int) ceil($roundElapsedSeconds * $replenishRatePerSecond);

        $obtainedAllowed = 0;
        $denied = 0;
        $transportFailures = 0;
        // Latências individuais (Fase 10): permitem reportar p95 e vazão sem
        // depender do k6, para quem roda a comparação só com PHP.
        $durationsSeconds = [];

        foreach ($handles as $handle) {
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            match (true) {
                $status === 200 => $obtainedAllowed++,
                $status === 429 => $denied++,
                default => $transportFailures++,
            };

            if ($status === 200 || $status === 429) {
                $durationsSeconds[] = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
            }

            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }

        curl_multi_close($multi);

        if ($transportFailures === $totalAttempts) {
            fwrite(STDERR, "ERROR: no HTTP response from {$targetUrl} — is the app running?\n");
            fwrite(STDERR, "Proof result: PENDING EXECUTION — no numbers were invented.\n");
            exit(1);
        }

        echo sprintf(
            "round %d: expected=%d, obtained(200)=%d, denied(429)=%d, transport failures=%d, duration=%.3fs, p95=%.3fs, throughput=%.1f req/s\n",
            $round,
            $expectedAllowed,
            $obtainedAllowed,
            $denied,
            $transportFailures,
            $roundElapsedSeconds,
            percentile95($durationsSeconds),
            $roundElapsedSeconds > 0 ? count($durationsSeconds) / $roundElapsedSeconds : 0.0,
        );

        $reportRows[] = [
            'round' => $round,
            'expected' => $expectedAllowed,
            'obtained' => $obtainedAllowed,
            'legitMargin' => $legitMargin,
        ];

        if ($round < $rounds) {
            echo "waiting for the window/replenish before the next round...\n";
            sleep($windowSeconds + 1);
        }
    }

    printReport($algorithm, $reportRows);
    exit(0);
}

fwrite(STDERR, "ERROR: unknown mode '{$mode}' (use --mode=algorithm or --mode=http).\n");
exit(1);
