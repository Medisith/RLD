# ADR 001 — Rate limiter distribuído customizado sobre Redis

- Status: aceito
- Data: 2026-07-29
- Fases cobertas por este ADR: 0 (framing e contratos) e 1 (limitador ingênuo com prova de race condition)

## Contexto

A aplicação (exercício de portfólio) precisa limitar requisições por cliente em um cenário
distribuído: várias instâncias de PHP-FPM/servidores atendendo o mesmo tráfego, onde qualquer
instância pode receber qualquer requisição do mesmo cliente. O limite precisa valer para o
cliente como um todo, não por instância.

Restrição deliberada do exercício: é proibido usar o rate limiter nativo do Laravel em qualquer
forma — `RateLimiter`, `ThrottleRequests`, `Illuminate\Support\Facades\RateLimiter` ou o alias de
middleware `throttle`. O objetivo é dominar o problema de contagem concorrente distribuída, não
consumir uma solução pronta.

O problema central que este projeto existe para expor e depois resolver: decidir "ainda cabe mais
uma requisição?" exige ler um contador, comparar com a capacity e escrever o novo consumo. Se
esses três passos não formarem uma operação atômica, dois ou mais processos concorrentes leem o
mesmo estado e todos se admitem — o limite deixa de ser um limite exatamente na hora em que é mais
necessário (pico de tráfego).

## Decisão

1. O estado de contagem vive em um **Redis compartilhado** por todas as instâncias da aplicação.
   Redis é o ponto único de verdade do saldo de cada chave de limitação; a aplicação permanece
   stateless.
2. A **atomicidade da decisão será obtida com script Lua** (`EVAL`) executado no servidor Redis,
   em fase futura: leitura, comparação e escrita dentro de um único passo indivisível do lado do
   servidor. Nas Fases 0 e 1 o script Lua **não** é implementado — a Fase 1 entrega de propósito a
   versão errada (check-then-act em comandos separados) e prova empiricamente por que ela falha.
3. A arquitetura de domínio é fechada em contratos desde a Fase 0: `RateLimitAlgorithm` (decisão),
   `RateLimitKeyResolver` (identidade do cliente) e `RateLimitRedisClient` (porta de acesso ao
   Redis restrita a comandos individuais nesta fase). Trocar o algoritmo ingênuo pela versão
   atômica não altera middleware, rotas ou config.
4. A chave de limitação segue o padrão canônico
   `rate-limit:{strategy}:{identifier}:{routeName}` — por exemplo,
   `rate-limit:ip:203.0.113.10:rate-limited.ping`.

## Alternativas rejeitadas

**Rate limiter nativo do Laravel (`throttle`/`RateLimiter`).** Rejeitado por regra do exercício, e
também por objetivo de aprendizado: o valor deste portfólio está em construir e provar o
mecanismo, não em configurá-lo. Nenhum código desta entrega pode referenciar o mecanismo nativo.

**Cache local por instância (array, APCu, arquivo).** Rejeitado por incorreção estrutural no
cenário distribuído: cada instância enxergaria apenas sua fração do tráfego. Com N instâncias e
limite L, o cliente obtém até N × L requisições — o limite global não existe. Não é um problema de
ajuste, é a ferramenta errada.

**`MULTI`/`EXEC` ingênuo.** Rejeitado porque transação no Redis não resolve check-then-act: os
comandos enfileirados no `MULTI` são executados atomicamente, mas a **decisão** (comparar contador
com capacity) acontece no PHP **entre** a leitura e o `EXEC`, sobre valor possivelmente
obsoleto. Seria necessário `WATCH` + laço de retry (custo e complexidade sob contenção alta,
exatamente o cenário de pico) para chegar perto do que uma única execução de script Lua entrega de
forma direta e barata.

**Lock distribuído como primeira opção (SETNX/Redlock).** Rejeitado como abordagem padrão:
serializa todas as decisões da chave num gargalo, adiciona latência de aquisição/liberação em cada
requisição e traz os modos de falha clássicos de lock (expiração sob GC/pausa, liberação indevida,
fencing). Para um contador de rate limiting, a operação inteira cabe em um script Lua atômico sem
lock nenhum. Locks ficam reservados para casos futuros onde a seção crítica seja inevitavelmente
maior que um script.

