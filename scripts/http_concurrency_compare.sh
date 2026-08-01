#!/usr/bin/env bash
# =========================================================================
# Comparação de concorrência HTTP multi-worker (Fase 10).
#
# Responsabilidade: reproduzir, em qualquer máquina com PHP + Redis e SEM
# composer/Docker/k6, a evidência central da fase: sob um servidor HTTP com
# VÁRIOS workers, o naive sobre-admite; token_bucket e leaky_bucket não.
#
# Como funciona: sobe o scripts/http_harness.php no servidor embutido do PHP
# com PHP_CLI_SERVER_WORKERS=N (N processos reais) e dispara o burst com
# scripts/prove_race_condition.php --mode=http (curl_multi). Inclui a rodada
# de CONTROLE com 1 worker, que é o que isola o número de workers como causa.
#
# Uso: ./scripts/http_concurrency_compare.sh [workers] [rodadas] [capacidade]
#      (padrão: 8 workers, 3 rodadas, capacidade 50)
# =========================================================================
set -euo pipefail

WORKERS="${1:-8}"
ROUNDS="${2:-3}"
CAPACITY="${3:-50}"
PORT="${PORT:-8080}"
REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
REDIS_PORT="${REDIS_PORT:-6379}"

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

for EXTENSION in redis pcntl curl; do
    if ! php -m | grep -qi "^${EXTENSION}$"; then
        echo "ERRO: extensão PHP '${EXTENSION}' ausente — necessária para esta comparação." >&2
        exit 1
    fi
done

if ! php -r 'try { $r = new Redis(); $r->connect(getenv("REDIS_HOST") ?: "127.0.0.1", (int) (getenv("REDIS_PORT") ?: 6379), 1.5); exit($r->ping() ? 0 : 1); } catch (Throwable) { exit(1); }'; then
    echo "ERRO: Redis não responde em ${REDIS_HOST}:${REDIS_PORT}. Suba com: docker compose up -d" >&2
    exit 1
fi

HARNESS_PID=""

# Encerra o servidor mesmo se o script for interrompido no meio.
cleanup() {
    if [ -n "$HARNESS_PID" ] && kill -0 "$HARNESS_PID" 2>/dev/null; then
        kill "$HARNESS_PID" 2>/dev/null || true
        wait "$HARNESS_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT

# Recebe: algoritmo e número de workers. Faz: sobe o harness, roda N rodadas
# limpando a chave entre elas e imprime uma linha por rodada.
run_series() {
    local algorithm="$1"
    local workers="$2"
    local key="rate-limit:ip:http-harness:${algorithm}"

    PHP_CLI_SERVER_WORKERS="$workers" \
    ALGORITHM="$algorithm" CAPACITY="$CAPACITY" \
    REFILL_RATE=1.0 LEAK_RATE=1.0 WINDOW_SECONDS=60 \
    REDIS_HOST="$REDIS_HOST" REDIS_PORT="$REDIS_PORT" \
        php -S "127.0.0.1:${PORT}" scripts/http_harness.php >/dev/null 2>&1 &
    HARNESS_PID=$!
    sleep 1.5

    echo "--- ${algorithm} | workers=${workers} | capacidade=${CAPACITY} | burst 40x5=200 ---"

    for round in $(seq 1 "$ROUNDS"); do
        redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" del "$key" >/dev/null 2>&1 || \
            php -r '$r=new Redis();$r->connect(getenv("REDIS_HOST"),(int)getenv("REDIS_PORT"));$r->del($argv[1]);' "$key"
        printf '  rodada %s: ' "$round"
        php scripts/prove_race_condition.php --mode=http --url="http://127.0.0.1:${PORT}/" \
            --algorithm="$algorithm" --capacity="$CAPACITY" --refill-rate=1 --leak-rate=1 \
            --processes=40 --attempts=5 --rounds=1 2>/dev/null \
            | grep -oE 'obtained\(200\)=[0-9]+.*'
    done

    cleanup
    HARNESS_PID=""
    sleep 0.4
}

echo "=========================================================================="
echo "CONTROLE — naive com 1 worker (equivale ao 'artisan serve' da Fase 8)"
echo "Esperado: SEM sobre-admissão; o servidor serializa e a corrida não aparece."
echo "=========================================================================="
run_series naive 1

echo
echo "=========================================================================="
echo "naive com ${WORKERS} workers — esperado: SOBRE-ADMISSÃO (allowed > capacidade)"
echo "=========================================================================="
run_series naive "$WORKERS"

echo
echo "=========================================================================="
echo "token_bucket com ${WORKERS} workers — esperado: allowed == capacidade"
echo "=========================================================================="
run_series token_bucket "$WORKERS"

echo
echo "=========================================================================="
echo "leaky_bucket com ${WORKERS} workers — esperado: allowed == capacidade"
echo "=========================================================================="
run_series leaky_bucket "$WORKERS"

echo
echo "A única variável entre o controle e a segunda bateria é o número de"
echo "workers. Se o naive sobe acima da capacidade só na segunda, a corrida"
echo "check-then-act foi provada pelo caminho HTTP. Registro: docs/fases/fase-10."
