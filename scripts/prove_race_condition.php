<?php

declare(strict_types=1);

/**
 * Prova empírica da race condition do NaiveRedisRateLimiter (Fase 1).
 *
 * Responsabilidade: disparar MUITAS tentativas de consumo CONCORRENTES
 * contra uma única chave de limitação e comparar "expected allowed"
 * (a capacidade da política) com "obtained allowed". Todo excesso obtido
 * acima da capacidade é contagem perdida pelo check-then-act não atômico.
 *
 * Dois modos de execução:
 *
 *   --mode=algorithm (padrão)
 *       Fork de N processos PHP (ext-pcntl); cada um instancia o MESMO
 *       NaiveRedisRateLimiter usado pelo middleware, porém sobre o
 *       adaptador phpredis puro (NativeRedisClient) — não requer vendor/
 *       nem aplicação de pé. Uma barreira de largada via chave no Redis
 *       garante que todos os processos batam no algoritmo ao mesmo tempo.
 *
 *   --mode=http --url=http://localhost:8000/api/rate-limited/ping
 *       Requisições HTTP reais e concorrentes (curl_multi) contra a rota
 *       protegida pelo middleware. Requer a aplicação instalada e servida
 *       (php artisan serve) e mede o mesmo fenômeno fim a fim.
 *
 * Uso típico (documentado em docs/fases/fase-1-race-condition.md):
 *   php scripts/prove_race_condition.php --processes=40 --attempts=5 --rounds=3
 *
 * O script NUNCA inventa números: sem Redis (ou sem aplicação no modo http)
 * a saída é PENDENTE DE EXECUÇÃO com falha explícita.
 */

$projectRoot = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Autoloader mínimo: mapeia App\ -> app/ para rodar o domínio SEM vendor/.
// Somente classes puras (algoritmo, DTOs, contratos, adaptador nativo) são
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

use App\RateLimiting\Algorithms\NaiveRedisRateLimiter;
use App\RateLimiting\Exceptions\RedisUnavailableException;
use App\RateLimiting\Infrastructure\NativeRedisClient;
use App\RateLimiting\Support\AvailableAlgorithm;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;

// ---------------------------------------------------------------------------
// Leitura de opções de linha de comando (tudo com padrão sensato).
// ---------------------------------------------------------------------------
$options = getopt('', [
    'mode::', 'processes::', 'attempts::', 'capacity::', 'window::',
    'cost::', 'rounds::', 'redis-host::', 'redis-port::', 'redis-db::',
    'url::', 'help',
]);

