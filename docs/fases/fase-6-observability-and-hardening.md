# Fase 6 — Observabilidade e endurecimento HTTP

Objetivo da fase: tornar o limitador OPERÁVEL — logs que podem ir para produção sem vazar PII,
métricas mínimas demonstráveis localmente e identidade por IP correta atrás de proxy — sem
introduzir stack externa (Prometheus/Datadog ficam de fora de propósito).

## Proxies confiáveis (TrustProxies)

A chave de limitação usa `request->ip()`. Isso tem dois modos de falha opostos:

- **Sem proxy configurado, atrás de um LB:** todas as requisições chegam com o IP do LB — o
  mundo inteiro divide UM balde. O limite "por cliente" vira limite global acidental.
- **Confiando em headers sem critério:** qualquer cliente direto envia `X-Forwarded-For: x.y.z.w`
  e escolhe o próprio balde — o rate limit fica trivialmente contornável.

Configuração adotada (explícita em `bootstrap/app.php`):

- Padrão: **nenhum proxy confiável** — `X-Forwarded-*` ignorado, IP = peer TCP. Seguro para
  exposição direta; errado atrás de LB (documente-se!).
- `TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12` (exemplo): lista de IPs/CIDRs dos proxies. O IP do
  cliente passa a vir do header, validado em cadeia pelo Symfony.
- `TRUSTED_PROXIES=*`: confia em QUALQUER peer — use somente quando a aplicação é inalcançável
  fora do LB (rede privada/security group). Com porta exposta, `*` devolve o spoofing.

Caveat operacional documentado no próprio bootstrap: o valor é lido com `env()` no bootstrap;
com `php artisan config:cache`, o `.env` não é carregado — `TRUSTED_PROXIES` precisa existir
como variável de ambiente REAL do processo (fpm/systemd/container), não apenas no `.env`.

Testes: um spoof de `X-Forwarded-For` sem proxy confiável NÃO muda a chave contada; com
`TrustProxies::at(['127.0.0.1'])` (mesmo mecanismo do bootstrap), o IP encaminhado vira a
identidade do balde — verificado pela existência das chaves no Redis.

## Logs estruturados sem PII crua

Toda linha de log do limitador passou a usar chave **pseudonimizada** pelo `KeyAnonymizer`:
o identificador (IP ou id de usuário) é trocado por HMAC-SHA256 truncado (16 hex), com a
`APP_KEY` como segredo; estratégia e rota permanecem legíveis (são operacionais, não pessoais).
O mesmo cliente gera sempre o mesmo pseudônimo — correlação entre linhas preservada para
debug — mas o valor não é reversível nem recalculável sem o segredo da aplicação. IPv6 (que tem
`:` dentro do identificador) é tratado. Chave fora do padrão é pseudonimizada por inteiro.

Níveis, por decisão consciente: **deny em `info`** (evento de interesse operacional, minoria do
tráfego); **allow em `debug`** (maioria absoluta do tráfego — em produção com `LOG_LEVEL=info`
fica silencioso, em investigação liga-se `debug`); falhas de infraestrutura em
`warning` (fail-open) e `error` (fail-closed), como já era. Todas as linhas carregam
`request_id` quando o cliente/proxy envia `X-Request-Id` — nada é inventado quando não envia.

Teste de vazamento: o teste de deny inspeciona o contexto real do log e falha se o IP cru
aparecer na chave ou se o `request_id` não for propagado.

## Métricas mínimas

Quatro contadores fechados em enum (`RateLimitMetric`): `allowed_total`, `denied_total`,
`redis_errors_total`, `evalsha_reload_total`.

Implementação: `HINCRBY` num hash único do Redis (`rate-limit:metrics:counters`) — cardinalidade
1, sem PII. Por que Redis e não memória de processo: contadores em memória PHP-FPM morrem a cada
request e são invisíveis para um processo Artisan — não seriam demonstráveis. Regra inegociável:
**métrica nunca derruba requisição** — `increment()` é best-effort; com o Redis fora, degrada
para linha de log métrico estruturada (`rate_limit_metric`), que é exatamente como
`redis_errors_total` sobrevive ao próprio incidente que o motivou.

Exposição: `php artisan rate-limit:metrics` (com `--reset` para demo/testes). Por que comando e
não endpoint interno com `APP_DEBUG`: não cria superfície HTTP nova para proteger, não depende
de flag de debug em produção e é consistente com as ferramentas de ops da Fase 4
(inspect/reset/dry-run).

`evalsha_reload_total` é contado pelo hook `reportEvalShaReload()` do trait `ExecutesEvalSha`
— no-op por padrão (o `NativeRedisClient` das provas continua sem dependências), sobrescrito no
`LaravelRedisClient` com incremento best-effort. Em operação normal fica perto de zero;
crescimento contínuo denuncia restarts/failovers/`SCRIPT FLUSH` frequentes.

## Limites conhecidos (registrados, não resolvidos)

- **Redis é SPOF:** uma instância única decide tudo; a mitigação existente é o `failure_mode`
  (open/closed) — HA/Sentinel/Cluster está fora de escopo, e split-brain não é tratado.
- **Cardinalidade de chaves:** um atacante com muitos IPs (ou IPv6) cria uma chave por
  identidade. O TTL de higiene limita a vida de cada chave (janela no naive; tempo-até-repouso
  nos buckets), então o conjunto converge para o tráfego ativo — mas um flood distribuído infla
  memória temporariamente. Mitigações reais (limite por prefixo de rede, proteção no edge) ficam
  fora de escopo.
- **`X-RateLimit-Reset` em delta (segundos), não epoch:** decisão da Fase 4 pela consistência
  com `Retry-After` e imunidade a clock skew do cliente; APIs que esperam epoch (estilo GitHub)
  precisam converter. Trade-off documentado, não defeito.
- **Métricas não são série temporal:** contadores acumulativos sem timestamps/labels; para taxa
  por minuto ou percentis, exportar para uma stack real (fora de escopo).
- **Log métrico de fallback não é idempotente com o contador:** durante indisponibilidade do
  Redis, os incrementos viram linhas de log e NÃO são replays no contador quando o Redis volta —
  os números do hash podem subestimar períodos de incidente (documentado; alternativa exigiria
  fila local, complexidade que a fase não paga).

## Critérios de aceite da Fase 6

1. TrustProxies explícito e documentado (default: nenhum; lista/`*` via `TRUSTED_PROXIES`),
   com testes de spoof e de proxy confiável.
2. Logs de allow/deny estruturados, sem PII crua (chave pseudonimizada), com `request_id`
   quando disponível — com teste de não-vazamento.
3. Métricas mínimas demonstráveis via `rate-limit:metrics`, incluindo contagem de reidratação
   EVALSHA — com testes.
4. Limites conhecidos documentados acima.
5. Seção de observabilidade no README.
