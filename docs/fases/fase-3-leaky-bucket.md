# Fase 3 — Leaky Bucket atômico via script Lua

Objetivo da fase: entregar o segundo algoritmo correto — vazão constante — atrás do mesmo
contrato `RateLimitAlgorithm`, selecionável por rota, com a mesma garantia de atomicidade da
Fase 2 e a mesma prova empírica. Fechar o comparativo Token vs Leaky que orienta qual usar.

## O que foi implementado

| Artefato | Papel |
|---|---|
| `app/RateLimiting/Redis/scripts/leaky_bucket.lua` | O algoritmo inteiro (leitura + drenagem + decisão + escrita), versionado como arquivo |
| `app/RateLimiting/Algorithms/LeakyBucketRateLimiter.php` | Casca PHP tipada sobre a mesma porta `RateLimitScriptRunner` da Fase 2 |
| `AvailableAlgorithm::LeakyBucket` + braço no `RateLimitAlgorithmFactory` | Seleção por config, global ou por rota |
| `leak_rate` em `config/rate_limiting.php` e `RateLimitPolicy` | Parâmetro de vazão, validado na construção da política |

## Semântica

- `capacity` — volume máximo do balde: quanto de trabalho pode ficar **represado** antes de negar.
- `leak_rate` — unidades drenadas por segundo: a **vazão constante de saída**.
- Cada requisição admitida ADICIONA `cost` ao nível; o nível desce sozinho a `leak_rate`/s
  (drenagem *lazy*, calculada na decisão). Nível + custo acima da capacidade = 429 com
  `retry_after = ceil(overflow / leak_rate)`.
- Estado por chave (HASH): `level` (nível atual, float) e `last_leak_ms`. Chave ausente = balde
  vazio. TTL de higiene: `ceil(level/leak_rate) + 1s` — o tempo de drenar até o vazio, quando a
  chave volta a ser dispensável.
- Consequência prática: o downstream nunca recebe mais que `leak_rate`/s em regime. O excedente
  de burst espera (429 + retry) em vez de passar de uma vez.

Atomicidade e relógio: idênticos à Fase 2 — script Lua único executado por `EVAL` (nenhum
comando intercalado entre leitura e escrita) e `TIME` do próprio Redis contra clock skew.
Justificativas completas em `docs/fases/fase-2-token-bucket.md` e no adendo do ADR 001.

## Prova de concorrência — resultado real

Mesma bateria (40 × 5 = 200 tentativas concorrentes, capacidade 50), executada em 2026-07-30,
PHP 8.4.21 (NTS), Redis 7.0.15, Linux x86_64:

```bash
php scripts/prove_race_condition.php --algorithm=leaky_bucket --leak-rate=1 \
    --processes=40 --attempts=5 --rounds=3
```

```
round 1: expected=50, obtained=50, round duration=0.228s, legit replenish margin=1
round 2: expected=50, obtained=50, round duration=0.256s, legit replenish margin=1
round 3: expected=50, obtained=50, round duration=0.227s, legit replenish margin=1
```

| Round | Expected allowed | Obtained allowed | Over-admission | Legit replenish margin |
|------:|-----------------:|-----------------:|---------------:|-----------------------:|
|     1 |               50 |               50 |       +0 (+0%) |                      +1 |
|     2 |               50 |               50 |       +0 (+0%) |                      +1 |
|     3 |               50 |               50 |       +0 (+0%) |                      +1 |

Veredito do script: **NO OVER-ADMISSION — atomic by construction, confirmed empirically.**
(A margem "+1" é a drenagem legítima durante a própria rodada — `leak_rate × duração` — impressa
em separado por honestidade; o obtido foi exatamente `capacity` em todas as rodadas.)

Regressão do naive re-executada na mesma sessão para garantir que a Fase 1 segue válida:
85, 73 e 95 admitidos para capacidade 50 (`--algorithm=naive`).

## Token Bucket vs Leaky Bucket — quando usar cada um

| Dimensão | Token Bucket (Fase 2) | Leaky Bucket (Fase 3) |
|---|---|---|
| Metáfora | Balde de tokens que RECARREGA; requisição gasta token | Balde de água que VAZA; requisição despeja volume |
| Burst | Permitido até `capacity` de uma vez (balde cheio gasta imediato) | Absorvido até `capacity`, mas LIBERADO ao ritmo de `leak_rate` |
| Vazão vista pelo downstream | Irregular: picos de até `capacity`, média `refill_rate`/s | Constante: nunca mais que `leak_rate`/s em regime |
| Parâmetros | `capacity` (burst) + `refill_rate` (tokens/s) | `capacity` (volume represado) + `leak_rate` (drenagem/s) |
| Estado ausente significa | Balde CHEIO (cliente novo tem burst inteiro) | Balde VAZIO (cliente novo não tem nada represado) |
| `Retry-After` calculado de | Deficit de tokens ÷ `refill_rate` | Overflow de volume ÷ `leak_rate` |
| Usar quando | Tráfego naturalmente em rajadas que o backend tolera: APIs de usuário, dashboards, mobile sync — pune a média, perdoa o pico | O downstream tem capacidade rígida por segundo: gateway de pagamento, fila de e-mails, integração de terceiro com SLA duro — o pico é exatamente o que não pode passar |
| Custo de UX | Melhor UX no pico (menos 429 em rajada legítima) | Mais 429 em rajada, em troca de proteção estrita do downstream |

Regra prática: proteja SEU produto com Token Bucket; proteja um TERCEIRO rígido (ou um recurso
interno de capacidade fixa) com Leaky Bucket. Os dois convivem no mesmo projeto — a escolha é
por rota, na config.

## Seleção por rota

```php
// config/rate_limiting.php
'policies' => [
    'rate-limited.ping' => [
        'capacity' => 50,
        'algorithm' => 'token_bucket', 'refill_rate' => 1.0,
        // ou: 'algorithm' => 'leaky_bucket', 'leak_rate' => 1.0,
        // ou: 'algorithm' => 'naive', 'window_seconds' => 60,  // didático — reproduz a Fase 1
    ],
],
```

## Testes desta fase

`tests/Feature/RateLimiting/LeakyBucketRateLimiterTest.php`: represamento até a capacidade com
espaço livre decrescente; negação com `retry_after ~= overflow/leak_rate`; drenagem real com o
passar do tempo (0,5 s a 5 unidades/s); contrato HTTP fim a fim com política `leaky_bucket` por
rota. `RateLimitPolicyTest` ganhou as invariantes de `leak_rate`. Aviso padrão: teste sequencial
cobre semântica; atomicidade é papel do script de prova.

## Critérios de aceite da Fase 3

1. `LeakyBucketRateLimiter` atômico via Lua versionado, atrás do contrato `RateLimitAlgorithm`.
2. Seleção por rota entre `naive | token_bucket | leaky_bucket` funcionando via config.
3. Prova de concorrência sem sobre-admissão (obtained == capacity em todas as rodadas).
4. Comparativo Token vs Leaky documentado (tabela acima) e README atualizado.
5. Testes entregues; naive e evidências das fases anteriores intactos.
