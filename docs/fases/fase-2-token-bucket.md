# Fase 2 — Token Bucket atômico via script Lua

Objetivo da fase: corrigir, de verdade, o defeito provado na Fase 1. O `TokenBucketRateLimiter`
entra atrás do MESMO contrato `RateLimitAlgorithm` que o naive, com toda a decisão executando
como uma operação atômica dentro do Redis — e a mesma bateria de concorrência que estourou o
naive agora admite exatamente a capacidade, nem uma requisição a mais.

## O que foi implementado

| Artefato | Papel |
|---|---|
| `app/RateLimiting/Redis/scripts/token_bucket.lua` | O algoritmo inteiro (leitura + recarga + decisão + escrita), versionado como arquivo |
| `app/RateLimiting/Algorithms/TokenBucketRateLimiter.php` | Casca PHP tipada: carrega o script, envia parâmetros, valida e converte a resposta em `RateLimitResult` |
| `app/RateLimiting/Contracts/RateLimitScriptRunner.php` | Porta nova e exclusiva de `EVAL` (ver "Desenho das portas" abaixo) |
| `app/RateLimiting/Exceptions/LuaScriptFailureException.php` | Falha explícita para script ausente, erro de servidor ou resposta malformada |
| `app/RateLimiting/Algorithms/RateLimitAlgorithmFactory.php` | Mapa exaustivo `AvailableAlgorithm -> implementação`, usado pelo middleware e pelo provider |

## Semântica

- `capacity` — tamanho do balde: o **burst máximo** que um cliente consegue gastar de uma vez.
- `refill_rate` — tokens repostos por segundo: a **vazão média sustentada**. Com `capacity=50` e
  `refill_rate=1.0`, o cliente pode estourar 50 requisições imediatamente e depois sustenta
  1 req/s (~60/min).
- Estado por chave (HASH no Redis): `tokens` (saldo, float) e `last_refill_ms` (última recarga,
  em milissegundos). Chave ausente = balde cheio — cliente novo começa com o burst inteiro.
- Recarga *lazy*: calculada no momento da decisão, proporcional ao tempo decorrido, saturada em
  `capacity`. Não há job nem timer — o próprio script repõe.
- TTL de higiene: a chave expira quando o balde estaria cheio de novo
  (`ceil((capacity - tokens)/refill_rate) + 1s`) — balde cheio é indistinguível de chave ausente,
  então nada se perde e nenhuma chave fica eterna. Com taxas muito baixas o TTL cresce
  proporcionalmente (trade-off aceito e documentado).

## Por que Lua

A Fase 1 provou que check-then-act em comandos separados admite 46% a 90% acima da capacidade
sob concorrência. As alternativas foram descartadas no ADR 001 (adendo incluído): `MULTI`/`EXEC`
não resolve porque a decisão continua no PHP entre a leitura e o `EXEC`; `WATCH` + retry degrada
exatamente sob contenção; lock distribuído serializa e adiciona modos de falha próprios. Um
script Lua executa **dentro** do servidor como unidade indivisível: entre o `HMGET` e o `HSET`
do script, nenhum outro comando de nenhum outro cliente é intercalado. A janela de corrida deixa
de existir por construção — e o custo é o de um único round-trip.

**Relógio: `TIME` do Redis, não `now()` do PHP.** Passar o timestamp do PHP como argumento
reintroduziria um problema distribuído: N instâncias com relógios divergentes (clock skew)
recarregariam o mesmo balde em linhas do tempo diferentes — uma instância adiantada "recarrega no
futuro" e uma atrasada pode nem recarregar. Com `redis.call('TIME')` dentro do script, o Redis é
o único ponto de verdade também para o tempo. `TIME` em script é seguro no Redis >= 5
(replicação por efeitos) e é chamado antes de qualquer escrita, como o Redis exige.

## Desenho das portas — por que o naive continua quebrado

O `EVAL` entrou em um contrato **separado** (`RateLimitScriptRunner`), não em
`RateLimitRedisClient`. A separação é estrutural: o `NaiveRedisRateLimiter` continua enxergando
apenas comandos individuais (e continua inseguro de propósito, com a prova da Fase 1 válida),
enquanto os algoritmos atômicos enxergam apenas `EVAL`. Nenhum algoritmo consegue misturar os
dois mundos. Os adaptadores concretos (`LaravelRedisClient` para o middleware,
`NativeRedisClient` para a prova standalone) implementam as duas portas sobre a mesma conexão.

