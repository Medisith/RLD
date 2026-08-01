# Rate Limiter Distribuído — Laravel + Redis + Lua

Rate limiter distribuído construído do zero em Laravel 12, **sem** o rate limiter nativo do
framework (`RateLimiter`, `ThrottleRequests`, `throttle` — zero uso). Primeiro a versão errada,
provada errada com números; depois as versões corretas, provadas corretas com os mesmos números.

## O problema, em uma frase

"Ler o contador no Redis, decidir no PHP, escrever de volta" parece funcionar — até duas
requisições concorrentes lerem o MESMO valor e ambas se admitirem. Sob rajada, o limite deixa de
ser um limite exatamente quando mais importa.

## A prova (números reais, medidos por `scripts/prove_race_condition.php`)

200 tentativas concorrentes (40 processos × 5) contra capacidade 50, PHP 8.4 + Redis 7:

| Algoritmo | Fase | Como decide | Admitidas de 200 (esperado: 50) |
|---|---|---|---|
| `naive` | 1 | GET → decide no PHP → SET/INCR (didático, **NÃO usar**) | **73–95** (+46% a +90%) |
| `token_bucket` | 2 | Script Lua atômico via EVALSHA — burst + recarga | **exatamente 50** |
| `leaky_bucket` | 3 | Script Lua atômico via EVALSHA — vazão constante | **exatamente 50** |

O naive fica no projeto de propósito, como artefato didático comparativo. Registros completos:
`docs/fases/fase-1` a `fase-4`.

## Rodar sem Docker (caminho padrão)

Docker é **opcional**. Com Redis na máquina (`127.0.0.1:6379`), ignore o Compose.

```bash
# Provas (Windows: scripts/demo.ps1 | Linux/macOS: ./scripts/demo.sh)
./scripts/demo.sh

# App HTTP
cp .env.example .env && composer install && php artisan key:generate
php artisan serve --port=8000

curl -i -X POST http://localhost:8000/api/rate-limited/ping -H 'Accept: application/json'
# 200 + X-RateLimit-Limit: 50 | X-RateLimit-Remaining: 49 | X-RateLimit-Reset: 50
# esgotado o balde: 429 + Retry-After + X-RateLimit-Reset (code RATE_LIMIT_EXCEEDED)
```

Sem Redis local, opcional: `docker compose up -d` sobe **só** o Redis; a app segue no host.

Tem 5 minutos? O roteiro completo de avaliação está em
[docs/fases/fase-7-portfolio-closure.md](docs/fases/fase-7-portfolio-closure.md).

A demo não exige `composer install` — o script de prova carrega os mesmos algoritmos do
middleware por autoloader próprio.

## Escolhendo o algoritmo (global e por rota)

```php
// config/rate_limiting.php — cada rota escolhe seu algoritmo
'policies' => [
    'rate-limited.ping' => [
        'capacity' => 50,
        'algorithm' => 'token_bucket', 'refill_rate' => 1.0,
        // ou: 'algorithm' => 'leaky_bucket', 'leak_rate' => 1.0,
        // ou: 'algorithm' => 'naive', 'window_seconds' => 60,  // reproduz a Fase 1
    ],
],
```

Regra prática (tabela completa em `docs/fases/fase-3-leaky-bucket.md`): **Token Bucket** deixa o
burst passar e limita a média — proteja o SEU produto com boa UX; **Leaky Bucket** nivela a
saída em `leak_rate`/s — proteja downstream rígido (gateway de pagamento, SLA duro).

Para comparar algoritmos sem editar código: `RATE_LIMIT_PING_ALGORITHM=leaky_bucket` no ambiente.

## Quota por tenant (opcional, desligada por padrão)

Ligada, cada requisição consome dois baldes — o do cliente e o compartilhado da organização:

```bash
RATE_LIMIT_TENANT_ENABLED=true
RATE_LIMIT_TENANT_CAPACITY=200
RATE_LIMIT_TENANT_REFILL_RATE=4.0
```

```bash
curl -i -X POST http://localhost:8000/api/rate-limited/ping \
     -H 'Accept: application/json' -H 'X-Tenant-Id: acme'
# 429 quando a cota compartilhada acaba: {"code":"RATE_LIMIT_EXCEEDED","scope":"tenant",...}
```

