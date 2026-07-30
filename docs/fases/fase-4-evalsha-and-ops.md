# Fase 4 — EVALSHA, X-RateLimit-Reset e ferramentas de operação

Objetivo da fase: tirar o custo operacional do caminho atômico (banda de rede por decisão),
completar o contrato de headers prometido na Fase 0 e dar ao operador ferramentas de
inspeção/reset/simulação via Artisan — sem tocar na semântica de nenhum algoritmo.

## EVAL vs EVALSHA — o trade-off

Até a Fase 3, cada decisão enviava o FONTE Lua inteiro (~4 KB) via `EVAL`. Correto, mas
desperdício: o Redis compila e cacheia scripts por SHA-1, então repetir o fonte a cada request
paga banda e parsing por nada. A Fase 4 muda o fluxo para:

1. `LuaScript::fromFile()` carrega o `.lua` versionado UMA vez por processo e pré-computa o
   SHA-1 (o "cache do SHA em memória de processo" — vive na instância memoizada do algoritmo).
2. Toda decisão executa `EVALSHA sha1 ...` — 40 bytes em vez de ~4 KB, um único round-trip.
3. Se o servidor responder `NOSCRIPT` (restart, failover, `SCRIPT FLUSH`): o adaptador executa
   `SCRIPT LOAD` com o fonte do arquivo versionado, **verifica que o SHA devolvido pelo servidor
   é idêntico ao local** (divergência = `LuaScriptFailureException::shaMismatch`, para tudo) e
   repete o `EVALSHA` uma única vez. `NOSCRIPT` persistente após reload também falha alto
   (`noScriptAfterReload`) — nunca retry em loop.

A rotina delicada vive em UM lugar (`Infrastructure/Concerns/ExecutesEvalSha`), compartilhada
pelos dois adaptadores (`LaravelRedisClient` e `NativeRedisClient`). Os arquivos `.lua` em
`app/RateLimiting/Redis/scripts/` continuam sendo a única fonte de verdade — nenhum Lua embutido
em string PHP, nenhum passo manual de deploy: a reidratação é automática e transparente.

Custos honestos do EVALSHA: o caminho frio custa 2 round-trips extras (uma vez por
restart/flush); o comportamento do phpredis (retorna `false` + `getLastError()` em vez de lançar)
exige o tratamento explícito que o trait concentra. Nada muda para o naive — ele nem passa por
essa porta.

### Evidência real (executada em 2026-07-30, PHP 8.4.21, Redis 7.0.15)

Prova de concorrência com `SCRIPT FLUSH` imediatamente antes (garantindo caminho frio na
primeira decisão) — mesma bateria das fases anteriores:

```
=== Concurrency proof — token_bucket (EVALSHA) ===
round 1: expected=50, obtained=50, round duration=0.302s, legit replenish margin=1
round 2: expected=50, obtained=50, round duration=0.186s, legit replenish margin=1
Verdict: NO OVER-ADMISSION — atomic by construction, confirmed empirically.
```

Reidratação transparente no meio da operação (estado do balde preservado):

```
1a decisao (script carregado):    allowed=true, remaining=4, resetAfter=2
SCRIPT FLUSH executado (simula restart/failover)
2a decisao (pos-flush, reidratada TRANSPARENTEMENTE): allowed=true, remaining=3, resetAfter=4
script registrado no servidor apos reidratacao: true
```

O `SCRIPT FLUSH` apaga o cache de scripts, não as chaves: a contagem continuou de onde estava
(remaining 4 -> 3), sem erro visível para a requisição.

## X-RateLimit-Reset

Header novo, presente no **200 e no 429**, fechando a tabela de headers da Fase 0:

- Valor: **segundos (delta)** até o estado da chave voltar ao repouso — janela expirar (naive),
  balde encher (token_bucket) ou balde drenar por completo (leaky_bucket).
- Por que delta e não epoch: consistência com `Retry-After` (também delta) e imunidade a clock
  skew do relógio do CLIENTE — o cliente soma ao próprio relógio, sem depender de sincronia.
- Distinção semântica por algoritmo: `Retry-After` responde "quando UMA requisição volta a
  caber"; `X-RateLimit-Reset` responde "quando o estado volta ao repouso TOTAL". No naive os
  dois coincidem (expiração da janela); nos buckets `Reset >= Retry-After` — invariante
  garantida na fábrica `RateLimitResult::denied()`.
- Implementação: os scripts Lua passaram a devolver um 4º inteiro (`reset_after`), derivado do
  MESMO cálculo que já dimensionava o TTL de higiene — uma única definição de "repouso" para
  TTL e para o cliente. No naive, o TTL lido para o reparo de chave órfã alimenta o header
  (leitura informativa, não decisória — comentado no código).

## Comandos Artisan de operação

Três comandos em `app/Console/Commands/` (auto-descobertos pelo Laravel), todos sem vazar
segredos — imprimem apenas o conteúdo das chaves de limitação:

```bash
# estado bruto de uma chave (somente leitura): tipo, campos, TTL, interpretação
php artisan rate-limit:inspect "rate-limit:ip:203.0.113.10:rate-limited.ping"

# zera uma chave (cliente volta ao repouso — balde cheio/vazio, janela nova)
php artisan rate-limit:reset "rate-limit:ip:203.0.113.10:rate-limited.ping"

# simula a resolução de política de uma rota SEM consumir saldo
php artisan rate-limit:dry-run rate-limited.ping --identifier=203.0.113.10
```

`inspect` reconhece os três formatos de estado (contador string do naive; hash
`tokens/last_refill_ms` do token_bucket; hash `level/last_leak_ms` do leaky_bucket) e trata
chave ausente como estado de repouso, não erro. `dry-run` usa exatamente o mesmo
`RateLimitPolicy::fromConfig()` do middleware — config quebrada falha aqui igual falharia em
produção, o que o torna um verificador barato de configuração. Decisão de arquitetura
registrada nos cabeçalhos: comandos de ops falam com a conexão Redis do framework diretamente;
as portas do domínio (`RateLimitRedisClient`/`RateLimitScriptRunner`) seguem fechadas ao caminho
de decisão e NÃO ganharam comandos de introspecção.

Nota de honestidade: os formatos de saída acima foram validados por leitura de código e testes
escritos; a execução real dos comandos via `php artisan` está PENDENTE DE EXECUÇÃO neste
ambiente (Packagist bloqueado — sem `vendor/`). A mecânica Redis subjacente (EVALSHA, tipos de
chave, TTLs) foi toda validada com Redis real.

## Testes desta fase

`EvalShaReloadTest`: decisão sobrevive a `SCRIPT FLUSH` real de forma transparente com estado
preservado; SHA local registrado no servidor após reidratação. `RateLimitCommandsTest`: os três
comandos, incluindo dry-run que não consome saldo e falha alto com config inválida.
`RateLimitResultTest`: invariantes do `resetAfter` (saneamento e `reset >= retry`).
Middleware/bucket tests: `X-RateLimit-Reset` no 200 e no 429, coerente com `Retry-After`.

## Critérios de aceite da Fase 4

1. EVALSHA em uso em toda decisão atômica, com reidratação NOSCRIPT automática e verificação de
   SHA — provado com `SCRIPT FLUSH` real.
2. `X-RateLimit-Reset` no 200 e no 429, consistente com `Retry-After` e com a semântica de cada
   algoritmo.
3. `rate-limit:inspect`, `rate-limit:reset` e `rate-limit:dry-run` implementados e testados.
4. naive/token/leaky intactos (provas re-executadas nesta sessão).
5. Este documento e a seção operacional do README.
