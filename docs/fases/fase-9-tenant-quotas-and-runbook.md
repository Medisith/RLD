# Fase 9 — Quotas compostas por tenant e runbook

Objetivo da fase: permitir que uma requisição consuma DOIS baldes — o do cliente individual e o
da organização (tenant) que ele representa — sem alterar em nada o comportamento de quem não
usa o recurso, e fechar a operação com um runbook curto.

## Desenho

Com a flag ligada e o header presente, cada requisição passa por dois checks sequenciais:

```
requisição
  ├─ CHECK 1: balde do CLIENTE   rate-limit:{strategy}:{identifier}:{routeName}
  │     └─ negou? -> 429 scope=client   (o balde do tenant NEM É TOCADO)
  └─ CHECK 2: balde do TENANT    rate-limit:tenant:{tenantId}:{routeName}
        └─ negou? -> 429 scope=tenant
```

Componentes: `TenantQuotaResolver` (lê a flag, sanitiza o header, monta política e chave),
`TenantQuota` (DTO do par política+chave), `RateLimitScope` (enum `client` | `tenant`, exposto no
corpo do 429 e nos logs). A política do tenant é construída pelo mesmo
`RateLimitPolicy::fromConfig()` das rotas, mesclando `rate_limiting.tenant` sobre a config
global — ou seja, herda todas as invariantes já validadas (capacidade, taxa por algoritmo,
custo) e pode usar qualquer um dos três algoritmos.

### Configuração (desligada por padrão)

```php
// config/rate_limiting.php
'tenant' => [
    'enabled' => false,          // RATE_LIMIT_TENANT_ENABLED
    'header' => 'X-Tenant-Id',   // RATE_LIMIT_TENANT_HEADER
    'capacity' => 200,           // RATE_LIMIT_TENANT_CAPACITY
    'algorithm' => 'token_bucket',
    'refill_rate' => 4.0,
    'leak_rate' => 4.0,
],
```

Com `enabled = false` o resolver devolve `null` antes de olhar qualquer header e o middleware
executa exatamente o caminho das fases anteriores — comportamento preservado, coberto por teste
explícito ("with the feature disabled the tenant header is completely ignored").

### Por que dois checks, e não um script Lua composto com 2 KEYS

A alternativa atômica existe e é tecnicamente superior num aspecto: um único script recebendo
`KEYS[1]=cliente` e `KEYS[2]=tenant`, calculando ambos os baldes e **só escrevendo se ambos
permitirem**. Isso elimina o vazamento descrito abaixo.

Ficou de fora nesta fase, com trade-off explícito:

| | Dois checks (escolhido) | Script composto |
|---|---|---|
| Atomicidade | cada check é atômico; a dupla não é | dupla atômica |
| Algoritmos | cliente e tenant podem ser diferentes | ambos presos ao mesmo script/algoritmo |
| Reuso | zero código Lua novo | terceiro script + formatos de estado combinados |
| Redis Cluster | duas chaves quaisquer | exige hash tag para mesmo slot |
| Vazamento | 1 token do cliente por request negado pelo tenant | nenhum |

A escolha privilegia composição (qualquer algoritmo de cada lado, zero Lua novo) e o escopo
"quotas leves" desta fase. O custo é conhecido, medido e pequeno — e a evolução está desenhada
acima para quem quiser cravar a atomicidade da dupla.

### Por que CLIENTE primeiro

A ordem não é arbitrária. Com dois checks não atômicos, um dos dois baldes sempre "vaza" no
caso de negação do outro; a pergunta é qual vazamento causa menos dano:

- **Cliente primeiro (escolhido):** quando o tenant nega, o cliente perde 1 token — mas aquele
  cliente ia ser negado de qualquer forma. E, crucialmente, um cliente abusivo barrado pelo
  próprio balde **nunca chega ao check do tenant**, então não consegue drenar a cota
  compartilhada da organização. Isso é exatamente o ataque que a quota de tenant existe para
  conter.