O cliente é checado primeiro, de propósito: um cliente abusivo barrado no próprio balde nunca
drena a cota do tenant. **O header não é fronteira de confiança** — só tem valor se um gateway
confiável o injeta. Desenho, trade-offs e limites: `docs/fases/fase-9-tenant-quotas-and-runbook.md`.

**Planos de cota** (Fase 11): tenants diferentes, limites diferentes, sem billing real.

```php
// config/rate_limiting.php -> tenant
'default_plan' => 'free',
'plans' => [
    'free' => ['capacity' => 60,  'algorithm' => 'token_bucket', 'refill_rate' => 1.0],
    'pro'  => ['capacity' => 600, 'algorithm' => 'token_bucket', 'refill_rate' => 10.0],
],
'assignments' => ['acme' => 'pro'],   // decidido no SERVIDOR, nunca por header
```

O cliente diz quem é; o servidor decide quanto ele pode — não existe header de plano. A chave do
balde não inclui o plano, então mudar de plano não zera a cota. Plano inexistente falha alto em
vez de conceder a cota-base em silêncio. Ver `docs/fases/fase-11-tenant-plans.md`.

```bash
php artisan rate-limit:dry-run rate-limited.ping --tenant=acme   # plano resolvido + chave
```

## Carga e concorrência HTTP

**k6 sobre `artisan serve`** (Fase 8) — mede latência e a curva permitidas/negadas vista pelo
cliente. Números reais medidos: naive 50 allowed / p95 8.53 s, token_bucket 93 / 9.04 s,
leaky_bucket 92 / 8.61 s em 40 VUs × 200 iterações. Detalhes e leitura em
`docs/fases/fase-8-k6-load.md`.

```bash
k6 run -e ALGORITHM=token_bucket k6/rate_limit_burst.js   # exige app servida + Redis
```

**HTTP multi-worker** (Fase 10) — `artisan serve` é single-worker e serializa, então o naive não
sobre-admite por ali. Servindo com vários workers, ele sobre-admite:

| Algoritmo | Workers | allowed (esperado 50, de 200 requisições) |
|---|---:|---|
| naive (controle) | 1 | 50 / 50 / 50 |
| **naive** | **8** | **60 / 60 / 54** |
| token_bucket | 8 | 50 / 50 / 50 |
| leaky_bucket | 8 | 50 / 50 / 50 |

```bash
# Caminho sem Docker (recomendado neste portfólio): harness + prova HTTP
./scripts/http_concurrency_compare.sh 8 3 50
```

Requer **Linux, macOS ou WSL** (`PHP_CLI_SERVER_WORKERS` / fork). No Windows nativo o harness
multi-worker **não roda** — use WSL com Redis local, ou aceite a evidência já registrada na doc.
Compose + nginx/php-fpm existe como caminho **opcional** (quando houver Docker); não é
necessário para fechar a fase. Detalhe em `docs/fases/fase-10-http-concurrency.md`.

## Operação (Fase 4)

- **EVALSHA + reidratação automática:** toda decisão atômica envia só o SHA-1 (40 bytes) em vez
  do fonte Lua (~4 KB). Em `NOSCRIPT` (restart/failover/`SCRIPT FLUSH`), o adaptador recarrega o
  `.lua` versionado via `SCRIPT LOAD`, confere o SHA e repete — transparente, provado com
  `SCRIPT FLUSH` real no meio da operação. Nenhuma ação extra de deploy.
- **Headers:** `X-RateLimit-Limit`, `X-RateLimit-Remaining` e `X-RateLimit-Reset` no 200 e no
  429; `Retry-After` no 429. `Reset` é delta em segundos (consistente com `Retry-After`) até o
  estado voltar ao repouso — janela expirar, balde encher ou balde drenar.
- **failure_mode honrado:** Redis fora → `open` deixa passar sem contagem (log de alerta) ou
  `closed` nega com 503. Bug de script Lua nunca é absorvido: propaga.
- **Comandos Artisan:**

```bash
php artisan rate-limit:inspect "rate-limit:ip:203.0.113.10:rate-limited.ping"   # estado bruto (read-only)
php artisan rate-limit:reset   "rate-limit:ip:203.0.113.10:rate-limited.ping"   # volta ao repouso
php artisan rate-limit:dry-run rate-limited.ping --identifier=203.0.113.10      # política efetiva, sem consumir
php artisan rate-limit:metrics                                                   # contadores (--reset para zerar)
```

