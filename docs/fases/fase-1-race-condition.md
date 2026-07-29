# Fase 1 — Limitador ingênuo e prova empírica da race condition

Objetivo da fase: implementar o limitador **errado de propósito** (check-then-act em comandos
Redis separados), colocá-lo atrás do contrato definitivo `AlgoritmoLimitacao` e **provar com
números reais** que ele admite mais requisições do que a capacidade permite. Esta implementação
existe para falhar. Ela será substituída pelo Token Bucket atômico via script Lua em fase futura,
sem alterar middleware, config ou rotas.

## O que foi implementado

- `LimitadorIngenuoRedis`: contador em janela fixa com o ciclo `GET` → decisão no PHP →
  `SET`/`INCRBY` (comandos separados). Cada ponto vulnerável está comentado no próprio código.
- `MiddlewareLimitacaoAvancada`: resolve política e chave, consulta o algoritmo; negado → 429 JSON
  com mensagens em português + `X-RateLimit-Limit`, `X-RateLimit-Remaining` e `Retry-After`;
  permitido → segue o pipeline e anexa os headers informativos.
- Política de teste: capacidade 50, janela/TTL 60 s, custo 1 (rota `limitado.ping`).
- `scripts/provar_race_condition.php`: prova empírica em dois modos (algoritmo e http).

## Por que check-then-act falha

A decisão é tomada no PHP sobre um valor lido em um comando anterior. Entre a leitura e a
escrita existe uma janela em que outros processos leem o MESMO valor:

```
tempo →
Processo A: GET contador=49 ....... decide (49+1 <= 50: cabe) ....... INCR → 50  (admitido)
Processo B: .... GET contador=49 .. decide (49+1 <= 50: cabe) .......... INCR → 51  (admitido!)
Processo C: ...... GET contador=49  decide (49+1 <= 50: cabe) ............ INCR → 52  (admitido!)
```

Os três leram 49, os três concluíram "ainda cabe", os três foram admitidos. O limite era 50 e o
contador chegou a 52 — e ninguém violou nenhum comando do Redis: cada `GET` e cada `INCRBY`,
isolado, é atômico. O defeito está em o **conjunto** leitura+decisão+escrita não ser atômico.
Nenhuma reordenação de comandos individuais corrige isso.

Há um segundo defeito, ainda mais destrutivo, no ramo de "primeira requisição da janela": quando
dois processos leem `null` ao mesmo tempo, ambos executam `SET valor=1 EX 60`. O segundo `SET`
**sobrescreve** o contador do primeiro (consumo perdido) e **reinicia o TTL** (janela alongada).
Sob rajada, isso zera contagem repetidamente — é por isso que, nos resultados abaixo, o contador
final no Redis é **menor** que o total de requisições realmente admitidas.

## Como reproduzir

Pré-requisito: Redis acessível (padrão `127.0.0.1:6379`) e PHP com as extensões `redis` e
`pcntl`. O modo algoritmo **não** exige `composer install` nem a aplicação de pé — ele carrega o
mesmo `LimitadorIngenuoRedis` do middleware por um autoloader mínimo.

```bash
# subir um Redis local descartável
redis-server --daemonize yes --port 6379 --save '' --appendonly no

# prova direta no algoritmo: 40 processos x 5 tentativas = 200 tentativas
# concorrentes contra capacidade 50, 3 rodadas
php scripts/provar_race_condition.php --processos=40 --tentativas=5 --rodadas=3
```

Variante fim a fim via HTTP (exige `composer install` e a aplicação servida):

```bash
php artisan serve --port=8000
php scripts/provar_race_condition.php --modo=http \
    --url=http://localhost:8000/api/limitado/ping --rodadas=1
```

No modo http, entre rodadas o script aguarda a janela expirar (ou limpe a chave manualmente com
`redis-cli DEL "limitacao:ip:127.0.0.1:limitado.ping"`).

## Resultado obtido (execução real)

Ambiente da execução registrada: PHP 8.4.21 (NTS), Redis 7.0.15, Linux x86_64, Redis local na
mesma máquina (latência mínima — cenário **conservador**: com latência de rede real a janela de
corrida cresce e o excesso tende a piorar). Comando executado:

```bash
php scripts/provar_race_condition.php --processos=40 --tentativas=5 --rodadas=3
```

Saída relevante (números reais, não simulados):

```
modo=algoritmo | processos=40 | tentativas/processo=5 | total de tentativas=200
política: capacidade=50, janela=60s, custo=1
permitidos esperados por rodada (o correto): 50

rodada 1: esperados=50, obtidos=86, contador final no Redis=64
rodada 2: esperados=50, obtidos=89, contador final no Redis=68
rodada 3: esperados=50, obtidos=90, contador final no Redis=72
```

| Rodada | Permitidos esperados | Permitidos obtidos | Excesso admitido |
|-------:|---------------------:|-------------------:|-----------------:|
|      1 |                   50 |                 86 |       +36 (+72%) |
|      2 |                   50 |                 89 |       +39 (+78%) |
|      3 |                   50 |                 90 |       +40 (+80%) |

Veredito do script: **RACE CONDITION DEMONSTRADA**.

Leitura dos números, sintoma a sintoma:

1. **Excesso de admissão:** o "limite 50" admitiu 86–90 requisições (72% a 80% acima do
   contratado). Um backend dimensionado para 50 req/min recebeu quase o dobro exatamente no
   cenário (rajada concorrente) em que o limitador mais importava.
2. **Contagem perdida:** o contador final (64–72) é menor que o total admitido (86–90). A
   diferença são consumos apagados pelos `SET` concorrentes do ramo "primeira requisição" — o
   estado no Redis nem sequer registra o estouro que aconteceu.

Observação metodológica: concorrência é probabilística; os números variam por execução e por
máquina. O que é estável é o fenômeno — obtidos > esperados sempre que há concorrência real na
borda da capacidade. Se uma execução não exibir excesso, aumente `--processos`/`--tentativas`.

## Aviso e próximo passo

`LimitadorIngenuoRedis` está rotulado no código com aviso de projeto e **não deve ser usado em
produção**. A correção definitiva — mover leitura+decisão+escrita para dentro de um script Lua
executado atomicamente no Redis (Token Bucket) — é escopo da próxima fase e substituirá o
algoritmo atrás do mesmo contrato, sem tocar no middleware.

## Testes automatizados desta fase

`tests/Feature/LimitacaoRequisicoes/MiddlewareLimitacaoAvancadaTest.php` cobre o comportamento
**sequencial**: abaixo do limite responde 200 com headers corretos; estourada a capacidade,
responde 429 com corpo e headers do contrato. Importante e explícito no próprio teste: **teste
sequencial não prova nem refuta a race condition** — uma requisição por vez nunca abre a janela de
corrida. A prova de concorrência é exclusivamente o script desta fase. Os testes exigem Redis
disponível e são pulados com aviso claro quando não há conexão.

## Critérios de aceite da Fase 1

1. Algoritmo ingênuo, middleware e rota funcionando em conjunto.
2. Script de prova + instruções de reprodução + resultados reais registrados neste documento.
3. Testes sequenciais mínimos presentes.
4. Nenhum script Lua, nenhum `Redis::eval`, nenhum uso do rate limiter nativo do Laravel.
