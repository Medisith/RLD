# Rate Limiter Distribuído: Laravel + Redis + Lua

Portfólio **fechado (Fases 0-11)**: rate limiter distribuído construído do zero em Laravel 12,
**sem** o rate limiter nativo do framework (`RateLimiter`, `ThrottleRequests`, `throttle`: zero
uso). Primeiro a versão errada, provada errada com números; depois as versões corretas, provadas
corretas com os mesmos números.

CI verde em cada push/PR (`.github/workflows/ci.yml`). Caminho padrão: Redis + PHP no host.

## O problema, em uma frase

"Ler o contador no Redis, decidir no PHP, escrever de volta" parece funcionar, até duas
requisições concorrentes lerem o MESMO valor e ambas se admitirem. Sob rajada, o limite deixa de
ser um limite exatamente quando mais importa.

## A prova (números reais)

200 tentativas concorrentes (40 processos x 5) contra capacidade 50 (`scripts/prove_race_condition.php`):

| Algoritmo | Fase | Como decide | Admitidas de 200 (esperado: 50) |
|---|---|---|---|
| `naive` | 1 | GET, decide no PHP, SET/INCR (didático, **NÃO usar**) | **73-95** (+46% a +90%) |
| `token_bucket` | 2 | Script Lua atômico via EVALSHA (burst + recarga) | **exatamente 50** |
| `leaky_bucket` | 3 | Script Lua atômico via EVALSHA (vazão constante) | **exatamente 50** |

O naive fica no projeto de propósito, como artefato didático. Em HTTP multi-worker (Fase 10) ele
também sobre-admite; com `artisan serve` (single-worker) não, porque o servidor serializa.

## Mapa das fases

| Fase | Entrega |
|---|---|
| 0-1 | Framing, ADR, naive + prova da race |
| 2-3 | Token Bucket e Leaky Bucket (Lua atômico) + `failure_mode` |
| 4-5 | EVALSHA/NOSCRIPT, headers, Artisan ops, demo |
| 6-7 | Logs sem PII, métricas, TrustProxies, CI |
| 8-9 | k6, quota por tenant, runbook |
| 10-11 | Concorrência HTTP multi-worker, planos free/pro |

## Como rodar

Com Redis em `127.0.0.1:6379`:

```bash
# Provas (Windows: scripts/demo.ps1 | Linux/macOS: ./scripts/demo.sh)
./scripts/demo.sh

# App HTTP
cp .env.example .env && composer install && php artisan key:generate
php artisan serve --port=8000

curl -i -X POST http://localhost:8000/api/rate-limited/ping -H 'Accept: application/json'
# 200 + X-RateLimit-Limit / Remaining / Reset
# balde esgotado: 429 + Retry-After (code RATE_LIMIT_EXCEEDED)
```

Se preferir Redis em container: `docker compose up -d` sobe **só** o Redis; a app segue no host.

Roteiro de avaliação em 5 minutos:
[docs/fases/fase-7-portfolio-closure.md](docs/fases/fase-7-portfolio-closure.md).

A demo não exige `composer install`. O script de prova carrega os mesmos algoritmos por
autoloader próprio.

## Escolhendo o algoritmo

```php
// config/rate_limiting.php: cada rota escolhe seu algoritmo
'policies' => [
    'rate-limited.ping' => [
        'capacity' => 50,
        'algorithm' => 'token_bucket', 'refill_rate' => 1.0,
        // ou: 'algorithm' => 'leaky_bucket', 'leak_rate' => 1.0,
        // ou: 'algorithm' => 'naive', 'window_seconds' => 60,  // reproduz a Fase 1
    ],
],
```

**Token Bucket:** burst + média limitada (boa UX no seu produto).  
**Leaky Bucket:** vazão constante (protege downstream rígido).  
Troca rápida na rota de teste: `RATE_LIMIT_PING_ALGORITHM=leaky_bucket`.

## Quota por tenant e planos (desligados por padrão)

```bash
RATE_LIMIT_TENANT_ENABLED=true
```

```bash
curl -i -X POST http://localhost:8000/api/rate-limited/ping \
     -H 'Accept: application/json' -H 'X-Tenant-Id: acme'
```

Dois baldes em sequência (cliente, depois tenant): abusador barrado no cliente não drena a cota
da organização. **`X-Tenant-Id` não é fronteira de confiança**: só vale se um gateway confiável o
injeta.

Planos (Fase 11): o cliente diz *quem* é; o servidor decide *quanto* pode (`assignments` /
`plans` em config, sem header de plano). A chave do balde não inclui o plano (upgrade não zera
cota). Ver `docs/fases/fase-9-tenant-quotas-and-runbook.md` e
`docs/fases/fase-11-tenant-plans.md`.