## Observabilidade e IP atrás de proxy (Fase 6)

- **Logs sem PII crua:** toda linha do limitador loga a chave PSEUDONIMIZADA — o identificador
  (IP/id de usuário) vira HMAC-SHA256 truncado com a `APP_KEY` como segredo; estratégia e rota
  ficam legíveis. Mesmo cliente = mesmo pseudônimo (correlação preservada), sem reversão. Denies
  em `info`, allows em `debug` (silenciosos em produção), `request_id` incluído quando o
  cliente/proxy envia `X-Request-Id`.
- **Métricas mínimas:** `allowed_total`, `denied_total`, `redis_errors_total` e
  `evalsha_reload_total` em um hash no Redis (HINCRBY best-effort — métrica nunca derruba
  requisição; com Redis fora, degrada para linha de log `rate_limit_metric`). Exibição via
  `php artisan rate-limit:metrics`. Sem Prometheus de propósito: leve e demonstrável localmente.
- **TrustProxies explícito:** por padrão NENHUM proxy é confiável — `X-Forwarded-For` de cliente
  direto é ignorado (spoof não escolhe balde). Atrás de LB, configure
  `TRUSTED_PROXIES=<ips/cidrs>` (ou `*` somente se o app é inalcançável fora do LB). Com
  `config:cache`, defina como variável de ambiente real — caveat documentado no bootstrap e na
  fase 6.

## Testes

```bash
php artisan test
```

Exigem Redis real (banco 15; pulados com aviso se ausente). Cobrem contrato HTTP, semântica dos
três algoritmos, invariantes de política e de resultado, failure_mode com conexão derrubada de
verdade, reidratação pós-`SCRIPT FLUSH`, comandos de operação, proxy confiável vs spoof de
`X-Forwarded-For`, logs sem vazamento de PII, métricas e cost override por rota. Testes são
sequenciais e não provam atomicidade — isso é papel exclusivo do script de provas.

**CI (Fase 7):** `.github/workflows/ci.yml` roda em cada push/PR — Redis 7 como service
container, lint, a suíte Pest inteira e as três provas de concorrência como smoke, deixando os
vereditos no log do job.

## Checklist de honestidade — o que isto ainda NÃO é

- **Não é um pacote de produção:** observabilidade é a MÍNIMA (4 contadores + logs
  estruturados — sem série temporal, tracing ou alertas), sem Nginx/edge (fora de escopo).
- **A carga multi-worker medida veio do harness, não da stack Laravel completa:** os números da
  Fase 10 provam a concorrência do algoritmo pelo caminho HTTP, mas não medem middleware,
  roteamento e boot do framework. Compose + k6 é caminho **opcional** (exige Docker); o
  portfólio fecha a fase sem ele. No Windows nativo, multi-worker HTTP pede WSL ou fica na
  evidência documentada.
- **Quota por tenant é leve, não multi-tenant de verdade:** há planos (free/pro) via mapa em
  config, mas sem billing, sem cadastro dinâmico, sem hierarquia e sem override por rota; o
  header `X-Tenant-Id` depende de um gateway confiável para ter qualquer valor de segurança; os
  dois checks não são atômicos entre si (vazamento de 1 token documentado na fase 9).
- **Métricas não são série temporal:** 4 contadores acumulativos, sem endpoint Prometheus — a
  Trilha B da Fase 11 (exposição HTTP das métricas) ficou registrada como candidata futura.
- **Redis único, sem HA:** cluster/sentinel e as consequências para EVALSHA/TIME não são
  tratados; `failure_mode` cobre indisponibilidade, não split-brain.
- **Chave por IP atrás de proxy exige `TRUSTED_PROXIES` correto:** o padrão seguro (nenhum
  proxy confiável) vira balde único atrás de LB até você configurar a lista — documentado na
  fase 6; cardinalidade de chaves sob flood distribuído é limitada pelos TTLs, não eliminada.
- **Testes e CI:** a suíte Pest e o workflow no GitHub Actions rodam com Redis real; a mecânica
  (provas, EVALSHA, NOSCRIPT, anonimização, tenant/planos) tem evidência registrada nas docs.