- **Tenant primeiro (rejeitado):** um único cliente ruidoso consumiria a cota do tenant inteiro
  antes de ser barrado pelo próprio limite — o raio de dano passa de um cliente para a
  organização toda.

Teste dedicado cobre isso ("a client denied by its own bucket never touches the tenant bucket":
após uma requisição permitida e várias negadas pelo cliente, o balde do tenant registra
exatamente 1 consumo).

### Headers na resposta permitida

Quando os dois baldes permitem, os headers `X-RateLimit-*` reportam o balde **mais restritivo**
(menor saldo restante), como conjunto coerente de um único balde — nunca `limit` de um com
`remaining` de outro. Sem quota de tenant, é sempre o do cliente, o que preserva o contrato das
fases anteriores byte a byte.

No 429, o corpo ganhou o campo aditivo `"scope": "client" | "tenant"` — o consumidor precisa
saber se esperou sozinho ou se a cota da organização acabou, porque a ação corretiva é
diferente (esperar vs falar com quem administra o tenant).

## Limites conhecidos

- **O header não é fronteira de confiança.** `X-Tenant-Id` vem do cliente: omiti-lo pula a quota
  de tenant e forjá-lo troca de balde. O recurso só faz sentido quando um gateway/BFF confiável
  **injeta e sobrescreve** o header (ou quando ele é derivado do token de autenticação). O
  resolver sanitiza o formato (`[A-Za-z0-9._-]{1,64}`), o que protege o keyspace contra
  cardinalidade artificial e impede `:` de quebrar o padrão de chave — mas isso é higiene, não
  autenticação. Identificador ausente ou malformado é tratado como "sem tenant", não como erro:
  rejeitar com 4xx daria falsa sensação de proteção (quem quer escapar simplesmente omite) e
  quebraria clientes durante uma migração gradual.
- **Vazamento de 1 token por negação de tenant**, descrito acima. Sob esgotamento sustentado do
  tenant, os baldes dos clientes drenam junto; o runbook documenta o reset como aceleração da
  normalização.
- **Custo:** com a flag ligada e header presente, cada requisição permitida no primeiro check
  faz uma segunda ida ao Redis. Requisições negadas pelo cliente continuam custando uma só.
- **Sem planos por tenant.** A quota é uma só, igual para todos os tenants, e a mesma para todas
  as rotas. Limites por tenant individual (billing/planos), hierarquia de organizações e
  overrides por rota estão fora do escopo — exigiriam persistência de plano e invalidação de
  cache, outro projeto.
- **Duas chaves, sem hash tag:** em Redis Cluster (fora de escopo aqui) as chaves de cliente e
  tenant podem cair em slots diferentes. Irrelevante para dois checks separados, mas é o que
  bloquearia o script composto sem uma hash tag comum.

## Runbook

Escrito em [docs/runbook.md](../runbook.md): diagnóstico em 30 segundos e cinco procedimentos —
Redis indisponível (com a decisão `failure_mode`), suspeita de spoofing de IP/tenant,
`SCRIPT FLUSH`/restart, cliente legítimo bloqueado (incluindo o caso `scope=tenant`) e cota de
tenant esgotando cedo demais.

## Testes desta fase

`tests/Feature/RateLimiting/TenantQuotaTest.php`: flag desligada ignora o header por completo
(compatibilidade); flag ligada sem header consome só o cliente; identificador malformado tratado
como ausente; dois baldes consumidos com negação no tenant (`scope=tenant`, headers do balde do
tenant); isolamento entre tenants; short-circuit preservando a cota compartilhada; headers do
balde mais restritivo.

## Critérios de aceite da Fase 9

1. Identificador de tenant opcional, documentado e desligado por padrão — feito.
2. Limite por tenant E por rota, com a escolha de desenho justificada — feito acima.
3. Testes de tenant ausente/presente/inválido e de não regressão com a flag padrão — feitos.
4. Runbook operacional curto — `docs/runbook.md`.
5. README atualizado.
