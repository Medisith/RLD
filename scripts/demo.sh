#!/usr/bin/env bash
# =========================================================================
# Demo reproduzível do rate limiter (Fase 5) — Linux/macOS/WSL.
#
# Responsabilidade: em uma única execução, (1) garantir um Redis acessível
# (subindo o docker-compose.yml se preciso), (2) rodar as TRÊS provas de
# concorrência — naive, token_bucket e leaky_bucket — e (3) apontar o
# contraste. Nenhum número é fabricado: tudo que aparece na tela vem das
# execuções reais de scripts/prove_race_condition.php.
#
# Requisitos: php com extensões redis e pcntl (o modo algorithm forka
# processos — em Windows nativo use WSL; ver scripts/demo.ps1).
#
# Uso: ./scripts/demo.sh  [processos] [tentativas] [rodadas]
# =========================================================================
set -euo pipefail

PROCESSES="${1:-40}"
ATTEMPTS="${2:-5}"
ROUNDS="${3:-2}"

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

# ---- pré-requisitos: php + extensões -------------------------------------
if ! command -v php >/dev/null 2>&1; then
    echo "ERRO: php não encontrado no PATH." >&2
    exit 1
fi

for EXTENSION in redis pcntl; do
    if ! php -m | grep -qi "^${EXTENSION}$"; then
        echo "ERRO: extensão PHP '${EXTENSION}' ausente — necessária para a demo." >&2
        echo "Em Windows nativo (sem pcntl), rode via WSL ou use scripts/demo.ps1." >&2
        exit 1
    fi
done

# ---- Redis acessível? senão, tenta o Compose -----------------------------
redis_ok() {
    php -r 'try { $r = new Redis(); $r->connect("127.0.0.1", 6379, 1.5); exit($r->ping() ? 0 : 1); } catch (Throwable) { exit(1); }'
}

if redis_ok; then
    echo "[demo] Redis acessível em 127.0.0.1:6379."
else
    echo "[demo] Redis não respondeu — tentando subir via docker compose..."
    if command -v docker >/dev/null 2>&1; then
        docker compose up -d redis
        for _ in $(seq 1 30); do
            if redis_ok; then break; fi
            sleep 1
        done
    fi
    if ! redis_ok; then
        echo "ERRO: sem Redis em 127.0.0.1:6379 e não foi possível subir via Docker." >&2
        echo "Suba um Redis (ex.: docker compose up -d  ou  redis-server --daemonize yes) e rode de novo." >&2
        exit 1
    fi
    echo "[demo] Redis de demonstração no ar."
fi

# ---- as três provas, mesma bateria ---------------------------------------
banner() {
    echo
    echo "==========================================================================="
    echo "$1"
    echo "==========================================================================="
}

banner "1/3 — naive (Fase 1): check-then-act SEM atomicidade. Espere SOBRE-ADMISSÃO."
php scripts/prove_race_condition.php --algorithm=naive \
    --processes="$PROCESSES" --attempts="$ATTEMPTS" --rounds="$ROUNDS"

banner "2/3 — token_bucket (Fase 2): Lua atômico via EVALSHA. Espere obtained == capacity."
php scripts/prove_race_condition.php --algorithm=token_bucket --refill-rate=1 \
    --processes="$PROCESSES" --attempts="$ATTEMPTS" --rounds="$ROUNDS"

banner "3/3 — leaky_bucket (Fase 3): vazão constante, mesma atomicidade. Espere obtained == capacity."
php scripts/prove_race_condition.php --algorithm=leaky_bucket --leak-rate=1 \
    --processes="$PROCESSES" --attempts="$ATTEMPTS" --rounds="$ROUNDS"

banner "Contraste"
cat <<'RESUMO'
Leia os três veredictos acima, medidos AGORA na sua máquina:
  - naive:        "obtained allowed" ESTOURA a capacidade — a decisão no PHP
                  sobre leitura obsoleta admite além do limite (Fase 1).
  - token_bucket: obtained == capacity, sempre — leitura+decisão+escrita num
                  único passo atômico dentro do Redis (Fase 2).
  - leaky_bucket: obtained == capacity, sempre — mesma atomicidade, vazão
                  constante para proteger downstream rígido (Fase 3).
Detalhes e números registrados: docs/fases/fase-1..fase-4.
RESUMO