- **Feito, apesar do nome "futuro" em fases antigas:** `SCRIPT LOAD`/EVALSHA (Fase 4),
  `X-RateLimit-Reset` (Fase 4), `failure_mode` honrado (Fase 2).

## Documentação por fase

| Documento | Conteúdo |
|---|---|
| [docs/adr/001-rate-limiter-distribuido.md](docs/adr/001-rate-limiter-distribuido.md) | Decisão arquitetural + adendos |
| [docs/fases/fase-0-framing.md](docs/fases/fase-0-framing.md) | Contratos de produto/código, política de falha, glossário |
| [docs/fases/fase-1-race-condition.md](docs/fases/fase-1-race-condition.md) | O defeito check-then-act e a prova com números |
| [docs/fases/fase-2-token-bucket.md](docs/fases/fase-2-token-bucket.md) | Token Bucket, por que Lua, prova corrigida |
| [docs/fases/fase-3-leaky-bucket.md](docs/fases/fase-3-leaky-bucket.md) | Leaky Bucket e comparativo Token vs Leaky |
| [docs/fases/fase-4-evalsha-and-ops.md](docs/fases/fase-4-evalsha-and-ops.md) | EVAL vs EVALSHA, X-RateLimit-Reset, comandos de ops |
| [docs/fases/fase-5-portfolio-packaging.md](docs/fases/fase-5-portfolio-packaging.md) | Empacotamento, demo e checklist de honestidade |
| [docs/fases/fase-6-observability-and-hardening.md](docs/fases/fase-6-observability-and-hardening.md) | Logs sem PII, métricas mínimas, TrustProxies, limites conhecidos |
| [docs/fases/fase-7-portfolio-closure.md](docs/fases/fase-7-portfolio-closure.md) | CI com Redis service e o roteiro de avaliação em 5 minutos |
| [docs/fases/fase-8-k6-load.md](docs/fases/fase-8-k6-load.md) | Carga com k6: script, comandos e o que a medição não prova |
| [docs/fases/fase-9-tenant-quotas-and-runbook.md](docs/fases/fase-9-tenant-quotas-and-runbook.md) | Quota por tenant: desenho, dois checks vs script composto, limites |
| [docs/fases/fase-10-http-concurrency.md](docs/fases/fase-10-http-concurrency.md) | Multi-worker: topologia escolhida, controle de 1 worker e a tabela real |
| [docs/fases/fase-11-tenant-plans.md](docs/fases/fase-11-tenant-plans.md) | Planos de cota por tenant: precedência, chave estável, falha alta |
| [docs/runbook.md](docs/runbook.md) | Runbook operacional: Redis fora, spoofing, SCRIPT FLUSH, cliente bloqueado, planos |

## Estrutura do domínio

```
app/RateLimiting/
├── Contracts/       RateLimitAlgorithm, RateLimitKeyResolver,
│                    RateLimitRedisClient (porta do naive — comandos individuais),
│                    RateLimitScriptRunner (porta dos buckets — EVALSHA atômico)
├── Algorithms/      NaiveRedisRateLimiter, TokenBucketRateLimiter,
│                    LeakyBucketRateLimiter, RateLimitAlgorithmFactory
├── Redis/           LuaScript (fonte + SHA-1) e scripts/*.lua (fonte de verdade)
├── Support/         RateLimitPolicy, RateLimitResult, enums,
│                    RateLimitMetrics + KeyAnonymizer (observabilidade — Fase 6)
├── Resolvers/       DefaultKeyResolver (rate-limit:{strategy}:{identifier}:{routeName})
│                    e TenantQuotaResolver (quota compartilhada — Fase 9)
├── Infrastructure/  LaravelRedisClient, NativeRedisClient, Concerns/ExecutesEvalSha
├── Http/            AdvancedRateLimiterMiddleware (alias "rate-limit.advanced")
└── Exceptions/      falhas explícitas por categoria (política, infra, script Lua)
```

Requisitos: PHP >= 8.2 (`ext-redis`; `ext-pcntl` para as provas), Redis >= 5 (scripts usam
`TIME`), Composer. Sem banco relacional — sqlite em memória só para o boot do framework.
