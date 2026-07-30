// =========================================================================
// Carga reproduzível do rate limiter (Fase 8) — script k6 ÚNICO,
// parametrizado por variáveis de ambiente.
//
// Responsabilidade: disparar um burst HTTP contra POST /api/rate-limited/ping
// e contar, fim a fim, quantas requisições foram permitidas (200) e negadas
// (429), além das métricas nativas do k6 (http_req_duration p95, vazão).
// O ALGORITMO em teste é o que a APLICAÇÃO estiver configurada para usar
// (RATE_LIMIT_PING_ALGORITHM) — a variável ALGORITHM daqui só etiqueta o
// resultado para leitura humana.
//
// Uso (docs/fases/fase-8-k6-load.md tem o roteiro completo):
//   k6 run -e ALGORITHM=token_bucket k6/rate_limit_burst.js
//   k6 run -e ALGORITHM=naive -e VUS=40 -e ITERATIONS=200 \
//          -e BASE_URL=http://localhost:8000 k6/rate_limit_burst.js
//
// Aviso de honestidade (repetido na doc): `php artisan serve` atende UMA
// requisição por vez — sob esse servidor o k6 mede latência/contrato HTTP,
// mas NÃO exercita a corrida do naive (requisições serializadas). A prova
// de concorrência continua sendo scripts/prove_race_condition.php; para
// carga concorrente real via HTTP use um servidor multi-worker (FPM/Octane).
// =========================================================================

import http from 'k6/http';
import { check } from 'k6';
import { Counter } from 'k6/metrics';

const allowedRequests = new Counter('allowed_requests');
const deniedRequests = new Counter('denied_requests');
const unexpectedResponses = new Counter('unexpected_responses');

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const ALGORITHM = __ENV.ALGORITHM || 'unspecified';
const VUS = Number(__ENV.VUS || 40);
const ITERATIONS = Number(__ENV.ITERATIONS || 200);

export const options = {
    scenarios: {
        // Mesma bateria das provas de concorrência: N requisições em burst
        // disputadas por VUS usuários virtuais, sem pacing.
        burst: {
            executor: 'shared-iterations',
            vus: VUS,
            iterations: ITERATIONS,
            maxDuration: '120s',
        },
    },
    // Threshold frouxo de propósito: existe para o resumo destacar o p95,
    // nunca para reprovar a execução com um SLO inventado.
    thresholds: {
        http_req_duration: ['p(95)<60000'],
    },
    tags: { algorithm: ALGORITHM },
};

export default function () {
    const response = http.post(`${BASE_URL}/api/rate-limited/ping`, null, {
        headers: { Accept: 'application/json' },
        tags: { algorithm: ALGORITHM },
    });

    // 200 e 429 são AMBOS comportamento correto do limitador; qualquer outra
    // coisa (500, timeout) é problema de app/infra e aparece separado.
    check(response, {
        'status is 200 or 429': (r) => r.status === 200 || r.status === 429,
    });

    if (response.status === 200) {
        allowedRequests.add(1);
    } else if (response.status === 429) {
        deniedRequests.add(1);
    } else {
        unexpectedResponses.add(1);
    }
}
