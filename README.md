# Rate Limiter Distribuído — Portfólio (Fases 0 a 3)

Rate limiter distribuído customizado em Laravel 12, construído **sem** o rate limiter nativo do
framework (nenhum uso de `RateLimiter`, `ThrottleRequests`, facade `RateLimiter` ou middleware
`throttle`). Estado compartilhado em Redis; estrutura e identificadores em inglês; comentários em português.

**Estado atual:** três algoritmos convivem atrás do mesmo contrato `RateLimitAlgorithm`,
selecionáveis por config (global e por rota):

| Algoritmo | Fase | Status | Prova empírica (200 tentativas concorrentes, capacidade 50) |
|---|---|---|---|
| `naive` | 1 | **Didático — NÃO usar em produção.** Check-then-act sem atomicidade | 73–95 admitidas (+46% a +90% de sobre-admissão) |
| `token_bucket` | 2 | Correto. Burst + recarga contínua, atômico via Lua | exatamente 50 em todas as rodadas |
| `leaky_bucket` | 3 | Correto. Vazão constante, atômico via Lua | exatamente 50 em todas as rodadas |

## Documentação

| Documento | Conteúdo |
|---|---|
| [docs/adr/001-rate-limiter-distribuido.md](docs/adr/001-rate-limiter-distribuido.md) | Decisão arquitetural + adendo das Fases 2 e 3 |
| [docs/fases/fase-0-framing.md](docs/fases/fase-0-framing.md) | Contratos de produto e de código, política de falha, glossário |
| [docs/fases/fase-1-race-condition.md](docs/fases/fase-1-race-condition.md) | O defeito check-then-act, como reproduzir e os resultados reais medidos |
| [docs/fases/fase-2-token-bucket.md](docs/fases/fase-2-token-bucket.md) | Semântica do Token Bucket, por que Lua, prova corrigida, contraste com o naive |
| [docs/fases/fase-3-leaky-bucket.md](docs/fases/fase-3-leaky-bucket.md) | Semântica do Leaky Bucket, prova e comparativo Token vs Leaky (quando usar cada um) |

## Estrutura do domínio

```
app/RateLimiting/
├── Contracts/       RateLimitAlgorithm, RateLimitKeyResolver,
│                    RateLimitRedisClient (comandos individuais — porta do naive),
│                    RateLimitScriptRunner (EVAL atômico — porta dos buckets)
├── Support/         RateLimitPolicy, RateLimitResult, enums (KeyStrategy,
│                    AvailableAlgorithm, FailureMode)
├── Algorithms/      NaiveRedisRateLimiter (INSEGURO de propósito — Fase 1),
│                    TokenBucketRateLimiter (Fase 2), LeakyBucketRateLimiter (Fase 3),
│                    RateLimitAlgorithmFactory (seleção por política)
├── Redis/scripts/   token_bucket.lua, leaky_bucket.lua (versionados, executados por EVAL)
├── Resolvers/       DefaultKeyResolver (chave rate-limit:{strategy}:{identifier}:{routeName})
├── Infrastructure/  LaravelRedisClient (produção) e NativeRedisClient (provas standalone)
├── Http/            AdvancedRateLimiterMiddleware (alias "rate-limit.advanced")
└── Exceptions/      RateLimitException, InvalidRateLimitPolicyException,
                     RedisUnavailableException, LuaScriptFailureException
```

Config de negócio: `config/rate_limiting.php`. Rota de teste: `POST /api/rate-limited/ping`
(política padrão: `token_bucket`, capacity 50, refill_rate 1.0/s).

## Escolhendo o algoritmo

Global (padrão para rotas sem política própria) — `.env` ou `config/rate_limiting.php`:

```bash
RATE_LIMIT_ALGORITHM=token_bucket   # naive | token_bucket | leaky_bucket
RATE_LIMIT_CAPACITY=50
RATE_LIMIT_REFILL_RATE=1.0          # token_bucket: tokens/segundo
RATE_LIMIT_LEAK_RATE=1.0            # leaky_bucket: drenagem/segundo
RATE_LIMIT_FAILURE_MODE=closed      # open | closed (honrado pelo middleware)
```

Por rota — `config/rate_limiting.php`:

