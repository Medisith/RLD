# Fase 11 — Planos de cota por tenant

Trilha escolhida: **A (planos de tenant)**. A Fase 9 entregou o segundo balde, compartilhado
pela organização, mas com uma cota única para todo mundo — o que não é como cota de tenant
funciona em produto nenhum. Esta fase fecha o gancho: tenants diferentes, limites diferentes,
sem billing real.

(A Trilha B — endpoint Prometheus — foi descartada aqui porque as métricas da Fase 6 já são
consultáveis via `rate-limit:metrics`, e expor um endpoint HTTP novo criaria superfície para
proteger em troca de pouco. Fica registrada como candidata natural para uma fase futura.)

## Desenho

```
X-Tenant-Id: acme          (identidade — vem do gateway)
        │
        ├─ assignments['acme'] = 'pro'    ← decisão do SERVIDOR
        │      (ausente? -> default_plan)
        │
        └─ plans['pro'] = [capacity: 600, refill_rate: 10.0]
                 merge sobre a base do tenant, merge sobre a config global
                          │
                          └─ balde rate-limit:tenant:acme:{routeName}
```

Configuração (`config/rate_limiting.php`, seção `tenant`):

```php
'default_plan' => 'free',
'plans' => [
    'free' => ['capacity' => 60,  'algorithm' => 'token_bucket', 'refill_rate' => 1.0],
    'pro'  => ['capacity' => 600, 'algorithm' => 'token_bucket', 'refill_rate' => 10.0],
],
'assignments' => [
    // 'acme' => 'pro',
],
```

Precedência de valores: **plano > base do tenant > config global**. Um plano só precisa declarar
o que muda; o resto é herdado. Toda a validação de invariantes continua sendo a do
`RateLimitPolicy::fromConfig()` — capacidade positiva, taxa compatível com o algoritmo, custo
menor ou igual à capacidade. Um plano pode inclusive usar outro algoritmo do que a base.

## Três decisões que valem explicação

**O cliente diz QUEM é; o servidor decide QUANTO ele pode.** Não existe header de plano, de
propósito. Se o plano viesse do cliente, o recurso inteiro seria decorativo — bastaria mandar
`X-Tenant-Plan: pro`. A identidade continua com o caveat da Fase 9 (o header só vale se um
gateway confiável o injeta e sobrescreve), mas o dano de um header forjado fica limitado: o
atacante no máximo cai no balde de *outro tenant existente*, nunca em uma cota maior por conta
própria. Há teste cobrindo exatamente isso.

**A chave não inclui o plano.** O balde é identificado pelo tenant
(`rate-limit:tenant:{tenantId}:{routeName}`), não pelo par tenant+plano. Se o plano fizesse
parte da chave, um upgrade no meio da janela criaria um balde novo e zerado — reset de cota de
graça, e um downgrade seria igualmente exploitável. Com a chave estável, mudar de plano ajusta
capacidade e taxa sobre o saldo que já existe. Também há teste para isso.

**Plano inexistente falha alto.** Se `assignments` aponta para um plano que não está em `plans`,
o resolver lança `InvalidRateLimitPolicyException` em vez de cair silenciosamente na cota-base.
Um erro de digitação em config não pode virar "esse tenant ganhou o limite errado e ninguém
percebeu" — mesma postura do resto do projeto.

## Retrocompatibilidade

Duas camadas de compatibilidade, ambas testadas:

1. `tenant.enabled = false` (padrão do projeto) — nada muda; o header é ignorado e nenhuma chave
   de tenant é criada, mesmo com planos declarados.
2. `plans` vazio — comportamento da Fase 9 preservado: a cota-base do tenant vale para todos.

## Operação

O `rate-limit:dry-run` ganhou `--tenant`, resolvendo a quota composta pelo **mesmo**
`TenantQuotaResolver` do middleware — dry-run e produção não podem divergir na resolução de
plano:

```bash
php artisan rate-limit:dry-run rate-limited.ping --tenant=acme
# mostra: tenant id, plano resolvido, algoritmo, capacidade, taxa, chave e se ela existe agora
```

O nome do plano também entra no **log** do 429 de escopo `tenant` (não no corpo da resposta —
é informação de operação, não de API). Sem ele, um 429 de tenant não diferencia "cota pequena
porque o plano é free" de "cota pequena porque a config está errada".

## Limites conhecidos

- **Sem billing, sem cadastro.** `assignments` é um mapa estático em config: mudar o plano de um
  tenant exige deploy (ou `config:cache` refeito). Uma fonte real (banco, painel) substituiria
  esse array sem mudar o resto do desenho — a resolução de plano já está isolada num único
  método —, mas traria invalidação de cache e consistência, que é outro projeto.
- **Sem hierarquia.** Um nível só: tenant. Organização → sub-organização → projeto está fora.
- **Sem override por rota.** O plano vale para todas as rotas; um plano que libera mais em
  `/reports` e menos em `/search` exigiria uma matriz plano × rota — deliberadamente não feito.
- **Herdados da Fase 9 e ainda válidos:** o header não é fronteira de confiança; os dois checks
  (cliente e tenant) não são atômicos entre si, com vazamento de até 1 token por requisição
  negada pelo tenant; cada requisição permitida no primeiro check paga uma segunda ida ao Redis.
- **Cardinalidade:** um plano generoso com muitos tenants ativos multiplica chaves; os TTLs de
  higiene limitam a vida de cada uma, mas não a taxa de criação.

## Testes desta fase

`tests/Feature/RateLimiting/TenantPlanTest.php`: tenant atribuído recebe a capacidade do plano;
tenant sem atribuição cai no padrão; dois planos convivendo com limites distintos na mesma
janela; header de plano forjado é ignorado; a chave não muda quando o plano muda; plano
inexistente lança exceção; flag desligada não altera nada; `plans` vazio preserva a Fase 9.

## Critérios de aceite da Fase 11

1. Planos de cota por tenant via config/mapa, sem billing real — feito.
2. Tenant resolvido a partir do header injetado pelo gateway, com o aviso mantido — feito.
3. Testes de planos diferentes → capacidades diferentes, e flag desligada → comportamento
   idêntico — feitos.
4. Documentação desta fase e runbook atualizado — este arquivo e `docs/runbook.md`.
