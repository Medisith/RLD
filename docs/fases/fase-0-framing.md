# Fase 0 — Framing, contratos e esqueleto padronizado

Objetivo da fase: fechar o enquadramento do problema, os contratos de produto e de código e o
esqueleto do projeto **antes** de qualquer algoritmo. Nada aqui decide permitir/negar — isso é
Fase 1 em diante.

## Contratos de produto

### Identidade do cliente e chave de limitação

Toda contagem é indexada por uma chave canônica no Redis:

```
rate-limit:{strategy}:{identifier}:{routeName}
```

- `strategy`: `user` (id do usuário autenticado), `ip` (endereço de origem) ou
  `user_or_ip` (usuário quando autenticado, senão IP). Na chave gravada, `user_or_ip`
  registra a estratégia que **prevaleceu** (`user` ou `ip`), nunca o literal `user_or_ip`.
- `identifier`: id do usuário, IP, ou `anonymous` (política `user` sem autenticação — balde
  único explícito, preferível a erro silencioso).
- `routeName`: nome da rota Laravel (ex.: `rate-limited.ping`), que também indexa a política em
  `config/rate_limiting.php`.

Exemplo real: `rate-limit:ip:203.0.113.10:rate-limited.ping`.

### Limite exemplo (política da rota de teste)

`POST /api/rate-limited/ping`: capacidade **50** consumos por janela de **60 segundos**, custo **1**
por requisição, estratégia `user_or_ip`, algoritmo `naive` (Fase 1).

### Resposta quando o limite é excedido

Status **429 Too Many Requests**, corpo JSON em inglês (código/API); comentários do projeto em português:

```json
{
    "message": "Rate limit exceeded. Try again in 42 seconds.",
    "code": "RATE_LIMIT_EXCEEDED",
    "limit": 50,
    "retry_after": 42
}
```

### Headers previstos

| Header | Quando | Significado |
|---|---|---|
| `X-RateLimit-Limit` | 200 e 429 | Capacidade total da política na janela |
| `X-RateLimit-Remaining` | 200 e 429 | Consumos restantes após esta decisão (0 quando negado) |
| `Retry-After` | 429 | Segundos até valer a pena tentar de novo |

### Política de falha (documentada, ainda não implementada)

`failure_mode` existe na config desde já, com dois valores possíveis:

- `open` — se o Redis cair, deixa a requisição passar (prioriza disponibilidade)
- `closed` — se o Redis cair, nega com 503 (prioriza proteção do backend)

Nas Fases 0 e 1 a falha de Redis **propaga** (`RedisUnavailableException`). O middleware ainda
**não** honra `failure_mode`; isso é fase futura.

## Contratos de código (fechados nesta fase)

- `RateLimitAlgorithm::attempt(string $key, RateLimitPolicy $policy, int $cost): RateLimitResult`
- `RateLimitKeyResolver::resolve(Request $request, RateLimitPolicy $policy): string`
- `RateLimitRedisClient` — apenas comandos individuais (`get`, `setWithTtl`, `increment`, `ttl`,
  `expire`, `delete`); sem EVAL/Lua nesta fase
- DTOs: `RateLimitPolicy`, `RateLimitResult`
- Enums: `KeyStrategy`, `AvailableAlgorithm` (somente `Naive` nas Fases 0 e 1)
- Middleware alias: `rate-limit.advanced`
- Config: `config/rate_limiting.php`

## Critério de pronto da Fase 0

1. ADR 001 e este documento
2. Config tipada e políticas por rota
3. Contratos, DTOs e wiring completos; `POST /api/rate-limited/ping` atravessa o middleware e chega ao
   controller (decisão de limite é Fase 1)
4. Zero uso do rate limiter nativo do Laravel

## Glossário

- **Política de limitação:** conjunto validado de parâmetros (capacidade, janela, custo,
  estratégia, algoritmo) aplicado a uma rota.
- **Chave de limitação:** identificador Redis que agrupa o saldo de um cliente em uma rota.
- **Janela fixa:** intervalo de tempo com TTL no Redis; dentro dela o contador sobe até a
  capacidade; ao expirar, o saldo renasce inteiro.
- **Custo:** quantas unidades de capacidade uma requisição consome (padrão 1).
- **Check-then-act:** ler, decidir no PHP e escrever em comandos separados (vulnerável a race).
- **Fail-open / fail-closed (`failure_mode` open/closed):** o que fazer quando a infraestrutura de
  contagem falha.