if (isset($options['help'])) {
    echo <<<HELP
Usage: php scripts/prove_race_condition.php [options]
  --mode=algorithm|http   (default: algorithm)
  --processes=N           concurrent processes (algorithm) /
                          simultaneous connections (http) (default: 40)
  --attempts=N            attempts per process (default: 5)
  --capacity=N            policy capacity (default: 50)
  --window=N              window/TTL in seconds (default: 60)
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
$processCount = max(2, (int) ($options['processes'] ?? 40));
$attemptsPerProcess = max(1, (int) ($options['attempts'] ?? 5));
$capacity = max(1, (int) ($options['capacity'] ?? 50));
$windowSeconds = max(1, (int) ($options['window'] ?? 60));
$cost = max(1, (int) ($options['cost'] ?? 1));
$rounds = max(1, (int) ($options['rounds'] ?? 3));
$redisHost = (string) ($options['redis-host'] ?? '127.0.0.1');
$redisPort = (int) ($options['redis-port'] ?? 6379);
$redisDatabase = (int) ($options['redis-db'] ?? 0);
$targetUrl = (string) ($options['url'] ?? 'http://localhost:8000/api/rate-limited/ping');

$totalAttempts = $processCount * $attemptsPerProcess;
$expectedAllowed = min($capacity, $totalAttempts);

echo "=== Race condition proof — NaiveRedisRateLimiter (Phase 1) ===\n";
echo "mode={$mode} | processes={$processCount} | attempts/process={$attemptsPerProcess} | ";
echo "total attempts={$totalAttempts}\n";
echo "policy: capacity={$capacity}, window={$windowSeconds}s, cost={$cost}\n";
echo "expected allowed per round (correct): {$expectedAllowed}\n\n";

/**
 * Recebe: linhas de resultado por rodada. Faz: imprime a tabela em Markdown
 * (pronta para colar em docs/fases/fase-1-race-condition.md) e o veredito.
 * Retorna: void. Efeitos colaterais: escrita em stdout.
 *
 * @param  list<array{round: int, expected: int, obtained: int}>  $rows
 */
function printReport(array $rows): void
{
    echo "\n| Round | Expected allowed | Obtained allowed | Over-admission |\n";
    echo "|------:|-----------------:|-----------------:|-------------:|\n";

    $hadExcess = false;

    foreach ($rows as $row) {
        $excess = $row['obtained'] - $row['expected'];
        $hadExcess = $hadExcess || $excess > 0;
        $percent = $row['expected'] > 0
            ? sprintf('%+d (%+.0f%%)', $excess, 100 * $excess / $row['expected'])
            : (string) $excess;
        echo sprintf(
            "| %5d | %16d | %16d | %12s |\n",
            $row['round'],
            $row['expected'],
            $row['obtained'],
            $percent,
        );
    }

    echo "\nVerdict: ";
    echo $hadExcess
        ? "RACE CONDITION DEMONSTRATED — the naive limiter admitted more consumptions than capacity.\n"
        : "excess not observed in THIS run (concurrency is probabilistic — increase --processes/--attempts and retry).\n";
}

// ===========================================================================
// MODO ALGORITHM — processos paralelos direto no NaiveRedisRateLimiter.
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
        algorithm: AvailableAlgorithm::Naive,
    );

    // Chave única de contagem: TODOS os processos disputam o mesmo saldo,
    // como fariam N workers atendendo o mesmo cliente.
    $targetKey = 'rate-limit:ip:race-proof:race_proof';
    $startGateKey = 'rate-limit:proof:start-gate';

    $resultsDirectory = sys_get_temp_dir().'/race_proof_'.getmypid();
    if (! is_dir($resultsDirectory) && ! mkdir($resultsDirectory, 0777, true)) {
        fwrite(STDERR, "ERROR: could not create {$resultsDirectory}.\n");
        exit(1);
    }

    $reportRows = [];

    for ($round = 1; $round <= $rounds; $round++) {
        // Estado zerado a cada rodada: contador e barreira removidos.
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
                // algoritmo ingênuo usado pelo middleware em produção.
                try {
                    $childClient = new NativeRedisClient($redisHost, $redisPort, null, $redisDatabase);
                    $limiter = new NaiveRedisRateLimiter($childClient);

                    // Barreira de largada: espera ocupada até o pai autorizar,
                    // maximizando a sobreposição GET/INCR entre os filhos.
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
        // então: largada.
        usleep(300_000);
        $controlClient->setWithTtl($startGateKey, 1, 30);

        foreach ($childPids as $childPid) {
            pcntl_waitpid($childPid, $status);
        }

        // Agregação por arquivos (um por filho): a métrica não passa pelo
        // Redis para não interferir no fenômeno que está sendo medido.
        $obtainedAllowed = 0;
        foreach (glob($resultsDirectory.'/*.result') ?: [] as $resultFile) {
            $obtainedAllowed += (int) file_get_contents($resultFile);
        }

        $finalCounter = $controlClient->get($targetKey);

        echo sprintf(
            "round %d: expected=%d, obtained=%d, final Redis counter=%s\n",
            $round,
            $expectedAllowed,
            $obtainedAllowed,
            $finalCounter ?? '(missing key)',
        );

        $reportRows[] = ['round' => $round, 'expected' => $expectedAllowed, 'obtained' => $obtainedAllowed];
    }

    // Limpeza final: nada de lixo no Redis depois da prova.
    $controlClient->delete($targetKey);
    $controlClient->delete($startGateKey);

    printReport($reportRows);
    exit(0);
}

// ===========================================================================
// MODO HTTP — requisições concorrentes reais contra a rota protegida.
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
        // DEL da chave) OU aguardar a janela expirar. Documentado na fase 1.
        $multi = curl_multi_init();
        $handles = [];

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

        $obtainedAllowed = 0;
        $denied = 0;
        $transportFailures = 0;

        foreach ($handles as $handle) {
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            match (true) {
                $status === 200 => $obtainedAllowed++,
                $status === 429 => $denied++,
                default => $transportFailures++,
            };
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
            "round %d: expected=%d, obtained(200)=%d, denied(429)=%d, transport failures=%d\n",
            $round,
            $expectedAllowed,
            $obtainedAllowed,
            $denied,
            $transportFailures,
        );

        $reportRows[] = ['round' => $round, 'expected' => $expectedAllowed, 'obtained' => $obtainedAllowed];

        if ($round < $rounds) {
            echo "waiting for the window to expire before the next round...\n";
            sleep($windowSeconds + 1);
        }
    }

    printReport($reportRows);
    exit(0);
}

fwrite(STDERR, "ERROR: unknown mode '{$mode}' (use --mode=algorithm or --mode=http).\n");
exit(1);
