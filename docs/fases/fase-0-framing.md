# Fase 0 — Framing, contratos e esqueleto padronizado

Objetivo da fase: fechar o enquadramento do problema, os contratos de produto e de código e o
esqueleto do projeto **antes** de qualquer algoritmo. Nada aqui decide permitir/negar — isso é
Fase 1 em diante.

## Contratos de produto

### Identidade do cliente e chave de limitação

Toda contagem é indexada por uma chave canônica no Redis:

```
limitacao:{estrategia}:{identificador}:{nomeRota}
```

- `estrategia`: `usuario` (id do usuário autenticado), `ip` (endereço de origem) ou
  `usuario_ou_ip` (usuário quando autenticado, senão IP). Na chave gravada, `usuario_ou_ip`
  registra a estratégia que **prevaleceu** (`usuario` ou `ip`), nunca o literal `usuario_ou_ip`.
- `identificador`: id do usuário, IP, ou `anonimo` (política `usuario` sem autenticação — balde
  único explícito, preferível a erro silencioso).
- `nomeRota`: nome da rota Laravel (ex.: `limitado.ping`), que também indexa a política em
  `config/limitacao_requisicoes.php`.

Exemplo real: `limitacao:ip:203.0.113.10:limitado.ping`.

### Limite exemplo (política da rota de teste)

`POST /api/limitado/ping`: capacidade **50** consumos por janela de **60 segundos**, custo **1**
por requisição, estratégia `usuario_ou_ip`, algoritmo `ingenuo` (Fase 1).

### Resposta quando o limite é excedido

Status **429 Too Many Requests**, corpo JSON com mensagens de negócio em português:

```json
{
    "mensagem": "Limite de requisições excedido. Tente novamente em 42 segundos.",
    "codigo": "LIMITE_REQUISICOES_EXCEDIDO",
    "limite": 50,
    "tentar_novamente_em": 42
}
```

### Headers previstos

| Header | Quando | Significado |
|---|---|---|
| `X-RateLimit-Limit` | 200 e 429 | Capacidade total da política na janela |
| `X-RateLimit-Remaining` | 200 e 429 | Consumos restantes após esta decisão (0 quando negado) |
| `Retry-After` | somente 429 | Segundos até valer a pena tentar de novo (mínimo 1) |

Reservado para fase futura: `X-RateLimit-Reset` (instante de renovação da janela).

## Política de falha (fail-open / fail-closed) — apenas documentada

`modo_falha` existe na config desde já, com dois valores possíveis:

- `aberto` (fail-open): Redis indisponível → a requisição **passa** sem contagem. Prioriza
  disponibilidade do produto; aceita perder proteção durante o incidente.
- `fechado` (fail-closed): Redis indisponível → resposta **503**. Prioriza proteção do backend;
  aceita negar tráfego legítimo durante o incidente.

**Estado nas Fases 0 e 1:** nenhum dos dois modos é honrado. Falha de Redis lança
`ExcecaoRedisIndisponivel`, que propaga e derruba a requisição de forma explícita e visível.
Decisão consciente: implementar fail-open/fail-closed antes de ter o algoritmo correto mascararia
erros de infraestrutura justamente na fase cujo objetivo é expor comportamento incorreto.

## Contratos de código (fechados nesta fase)

- `AlgoritmoLimitacao::tentar(string $chave, PoliticaLimitacao $politica, int $custo): ResultadoLimitacao`
- `ResolvedorChaveLimitacao::resolver(Request $request, PoliticaLimitacao $politica): string`
- `ClienteRedisLimitacao` — porta de acesso ao Redis restrita a comandos individuais (GET, SET,
  INCRBY, TTL, EXPIRE, DEL). A ausência de operações compostas é proposital: é o que torna o
  algoritmo da Fase 1 estruturalmente incapaz de ser atômico.
- DTOs imutáveis (`readonly`): `PoliticaLimitacao` (valida invariantes na construção) e
  `ResultadoLimitacao` (`permitido`, `restante`, `limite`, `tentarNovamenteEm`, `algoritmo`,
  `chave`).
- Wiring: alias de middleware `limitacao.avancada` → `MiddlewareLimitacaoAvancada` (declarado em
  `bootstrap/app.php`); bindings em `LimitacaoRequisicoesServiceProvider`.

## Critérios de aceite da Fase 0

1. ADR 001 e este documento escritos e honestos sobre o que está incompleto.
2. `config/limitacao_requisicoes.php` com chaves em português e políticas por rota.
3. Contratos, DTOs e wiring completos; `POST /api/limitado/ping` atravessa o middleware e chega ao
   `PingController`.
4. Zero uso do rate limiter nativo do Laravel (`throttle`, `ThrottleRequests`, facade
   `RateLimiter`) em qualquer arquivo do projeto.

## Glossário

- **Limitador de requisições (rate limiter):** mecanismo que restringe quantas requisições um
  cliente pode fazer num intervalo.
- **Chave de limitação:** string canônica que identifica "quem × qual rota" e indexa o contador no
  Redis.
- **Política de limitação:** conjunto validado de parâmetros (capacidade, janela, custo,
  estratégia, algoritmo) aplicado a uma rota.
- **Capacidade:** total de consumos permitidos dentro de uma janela.
- **Janela fixa:** intervalo de duração constante (aqui, o TTL da chave) dentro do qual a
  capacidade vale; ao expirar, o saldo renasce inteiro.
- **Custo:** quantas unidades de capacidade uma requisição consome (padrão 1).
- **Check-then-act:** padrão "ler, decidir, escrever" em passos separados; sob concorrência, a
  decisão usa leitura obsoleta — é o defeito estudado na Fase 1.
- **Race condition (condição de corrida):** resultado incorreto que depende da intercalação
  temporal de processos concorrentes.
- **Atomicidade:** propriedade de uma operação executar como um todo indivisível, sem estado
  intermediário observável por terceiros.
- **Fail-open / fail-closed (modo_falha aberto/fechado):** o que fazer quando a infraestrutura de
  decisão está fora — deixar passar ou negar.
- **429 Too Many Requests:** status HTTP padrão para limite excedido.
