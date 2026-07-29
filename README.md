# Rate Limiter Distribuído — Portfólio (Fases 0 e 1)

Rate limiter distribuído customizado em Laravel 12, construído **sem** o rate limiter nativo do
framework (nenhum uso de `RateLimiter`, `ThrottleRequests`, facade `RateLimiter` ou middleware
`throttle`). Estado compartilhado em Redis; estrutura e identificadores em inglês; comentários em português.

**Estado atual — leia antes de qualquer coisa:** esta entrega cobre as Fases 0 (framing,
contratos, ADR) e 1 (limitador ingênuo check-then-act). O algoritmo desta fase é
**intencionalmente vulnerável a race condition** e existe para falhar — a prova empírica, com
números reais, está em [docs/fases/fase-1-race-condition.md](docs/fases/fase-1-race-condition.md).
A versão correta (Token Bucket atômico via script Lua) é fase futura. **Não use o
`NaiveRedisRateLimiter` em produção.**

## Documentação

| Documento | Conteúdo |
|---|---|
| [docs/adr/001-rate-limiter-distribuido.md](docs/adr/001-rate-limiter-distribuido.md) | Decisão arquitetural: Redis compartilhado, Lua no futuro, alternativas rejeitadas |
| [docs/fases/fase-0-framing.md](docs/fases/fase-0-framing.md) | Contratos de produto e de código, política de falha, glossário |
| [docs/fases/fase-1-race-condition.md](docs/fases/fase-1-race-condition.md) | O defeito check-then-act, como reproduzir e os resultados reais medidos |

## Estrutura do domínio

```
app/RateLimiting/
├── Contracts/       RateLimitAlgorithm, RateLimitKeyResolver, RateLimitRedisClient
├── Support/         RateLimitPolicy, RateLimitResult, enums (KeyStrategy, AvailableAlgorithm)
├── Algorithms/      NaiveRedisRateLimiter (PROPOSITALMENTE INSEGURO — Fase 1)
├── Resolvers/       DefaultKeyResolver (chave rate-limit:{strategy}:{identifier}:{routeName})
├── Infrastructure/  LaravelRedisClient (produção) e NativeRedisClient (prova de race)
├── Http/            AdvancedRateLimiterMiddleware (alias "rate-limit.advanced")
└── Exceptions/      RateLimitException, InvalidRateLimitPolicyException, RedisUnavailableException
```

Config de negócio: `config/rate_limiting.php` (identifiers in English; comments in Portuguese). Rota de teste:
`POST /api/rate-limited/ping` (política: 50 requisições / 60 s / custo 1).

## Requisitos

PHP >= 8.2 com extensões `redis` (obrigatória) e `pcntl` (para a prova de race), Composer, Redis
6+ acessível. Sem banco relacional: o sqlite em memória só satisfaz o boot do framework.

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

Após a 50ª requisição na mesma janela, a resposta vira `429` com corpo JSON e
headers `X-RateLimit-Limit`, `X-RateLimit-Remaining: 0` e `Retry-After`.

## Prova da race condition (Fase 1)

Não exige a aplicação de pé nem `vendor/` — o script carrega o mesmo algoritmo do middleware via
autoloader próprio e dispara processos concorrentes contra o Redis:

```bash
php scripts/prove_race_condition.php --processes=40 --attempts=5 --rounds=3
```

Resultado esperado: "obtained allowed" acima da capacidade (na execução registrada: 86–90
admitidos com limite 50). Detalhes, variante HTTP e leitura dos números:
[docs/fases/fase-1-race-condition.md](docs/fases/fase-1-race-condition.md).

## Testes

```bash
php artisan test
```

Os testes de feature exigem Redis real (banco 15) e são pulados com aviso quando ele não está
disponível. Atenção: os testes são sequenciais e **não** provam a race condition — isso é papel
exclusivo do script acima (limite documentado no próprio arquivo de teste).

## Escopo fechado desta entrega

Fora de escopo, de propósito, nesta fase: script Lua/`EVAL`, Token Bucket, Leaky Bucket,
implementação do `failure_mode` (open/closed — apenas documentado), métricas/k6, Docker, Nginx.
