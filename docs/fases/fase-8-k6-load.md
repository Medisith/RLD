# Fase 8 — Carga reproduzível com k6

Objetivo da fase: dar uma medição de CARGA HTTP fim a fim (latência, vazão, proporção
permitidas/negadas) para os três algoritmos, com um script mínimo e reproduzível — sem virar
uma suíte de performance, que está fora do escopo do portfólio.

## O que existe

Um único script parametrizado: `k6/rate_limit_burst.js`. Dispara um burst
(`shared-iterations`: N requisições disputadas por V usuários virtuais, sem pacing) contra
`POST /api/rate-limited/ping` e conta:

| Métrica | Origem | O que diz |
|---|---|---|
| `allowed_requests` | contador customizado | quantas responderam 200 |
| `denied_requests` | contador customizado | quantas responderam 429 |
| `unexpected_responses` | contador customizado | qualquer outro status (deve ser 0) |
| `http_req_duration` | nativa do k6 | latência, com p(95) no resumo |
| `http_reqs` | nativa do k6 | vazão (req/s) |

O ALGORITMO efetivo é o que a APLICAÇÃO estiver configurada para usar — a variável `ALGORITHM`
do k6 apenas etiqueta o resultado. Por isso a política da rota passou a ler
`RATE_LIMIT_PING_ALGORITHM` (Fase 8): trocar de algoritmo entre execuções é mudar uma variável
de ambiente e reiniciar o servidor, sem editar código.

O threshold declarado (`p(95)<60000`) é frouxo de propósito: existe para o k6 destacar o p95 no
resumo, nunca para reprovar a execução contra um SLO inventado. Este projeto não tem SLO.

## Pré-requisitos

1. **k6** instalado (https://k6.io/docs/get-started/installation/).
2. **Redis** no ar: `docker compose up -d`.
3. **Aplicação instalada e servida**: `composer install && php artisan key:generate`, depois
   `php artisan serve --port=8000`.

## Como rodar

```bash
# Token Bucket (padrão)
RATE_LIMIT_PING_ALGORITHM=token_bucket php artisan serve --port=8000
k6 run -e ALGORITHM=token_bucket k6/rate_limit_burst.js

# Leaky Bucket — reinicie o serve com a nova variável
RATE_LIMIT_PING_ALGORITHM=leaky_bucket php artisan serve --port=8000
k6 run -e ALGORITHM=leaky_bucket k6/rate_limit_burst.js

# Naive (didático)
RATE_LIMIT_PING_ALGORITHM=naive php artisan serve --port=8000
k6 run -e ALGORITHM=naive k6/rate_limit_burst.js
```

Parâmetros opcionais: `-e VUS=40`, `-e ITERATIONS=200`, `-e BASE_URL=http://localhost:8000`.

Entre execuções, zere o estado do cliente para que a segunda rodada não comece com o balde já
gasto pela primeira:

```bash
php artisan rate-limit:reset "rate-limit:ip:127.0.0.1:rate-limited.ping"
php artisan rate-limit:metrics --reset   # opcional: contadores limpos por rodada
```

## Limite honesto desta medição

`php artisan serve` é single-worker: atende **uma requisição por vez**. Sob ele, o k6 mede
latência e o contrato HTTP corretamente, mas as requisições ficam serializadas — ou seja, **o
naive NÃO vai exibir sobre-admissão nesse setup**, porque a janela de corrida entre GET e
SET/INCR nunca é disputada. Isso não contradiz a Fase 1; apenas confirma que carga HTTP por um
servidor serializado não é o instrumento certo para provar corrida.

Para medir concorrência de verdade por HTTP seria preciso um servidor multi-worker (PHP-FPM com
vários workers, Octane/Swoole ou vários processos atrás de um balanceador) — fora do escopo
declarado destas fases. A prova de concorrência permanece sendo
`scripts/prove_race_condition.php`, que forka processos e ataca o algoritmo diretamente.

O que o k6 acrescenta, então: custo de latência do limitador no caminho da requisição, vazão
sustentada e a curva permitidas/negadas vista pelo CLIENTE — informações que o script de prova
não dá.

## Resultado (executado)

Rodada real em 2026-07-30, Windows 10, PHP 8.3.31 + phpredis 6.3.0, Redis 8.8 local,
k6 v2.1.0, `php artisan serve` em `127.0.0.1:8000`, política padrão da rota
(`capacity=50`, `refill_rate`/`leak_rate=1.0`), tenant desligado, chave zerada entre
algoritmos com `php artisan rate-limit:reset`. Burst: **40 VUs × 200 iterações**.
`unexpected_responses = 0` nas três rodadas (só 200/429).

| Algoritmo | VUs × iterações | allowed | denied | p(95) | req/s |
|---|---|---|---|---|---|
| naive | 40 × 200 | 50 | 150 | 8.53 s | 4.77 |
| token_bucket | 40 × 200 | 93 | 107 | 9.04 s | 4.59 |
| leaky_bucket | 40 × 200 | 92 | 108 | 8.61 s | 4.70 |

### Como ler estes números

- **p(95) ~8–9 s e ~4.6–4.8 req/s** medem sobretudo a **fila do `artisan serve`
  single-worker** com 40 VUs competindo — não o custo isolado do script Lua. Latência do
  limitador sozinha seria milissegundos; aqui o servidor serializa tudo.
- **naive = exatamente 50 allowed:** sob servidor serializado a corrida GET/SET não aparece
  (como a seção "Limite honesto" já antecipava). A prova de sobre-admissão continua sendo
  `scripts/prove_race_condition.php`.
- **token_bucket / leaky_bucket > 50 allowed:** a bateria durou ~42–44 s; com recarga/vazão
  de 1 unidade/s o saldo cresce durante o burst. 50 + ~43 s ≈ 93 — compatível com a
  semântica dos baldes, não com bug de limite. O naive não recarrega dentro da janela da
  mesma forma (contador fixo até TTL), por isso fica em 50.

Números brutos exportados pelo k6 (`--summary-export`) ficaram só no ambiente local
(`tmp_k6_results/`, não versionado); a tabela acima é o registro canônico no repositório.

## Critérios de aceite da Fase 8

1. Script k6 mínimo e parametrizado por algoritmo em `k6/` — feito.
2. Documentação de pré-requisitos, comandos e interpretação — este arquivo.
3. Seleção de algoritmo da rota por variável de ambiente, para comparar sem editar código.
4. Execução real OU pendência explícita sem números inventados — **execução real** acima.
5. Seção curta de carga no README.