## Prova de concorrência — resultado real

Mesma bateria da Fase 1 (40 processos × 5 tentativas = 200 tentativas concorrentes contra
capacidade 50, barreira de largada via Redis), executada em 2026-07-30, PHP 8.4.21 (NTS),
Redis 7.0.15, Linux x86_64:

```bash
php scripts/prove_race_condition.php --algorithm=token_bucket --refill-rate=1 \
    --processes=40 --attempts=5 --rounds=3
```

```
round 1: expected=50, obtained=50, round duration=0.583s, legit replenish margin=1
round 2: expected=50, obtained=50, round duration=0.232s, legit replenish margin=1
round 3: expected=50, obtained=50, round duration=0.188s, legit replenish margin=1
```

| Round | Expected allowed | Obtained allowed | Over-admission | Legit replenish margin |
|------:|-----------------:|-----------------:|---------------:|-----------------------:|
|     1 |               50 |               50 |       +0 (+0%) |                      +1 |
|     2 |               50 |               50 |       +0 (+0%) |                      +1 |
|     3 |               50 |               50 |       +0 (+0%) |                      +1 |

Veredito do script: **NO OVER-ADMISSION — atomic by construction, confirmed empirically.**

Nota de honestidade estatística: enquanto uma rodada executa, o balde recarrega
(`refill_rate × duração`). O relatório imprime essa margem legítima em separado (+1 aqui, pois
as rodadas duram < 1s com taxa 1/s); "violação de atomicidade" seria apenas exceder
`capacity + margem` — o que não ocorreu. Obtido foi **exatamente** `capacity` em todas as rodadas.

### Contraste com o naive (mesma sessão, mesma bateria)

```
naive:        expected=50 -> obtained=85, 73, 95   (+46% a +90% de sobre-admissão)
token_bucket: expected=50 -> obtained=50, 50, 50   (0% — sempre)
```

O mesmo teste que condena um algoritmo aprova o outro. É a diferença entre decidir no PHP sobre
leitura obsoleta e decidir dentro do servidor em um passo indivisível.

## Resiliência (entregue junto com esta fase)

O `failure_mode` documentado na Fase 0 passou a ser honrado pelo middleware: com o Redis
indisponível (`RedisUnavailableException`), `open` deixa a requisição passar sem contagem (log de
alerta, sem headers de saldo — não há números honestos a dar) e `closed` nega com 503 +
`Retry-After`. Bug de script Lua (`LuaScriptFailureException`) nunca é absorvido pelo fail-open:
propaga e falha alto. Testes cobrem os dois modos derrubando a conexão de verdade
(porta morta + `Redis::purge`).

## Testes desta fase

`tests/Feature/RateLimiting/TokenBucketRateLimiterTest.php`: burst até a capacidade com saldo
decrescente; negação com `retry_after ~= deficit/refill_rate`; recarga real com o passar do tempo
(0,5 s a 5 tokens/s); contrato HTTP fim a fim com política `token_bucket` por rota.
`tests/Unit/RateLimiting/RateLimitPolicyTest.php` ganhou as invariantes de `refill_rate`.
Aviso mantido dos testes das fases anteriores: teste sequencial cobre SEMÂNTICA; atomicidade sob
concorrência é papel exclusivo do script de prova.

## Critérios de aceite da Fase 2

1. `TokenBucketRateLimiter` atômico via Lua versionado, atrás do contrato `RateLimitAlgorithm`.
2. Selecionável na config, global (`rate_limiting.algorithm`) e por rota (`policies.*.algorithm`).
3. Prova de concorrência sem sobre-admissão (obtained == capacity em todas as rodadas).
4. `NaiveRedisRateLimiter` intacto e prova da Fase 1 ainda reproduzível com `--algorithm=naive`
   (re-executada nesta sessão: 85/73/95 admitidos para capacidade 50).
5. Este documento e testes entregues.
