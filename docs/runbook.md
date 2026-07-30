# Runbook operacional — rate limiter

Procedimentos curtos para os incidentes que este componente realmente produz. Todos os comandos
rodam na raiz do projeto, com o `.env` da instância afetada.

## Diagnóstico em 30 segundos

```bash
php artisan rate-limit:metrics                       # allowed/denied/redis_errors/evalsha_reload
php artisan rate-limit:dry-run rate-limited.ping     # política efetiva da rota agora
redis-cli ping                                       # o Redis responde?
```

Leitura rápida: `denied_total` subindo com `allowed_total` estagnado = alguém batendo no limite;
`redis_errors_total` > 0 = incidente de infraestrutura em curso (ver abaixo);
`evalsha_reload_total` subindo continuamente = o Redis está reiniciando ou alguém roda
`SCRIPT FLUSH` periodicamente.

## Incidente 1 — Redis indisponível

**Sintoma:** `redis_errors_total` crescendo; com `failure_mode=closed`, respostas 503
(`code: RATE_LIMITER_UNAVAILABLE`); com `open`, tráfego passando sem contagem e log
`warning` repetido.

1. Confirme o alcance: `redis-cli ping` a partir da máquina da aplicação (não da sua).
2. Decida a postura enquanto o Redis não volta:
   - Proteger o backend (padrão): mantenha `RATE_LIMIT_FAILURE_MODE=closed`.
   - Priorizar disponibilidade: `RATE_LIMIT_FAILURE_MODE=open` e reinicie os workers. **Ciente
     de que o backend fica sem limite nenhum enquanto isso durar.**
3. Restabeleça o Redis. Nenhuma ação de recuperação de estado é necessária: baldes ausentes
   equivalem a clientes em repouso, e os scripts Lua se reidratam sozinhos (Fase 4).
4. Depois: `php artisan rate-limit:metrics --reset` para começar a próxima janela de observação
   limpa, se for útil.

Limite honesto: durante a indisponibilidade os incrementos de métrica viram linhas de log
(`rate_limit_metric`) e **não** são replayed no contador quando o Redis volta — os números do
período do incidente ficam subestimados.

## Incidente 2 — Suspeita de spoofing de IP / cliente trocando de balde

**Sintoma:** um cliente parece nunca atingir o limite; muitas chaves distintas para o que
deveria ser um único cliente.

1. Verifique a configuração de proxy: `TRUSTED_PROXIES` deve conter **apenas** os IPs/CIDRs dos
   seus proxies. Se estiver `*` **e** a aplicação for alcançável fora do LB, qualquer cliente
   escolhe o próprio balde via `X-Forwarded-For` — corrija para a lista fechada.
2. Se estiver vazio e a aplicação estiver atrás de LB, o efeito é o oposto (todo mundo num
   balde só) — configure a lista.
3. Lembre que, com `php artisan config:cache`, `TRUSTED_PROXIES` precisa existir como variável
   de ambiente real do processo; um valor só no `.env` não é lido.
4. Reinicie os workers após alterar e confirme com uma requisição de teste:
   `php artisan rate-limit:inspect "rate-limit:ip:<ip-esperado>:rate-limited.ping"`.

O mesmo raciocínio vale para `X-Tenant-Id` (Fase 9): o header **não** é fronteira de confiança —
só tem valor se um gateway confiável o injeta e sobrescreve.

## Incidente 3 — `SCRIPT FLUSH` / restart do Redis

**Sintoma:** `evalsha_reload_total` sobe.

Nenhuma ação necessária: o `RateLimitScriptRunner` detecta `NOSCRIPT`, recarrega o `.lua`
versionado com `SCRIPT LOAD`, confere o SHA e repete a chamada — transparente para a requisição
em andamento. Só investigue se o contador crescer **continuamente**, o que indica restarts em
laço ou um job externo rodando `SCRIPT FLUSH`.

## Incidente 4 — Cliente legítimo bloqueado

```bash
# 1. Qual é o estado do balde dele?
php artisan rate-limit:inspect "rate-limit:ip:203.0.113.10:rate-limited.ping"

# 2. Destrave (volta ao repouso: balde cheio / vazio / janela nova)
php artisan rate-limit:reset "rate-limit:ip:203.0.113.10:rate-limited.ping"
```

Se o 429 trouxe `"scope": "tenant"`, o balde esgotado é o compartilhado — resete a chave do
tenant, não a do cliente:

```bash
php artisan rate-limit:inspect "rate-limit:tenant:acme:rate-limited.ping"
php artisan rate-limit:reset   "rate-limit:tenant:acme:rate-limited.ping"
```

Reset é seguro por construção: chave ausente é um estado válido para os três algoritmos.

## Incidente 5 — Cota de tenant esgotando cedo demais

1. Confirme a política vigente: `php artisan rate-limit:dry-run rate-limited.ping`.
2. Verifique se `RATE_LIMIT_TENANT_CAPACITY` e a taxa (`REFILL_RATE`/`LEAK_RATE`) fazem sentido
   para o número de clientes ativos daquele tenant — a cota é compartilhada, então N clientes
   consomem N vezes mais rápido.
3. Lembre do vazamento documentado (Fase 9): quando o tenant nega, o token já consumido do
   cliente não volta. Sob esgotamento sustentado do tenant, os baldes dos clientes também
   drenam — o que faz o problema parecer maior do que é. Após corrigir a cota, um
   `rate-limit:reset` nas chaves de cliente afetadas acelera a normalização.

## Como inspecionar chaves e métricas

```bash
php artisan rate-limit:metrics                 # contadores agregados (--reset zera)
php artisan rate-limit:inspect "<chave>"       # estado bruto: tipo, campos, TTL (somente leitura)
php artisan rate-limit:dry-run <rota>          # política efetiva sem consumir saldo
redis-cli --scan --pattern 'rate-limit:*' | head   # visão geral do keyspace (use com cuidado)
```

Nos logs, a chave aparece **pseudonimizada** (HMAC truncado com a `APP_KEY`) — o mesmo cliente
sempre gera o mesmo pseudônimo, o que permite correlacionar linhas sem expor IP ou id de
usuário. Para achar a chave real de um cliente conhecido, monte-a pelo padrão
`rate-limit:{strategy}:{identifier}:{routeName}` em vez de tentar reverter o log.
