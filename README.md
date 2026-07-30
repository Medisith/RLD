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

## Rodar em 3 comandos

```bash
docker compose up -d      # sobe SÓ o Redis (ou use um Redis local seu)
./scripts/demo.sh         # roda as três provas e imprime o contraste (Windows: scripts/demo.ps1)
```

A demo não exige `composer install` — o script de prova carrega os mesmos algoritmos do
middleware por autoloader próprio. Para a aplicação HTTP completa:

```bash
cp .env.example .env && composer install && php artisan key:generate
php artisan serve --port=8000

curl -i -X POST http://localhost:8000/api/rate-limited/ping -H 'Accept: application/json'
# 200 + X-RateLimit-Limit: 50 | X-RateLimit-Remaining: 49 | X-RateLimit-Reset: 50
# esgotado o balde: 429 + Retry-After + X-RateLimit-Reset (code RATE_LIMIT_EXCEEDED)
```

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
```

## Testes

```bash
php artisan test
```

Exigem Redis real (banco 15; pulados com aviso se ausente). Cobrem contrato HTTP, semântica dos
três algoritmos, invariantes de política e de resultado, failure_mode com conexão derrubada de
verdade, reidratação pós-`SCRIPT FLUSH` e os comandos de operação. Testes são sequenciais e não
provam atomicidade — isso é papel exclusivo do script de provas.

## Checklist de honestidade — o que isto ainda NÃO é

- **Não é um pacote de produção:** sem observabilidade (métricas/tracing), sem teste de carga
  (k6 — fora de escopo por regra), sem Nginx/edge (fora de escopo), sem multi-tenant.
- **Redis único, sem HA:** cluster/sentinel e as consequências para EVALSHA/TIME não são
  tratados; `failure_mode` cobre indisponibilidade, não split-brain.
- **Chave por IP pressupõe IP real:** atrás de proxy/CDN exige trusted proxies configurado —
  não incluído.
- **Testes automatizados:** escritos, porém a execução do `php artisan test` está PENDENTE DE
  EXECUÇÃO no ambiente desta entrega (Packagist bloqueado — sem `vendor/`); rode localmente.
  Toda a mecânica Redis (provas, EVALSHA, NOSCRIPT) FOI executada de verdade e está registrada
  nas docs com números reais.
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

## Estrutura do domínio

```
app/RateLimiting/
├── Contracts/       RateLimitAlgorithm, RateLimitKeyResolver,
│                    RateLimitRedisClient (porta do naive — comandos individuais),
│                    RateLimitScriptRunner (porta dos buckets — EVALSHA atômico)
├── Algorithms/      NaiveRedisRateLimiter, TokenBucketRateLimiter,
│                    LeakyBucketRateLimiter, RateLimitAlgorithmFactory
├── Redis/           LuaScript (fonte + SHA-1) e scripts/*.lua (fonte de verdade)
├── Support/         RateLimitPolicy, RateLimitResult, enums
├── Resolvers/       DefaultKeyResolver (rate-limit:{strategy}:{identifier}:{routeName})
├── Infrastructure/  LaravelRedisClient, NativeRedisClient, Concerns/ExecutesEvalSha
├── Http/            AdvancedRateLimiterMiddleware (alias "rate-limit.advanced")
└── Exceptions/      falhas explícitas por categoria (política, infra, script Lua)
```

Requisitos: PHP >= 8.2 (`ext-redis`; `ext-pcntl` para as provas), Redis >= 5 (scripts usam
`TIME`), Composer. Sem banco relacional — sqlite em memória só para o boot do framework.