```php
'policies' => [
    'rate-limited.ping' => [
        'capacity' => 50,
        'algorithm' => 'token_bucket', 'refill_rate' => 1.0,
        // ou: 'algorithm' => 'leaky_bucket', 'leak_rate' => 1.0,
        // ou: 'algorithm' => 'naive', 'window_seconds' => 60,  // didático — reproduz a Fase 1
    ],
],
```

Resumo do comparativo (tabela completa na fase 3): Token Bucket deixa o burst passar e limita a
média — bom para proteger o SEU produto com boa UX; Leaky Bucket nivela a saída em `leak_rate`/s
— bom para proteger downstream rígido (gateway de pagamento, integração com SLA duro).

## Requisitos

PHP >= 8.2 com extensões `redis` (obrigatória) e `pcntl` (para as provas), Composer, Redis 6+
acessível (os scripts Lua usam `TIME` no servidor; qualquer Redis >= 5 atende). Sem banco
relacional: o sqlite em memória só satisfaz o boot do framework.

## Instalação

```bash
cp .env.example .env        # ajuste REDIS_HOST/REDIS_PORT se preciso
composer install
php artisan key:generate
```

Suba um Redis local (qualquer forma serve; exemplo sem persistência):

```bash
redis-server --daemonize yes --port 6379 --save '' --appendonly no
```

## Validar manualmente uma requisição

```bash
php artisan serve --port=8000

curl -i -X POST http://localhost:8000/api/rate-limited/ping -H 'Accept: application/json'
# HTTP/1.1 200 — body {"message":"pong",...}
# X-RateLimit-Limit: 50
# X-RateLimit-Remaining: 49
```

Esgotado o balde, a resposta vira `429` com corpo JSON (`code: RATE_LIMIT_EXCEEDED`) e headers
`X-RateLimit-Limit`, `X-RateLimit-Remaining: 0` e `Retry-After` calculado da taxa da política.
Com o Redis fora do ar: `failure_mode=open` deixa passar sem contagem; `closed` responde 503
(`code: RATE_LIMITER_UNAVAILABLE`).

## Provas de concorrência (naive vs token vs leaky)

Não exigem a aplicação de pé nem `vendor/` — o script carrega os mesmos algoritmos do middleware
via autoloader próprio e dispara processos concorrentes contra o Redis:

```bash
# Fase 1 — o defeito: espere sobre-admissão (+46% a +90% nas execuções registradas)
php scripts/prove_race_condition.php --algorithm=naive --processes=40 --attempts=5 --rounds=3

# Fase 2 — a correção: espere exatamente capacity admitidas (50)
php scripts/prove_race_condition.php --algorithm=token_bucket --refill-rate=1 --processes=40 --attempts=5 --rounds=3

# Fase 3 — vazão constante, mesma atomicidade: espere exatamente capacity admitidas (50)
php scripts/prove_race_condition.php --algorithm=leaky_bucket --leak-rate=1 --processes=40 --attempts=5 --rounds=3
```

Variante fim a fim via HTTP (exige `composer install` e `php artisan serve`): adicione
`--mode=http --url=http://localhost:8000/api/rate-limited/ping` — o algoritmo, nesse modo, é o
que a config define para a rota. Resultados registrados e leitura dos números:
docs/fases/fase-1, fase-2 e fase-3.

## Testes

```bash
php artisan test
```

Os testes de feature exigem Redis real (banco 15) e são pulados com aviso quando ele não está
disponível. Cobrem: contrato HTTP (200/429/headers), semântica dos três algoritmos (burst,
recarga, represamento, drenagem), invariantes de política e os dois modos de `failure_mode`
(derrubando a conexão de verdade). Atenção: testes são sequenciais e **não** provam atomicidade
sob concorrência — isso é papel exclusivo do script de provas.

## Escopo fechado desta entrega

Fora de escopo, de propósito, até aqui: métricas e carga com k6; Docker Compose; Nginx; headers
adicionais (`X-RateLimit-Reset`); `EVALSHA`/`SCRIPT LOAD` como otimização de banda (hoje cada
decisão envia o fonte do script via `EVAL` — correto, porém otimizável em fase futura).