## Consequências

Positivas: limite global correto por construção (após a fase do Lua); aplicação stateless e
horizontalmente escalável; contratos estáveis desde já — middleware, config e rotas não mudam
quando o algoritmo evoluir; a versão ingênua da Fase 1 vira artefato didático permanente com prova
empírica registrada.

Negativas e riscos assumidos: o Redis torna-se dependência crítica do caminho de requisição — cai
o Redis, cai a capacity de decidir (a política de falha `failure_mode` aberto/fechado está
definida em config e **documentada**, com implementação adiada — nas Fases 0 e 1 a falha é
explícita); toda decisão custa uma ida ao Redis (mitigável no futuro; medição fica para a fase de
métricas); janela fixa tem o vício de borda conhecido (rajada no fim de uma janela + início da
seguinte pode dobrar o pico instantâneo) — aceito nesta fase, tratado quando o Token Bucket
substituir o contador simples.

## Escopo desta entrega versus futuro

Entregue agora (Fases 0 e 1): ADR e docs de framing; config `rate_limiting.php` com
políticas por rota; contratos e DTOs; resolvedor de chave; `NaiveRedisRateLimiter` (check-then-act
**intencionalmente vulnerável**, rotulado como tal no código); `AdvancedRateLimiterMiddleware` com
resposta 429 JSON e headers `X-RateLimit-Limit`, `X-RateLimit-Remaining` e `Retry-After`; rota de
teste `POST /api/rate-limited/ping`; prova empírica de race condition com resultados reais
(`docs/fases/fase-1-race-condition.md`); testes sequenciais mínimos.

Explicitamente fora desta entrega (fases futuras): script Lua e Token Bucket atômico; Leaky
Bucket; implementação do `failure_mode` aberto/fechado; headers adicionais (`X-RateLimit-Reset`);
métricas e carga com k6; Docker Compose e Nginx; qualquer refatoração fora do domínio do
limitador.

## Adendo (2026-07-30) — Fases 2 e 3 entregues

As seções acima permanecem como registro histórico da decisão original; nada foi apagado. Este
adendo registra o que mudou de status com a entrega das Fases 2 e 3.

**Decisão principal confirmada na prática.** A atomicidade via script Lua, prevista no item 2 da
Decisão, foi implementada (`app/RateLimiting/Redis/scripts/token_bucket.lua` e
`leaky_bucket.lua`, executados por `EVAL`) e validada empiricamente: 200 tentativas concorrentes
contra capacidade 50 admitiram exatamente 50 em todas as rodadas, nos dois algoritmos — contra
73–95 admissões do naive na mesma bateria (evidência em `docs/fases/fase-2-token-bucket.md` e
`fase-3-leaky-bucket.md`).

**Nova porta de infraestrutura.** O `EVAL` entrou em um contrato SEPARADO
(`RateLimitScriptRunner`) em vez de ampliar `RateLimitRedisClient`. Racional: manter o contrato
do naive estruturalmente incapaz de atomicidade composta preserva o artefato didático da Fase 1 e
impede, por construção, um algoritmo "híbrido" meio atômico. Os adaptadores concretos
(`LaravelRedisClient`, `NativeRedisClient`) implementam as duas portas sobre a mesma conexão.

**Relógio.** Os scripts usam `TIME` do próprio Redis (não o relógio do PHP): N instâncias de
aplicação com clock skew enxergam a mesma linha do tempo. `TIME` em script é seguro no
Redis >= 5 (replicação por efeitos) e é chamado antes de qualquer escrita.

**Consequência revisada — failure_mode.** A consequência negativa "cai o Redis, cai a capacidade
de decidir" foi mitigada: o middleware passou a honrar `failure_mode` (`open` = passa sem
contagem; `closed` = 503). Bug de script Lua nunca é absorvido pelo fail-open — propaga como todo
defeito de código deve propagar.

**Seleção por rota.** `AvailableAlgorithm` fechou com três casos (`naive`, `token_bucket`,
`leaky_bucket`), selecionáveis globalmente e por política de rota via
`RateLimitAlgorithmFactory` (match exaustivo). O naive permanece no projeto exclusivamente como
artefato didático comparativo — a advertência de não usar em produção continua em vigor.