```bash
php artisan rate-limit:dry-run rate-limited.ping --tenant=acme
```

## Carga e concorrência HTTP

**k6** (Fase 8) sobre `artisan serve`: latência e curva 200/429 (números em
`docs/fases/fase-8-k6-load.md`):

```bash
k6 run -e ALGORITHM=token_bucket k6/rate_limit_burst.js
```

**Multi-worker** (Fase 10): harness HTTP (Linux/macOS/WSL). No Windows nativo não roda;
use WSL ou a evidência na doc. Há também perfil Compose com FPM, se quiser medir a stack
Laravel completa.

| Algoritmo | Workers | allowed (esperado 50 / 200 req) |
|---|---:|---|
| naive (controle) | 1 | 50 / 50 / 50 |
| **naive** | **8** | **60 / 60 / 54** |
| token_bucket | 8 | 50 / 50 / 50 |
| leaky_bucket | 8 | 50 / 50 / 50 |

```bash
./scripts/http_concurrency_compare.sh 8 3 50
```

## Operação

```bash
php artisan rate-limit:inspect "rate-limit:ip:203.0.113.10:rate-limited.ping"
php artisan rate-limit:reset   "rate-limit:ip:203.0.113.10:rate-limited.ping"
php artisan rate-limit:dry-run rate-limited.ping --identifier=203.0.113.10
php artisan rate-limit:metrics
```

- **EVALSHA** com reidratação automática em `NOSCRIPT` (`SCRIPT LOAD`).
- Headers: `X-RateLimit-Limit`, `Remaining`, `Reset`; `Retry-After` no 429.
- **failure_mode:** Redis fora, `open` (passa sem contar) ou `closed` (503). Bug de Lua nunca
  é absorvido pelo fail-open.
- Runbook: [docs/runbook.md](docs/runbook.md).

## Observabilidade e proxy

- Logs com chave **pseudonimizada** (HMAC-SHA256 + `APP_KEY`); denies em `info`, allows em
  `debug`.
- Métricas: `allowed_total`, `denied_total`, `redis_errors_total`, `evalsha_reload_total`
  (`rate-limit:metrics`). Sem Prometheus de propósito.
- **TrustProxies:** padrão = nenhum proxy confiável. Atrás de LB: `TRUSTED_PROXIES=...`.

## Testes e CI

```bash
php artisan test
```

Redis real (banco 15; skip claro se ausente). Cobrem HTTP, algoritmos, failure_mode, EVALSHA,
ops, TrustProxies, PII, métricas, tenant e planos. Testes sequenciais **não** provam
atomicidade: isso é papel das provas / harness.

CI: lint + Pest + três provas de concorrência como smoke em cada push/PR.

## Checklist de honestidade: o que isto ainda NÃO é

- Não é pacote Composer publicado nem produto multi-tenant completo (sem billing, sem HA Redis).
- Multi-worker medido no **harness**, não na stack Laravel+nginx.
- Métricas são contadores acumulativos, não série temporal / Prometheus.
- Header de tenant depende de gateway confiável; dois checks não são atômicos entre si
  (vazamento de no máximo 1 token, documentado na Fase 9).
- Cardinalidade de chaves sob flood distribuído é limitada por TTL, não eliminada.

## Documentação

| Documento | Conteúdo |
|---|---|
| [docs/adr/001-rate-limiter-distribuido.md](docs/adr/001-rate-limiter-distribuido.md) | Decisão arquitetural |
| [docs/fases/fase-0-framing.md](docs/fases/fase-0-framing.md) ... [fase-11-tenant-plans.md](docs/fases/fase-11-tenant-plans.md) | Uma doc por fase |
| [docs/runbook.md](docs/runbook.md) | Operação: Redis fora, spoof, SCRIPT FLUSH, planos |

## Estrutura do domínio

```
app/RateLimiting/
├── Contracts/       RateLimitAlgorithm, KeyResolver, RedisClient, ScriptRunner
├── Algorithms/      Naive, TokenBucket, LeakyBucket, Factory
├── Redis/           LuaScript + scripts/*.lua
├── Support/         Policy, Result, Metrics, KeyAnonymizer, TenantQuota, enums
├── Resolvers/       DefaultKeyResolver, TenantQuotaResolver (planos, Fase 11)
├── Infrastructure/  LaravelRedisClient, NativeRedisClient, ExecutesEvalSha
├── Http/            AdvancedRateLimiterMiddleware (alias rate-limit.advanced)
└── Exceptions/      política, infra, script Lua
```

**Requisitos:** PHP >= 8.2 (`ext-redis`; `ext-pcntl` para provas em POSIX), Redis >= 5
(`TIME` em script), Composer. Sem banco relacional: sqlite em memória só para o boot do
framework.
