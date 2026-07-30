# k6 — carga reproduzível (Fase 8)

Um único script parametrizado: `rate_limit_burst.js`. Roteiro completo, pré-requisitos e
interpretação dos números em [../docs/fases/fase-8-k6-load.md](../docs/fases/fase-8-k6-load.md).

```bash
# 1. Redis + aplicação de pé (em outro terminal)
docker compose up -d
composer install && php artisan key:generate
php artisan serve --port=8000

# 2. Burst de 200 requisições com 40 VUs, um algoritmo por vez
RATE_LIMIT_PING_ALGORITHM=token_bucket php artisan serve --port=8000   # (reinicie o serve ao trocar)
k6 run -e ALGORITHM=token_bucket k6/rate_limit_burst.js
```

Métricas que importam na saída do k6: `allowed_requests` e `denied_requests` (contadores
customizados deste script), `http_req_duration p(95)` e `http_reqs` (vazão). O
`unexpected_responses` deve ser sempre 0 — qualquer valor acima disso é falha de app/infra, não
decisão do limitador.

Aviso: sob `php artisan serve` (single-worker) as requisições são serializadas — o k6 mede
latência e contrato HTTP, não concorrência real. A prova de concorrência é
`scripts/prove_race_condition.php`.
