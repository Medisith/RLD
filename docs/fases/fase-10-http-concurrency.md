# Fase 10 — Concorrência HTTP de verdade (multi-worker)

Objetivo da fase: fechar o buraco declarado na Fase 8. Lá, o k6 rodou contra `php artisan serve`
(single-worker) e o naive **não** sobre-admitiu — as requisições eram serializadas pelo próprio
servidor. Esta fase serve a aplicação com vários workers e mede de novo, isolando o número de
workers como a única variável.

## Caminho canônico (sem Docker): harness multi-worker

A evidência desta fase **não depende de Docker**. O caminho padrão é o harness HTTP + Redis
local (ou qualquer Redis acessível).

`scripts/http_harness.php` expõe os **mesmos algoritmos do domínio** por HTTP, servido pelo
servidor embutido do PHP com `PHP_CLI_SERVER_WORKERS=N` (N processos forkados de verdade).
Roda com PHP + Redis: sem `composer install`, sem Docker, sem k6.

`scripts/http_concurrency_compare.sh` orquestra a bateria completa (controle de 1 worker +
N workers para os três algoritmos) usando `prove_race_condition.php --mode=http` como gerador de
carga — que nesta fase passou a reportar também **p95 e vazão**.

```bash
./scripts/http_concurrency_compare.sh 8 3 50    # 8 workers, 3 rodadas, capacidade 50
```

**Plataforma:** `PHP_CLI_SERVER_WORKERS` / fork exigem Linux, macOS ou WSL. No Windows nativo o
harness multi-worker não sobe — use WSL com Redis na máquina (ou no WSL) ou fique com a tabela
já registrada abaixo. Docker **não** é requisito para fechar a fase.

O que o harness **não** é: não é a aplicação Laravel. Não passa por middleware, rotas,
TrustProxies, métricas ou logs. Ele isola a variável em estudo — a decisão do algoritmo sob
requisições HTTP paralelas.

## Caminho opcional: PHP-FPM + Nginx via Compose

Só se você **quiser** medir a stack Laravel completa com k6 e tiver Docker. Não é necessário
para o aceite da fase.

| Opção | Por quê / por que não |
|---|---|
| **PHP-FPM + Nginx (opcional)** | Workers explícitos (`pm.max_children`, `pm = static`); topologia comum em produção. Perfil Compose `http` — sem `--profile http`, `docker compose up -d` continua subindo **só Redis**. |
| Octane/Swoole | Runtime próprio; muda o que está sendo medido. |
| FrankenPHP | Mesmo raciocínio; ganho nenhum para a pergunta desta fase. |

Arquivos: `docker/php/Dockerfile`, `docker/php/www.conf`, `docker/nginx/default.conf`, perfil
`http` no `docker-compose.yml`.

```bash
# opcional — exige Docker Desktop / daemon
RATE_LIMIT_PING_ALGORITHM=naive docker compose --profile http up -d --build
k6 run -e ALGORITHM=naive k6/rate_limit_burst.js
```

`pm = static` de propósito; nginx sem `limit_req`; `TRUSTED_PROXIES=*` no serviço `app` para o
IP do cliente de carga não virar o IP do container do nginx (armadilha da Fase 6).

## Resultado (executado)

Rodada real em 2026-07-30, Linux x86_64, PHP 8.4.21 (NTS) + phpredis, Redis 7.0.15 local,
harness com `PHP_CLI_SERVER_WORKERS`, carga `prove_race_condition.php --mode=http`
(40 processos × 5 tentativas = **200 requisições**), capacidade **50**, `refill_rate` e
`leak_rate` = 1.0/s, chave zerada entre rodadas. `transport failures = 0` em todas as rodadas
(só 200 e 429).

| Algoritmo | Workers | allowed (esperado 50) | denied | p95 | vazão |
|---|---:|---:|---:|---:|---:|
| naive (controle) | 1 | 50 / 50 / 50 | 150 / 150 / 150 | 1.128 / 1.094 / 1.089 s | 173.7 / 178.4 / 173.8 req/s |
| **naive** | **8** | **60 / 60 / 54** | 140 / 140 / 146 | 1.065 / 1.057 / 1.059 s | 185.1 / 185.9 / 186.0 req/s |
| token_bucket | 8 | 50 / 50 / 50 | 150 / 150 / 150 | 1.066 / 1.109 / 1.077 s | 183.7 / 177.6 / 182.9 req/s |
| leaky_bucket | 8 | 50 / 50 / 50 | 150 / 150 / 150 | 1.059 / 1.061 / 1.063 s | 185.8 / 185.7 / 184.1 req/s |

**Critério da fase atendido:** o naive sobre-admitiu por HTTP multi-worker (+20%, +20%, +8%);
token_bucket e leaky_bucket cravaram a capacidade em todas as rodadas.

### Por que o controle de 1 worker importa

Entre a primeira linha e a segunda, **só o número de workers muda** — mesmo código, mesmo
Redis, mesma carga, mesma máquina, mesmos segundos. Com 1 worker o naive acerta 50 três vezes;
com 8 ele passa do limite três vezes. Isso transforma "o naive tem uma corrida" de afirmação em
observação controlada, e explica a Fase 8 sem contradizê-la: lá o servidor é que serializava.

### Contraste com as fases anteriores

| Instrumento | O que mede | naive |
|---|---|---|
| `prove_race_condition.php --mode=algorithm` (Fase 1) | processos forkados batendo direto no algoritmo | sobre-admite (73–95 de 50) |
| k6 sobre `artisan serve` (Fase 8) | latência e contrato HTTP, servidor serializado | **não** sobre-admite (50) |
| HTTP multi-worker (Fase 10) | concorrência real pelo caminho HTTP | **sobre-admite** (54–60 de 50) |

A sobre-admissão da Fase 1 é maior (73–95) que a desta fase (54–60) porque lá 40 processos
disputam sem nenhum intermediário; aqui a fila do servidor HTTP e o custo por requisição reduzem
a sobreposição da janela GET→SET. Menor, mas presente — e é a presença que importa: um limite que
falha 8% das vezes já não é limite.

Latência (~1.06–1.13 s de p95) e vazão (~174–186 req/s) medem sobretudo a fila do servidor
embutido com 200 requisições simultâneas, não o custo do script Lua. Repare que os números de
p95 e vazão são praticamente idênticos entre os três algoritmos: **o custo do limitador não é o
gargalo** — o EVALSHA não aparece na latência frente ao enfileiramento HTTP.

## Limites conhecidos

- Os números acima vêm do **harness**, não da stack Laravel completa. Eles provam a
  concorrência do algoritmo pelo caminho HTTP; não medem middleware, roteamento e boot do
  framework. Compose + k6 é **opcional** e fica de fora enquanto o portfólio roda sem Docker.
- O servidor embutido do PHP não é servidor de produção; ele serve aqui como gerador de
  paralelismo controlado, não como referência de desempenho.
- `PHP_CLI_SERVER_WORKERS` só existe em plataformas POSIX. No Windows nativo: WSL + Redis
  local, ou use a evidência já registrada nesta doc.

## Critérios de aceite da Fase 10

1. Forma reproduzível de servir com ≥ 2 workers, justificada — **harness sem Docker** (canônico);
   Compose FPM/Nginx documentado como opcional.
2. Mesma bateria (40 processos × 5 = 200 req) contra naive e token_bucket no harness — feita,
   com leaky e rodada de controle de 1 worker.
3. Documentação com tabela real e contraste com as Fases 1 e 8 — acima.
4. naive sobre-admite por HTTP multi-worker; token_bucket não — **confirmado** (harness).
