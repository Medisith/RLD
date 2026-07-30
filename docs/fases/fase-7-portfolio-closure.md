# Fase 7 — CI e fechamento do portfólio

Objetivo da fase: fechar o ciclo — CI reproduzível com Redis real, roteiro de 5 minutos para
quem avalia o projeto, e o registro final honesto do que existe e do que não existe.

## CI (GitHub Actions)

`.github/workflows/ci.yml` — um job, deliberadamente mínimo:

1. Redis 7 como **service container** (mesma imagem `redis:7-alpine` do `docker-compose.yml`),
   com healthcheck; os testes falam com `127.0.0.1:6379` e usam o banco 15 (`phpunit.xml`).
2. PHP 8.4 via `shivammathur/setup-php` com as extensões `redis` (aplicação) e `pcntl` (provas).
3. `composer validate` + `composer install` + lint (`php -l` em app/config/routes/tests/scripts).
4. `cp .env.example .env` + `php artisan key:generate` — o `.env.example` é o contrato de
   ambiente também no CI (Redis local, sqlite em memória, algoritmo padrão `token_bucket`).
5. `php artisan test` — a suíte Pest inteira contra o Redis do service.
6. **Provas de concorrência como smoke**: os três algoritmos rodam no runner e deixam os
   vereditos no log do job — o avaliador vê o naive sobre-admitindo e os buckets cravando a
   capacidade em cada execução de CI, não só nos números registrados nas docs.

Nota de honestidade: o workflow foi escrito e validado estruturalmente, mas **não há como
executá-lo neste ambiente** (execução acontece no GitHub após o push do usuário). A suíte
`php artisan test` também segue PENDENTE DE EXECUÇÃO localmente (Packagist bloqueado no
sandbox); as provas de concorrência e toda a mecânica Redis foram executadas de verdade aqui.

## O roteiro de 5 minutos (para quem avalia)

1. **(~90 s)** Leia o `README.md` do topo até a tabela de prova — problema, números do naive,
   números dos buckets.
2. **(~2 min)** Rode a demo: `docker compose up -d && ./scripts/demo.sh` — os três vereditos
   saem na sua tela, medidos na sua máquina (Windows: `scripts/demo.ps1`, que delega ao WSL).
3. **(~1 min)** Abra lado a lado `app/RateLimiting/Algorithms/NaiveRedisRateLimiter.php` (os
   comentários marcam a janela de corrida) e `app/RateLimiting/Redis/scripts/token_bucket.lua`
   (a mesma lógica, atômica no servidor). Esse contraste É o projeto.
4. **(~30 s)** Confira `docs/fases/fase-1-race-condition.md` vs `fase-2-token-bucket.md`: mesma
   bateria, +46% a +90% de sobre-admissão contra exatamente 0%.
5. **(~30 s)** Se quiser ver operação: `php artisan rate-limit:dry-run rate-limited.ping`,
   `rate-limit:inspect`, `rate-limit:metrics` (exigem `composer install`).

## Estado final por fase

| Fase | Entrega | Evidência |
|---|---|---|
| 0 | Framing, contratos, ADR | docs/fases/fase-0, ADR 001 |
| 1 | Naive check-then-act + prova da race | 73–95 admitidas de 50 (real) |
| 2 | Token Bucket atômico (Lua) + failure_mode | exatamente 50 de 200 (real) |
| 3 | Leaky Bucket atômico + seleção por rota | exatamente 50 de 200 (real) |
| 4 | EVALSHA + reload NOSCRIPT, X-RateLimit-Reset, ops commands | SCRIPT FLUSH transparente (real) |
| 5 | Compose Redis, demo, README de portfólio | demo executada (real) |
| 6 | TrustProxies, logs sem PII, métricas mínimas | anonymizer validado (real); testes escritos |
| 7 | CI com Redis service, cost override testado, este fechamento | workflow pronto; execução no GitHub |

## Pendências e fora de escopo — registro final

PENDENTE DE EXECUÇÃO (ambiente sem Packagist): `composer install`, `php artisan test`, primeira
rodada do workflow no GitHub. Nada disso tem resultado inventado em lugar nenhum do repositório.

Fora de escopo por regra, confirmado até o fim: k6 completo, Nginx/edge, cluster/HA Redis,
multi-tenant hierárquico, uso do rate limiter nativo do Laravel (zero ocorrências fora de
comentários que documentam a proibição) e remoção do naive (permanece como artefato didático).
