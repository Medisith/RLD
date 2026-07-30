# Fase 5 — Empacotamento de portfólio e demo reproduzível

Objetivo da fase: transformar o projeto num artefato de portfólio que um avaliador consegue
entender em ~90 segundos e reproduzir em ~3 comandos, sem inflar escopo — nada de containerizar
a aplicação, nada de k6, nada de Nginx.

## O que compõe o empacotamento

| Artefato | Papel |
|---|---|
| `docker-compose.yml` | SÓ o Redis (redis:7-alpine, sem persistência, healthcheck). O PHP roda no host — decisão explícita, comentada no próprio arquivo |
| `scripts/demo.sh` | Demo completa em uma execução: garante Redis (sobe o Compose se preciso), roda as três provas (naive / token_bucket / leaky_bucket) e aponta o contraste. Não fabrica números — imprime o que mediu |
| `scripts/demo.ps1` | Windows: delega ao WSL quando disponível (demo completa); sem WSL, explica as alternativas honestamente (pcntl não existe no PHP Windows nativo) em vez de fingir que rodou |
| `README.md` | Reescrito para leitura em ~90 segundos: problema → prova naive → solução token/leaky → como rodar → operação → checklist de honestidade |

## A demo

```bash
docker compose up -d      # opcional, se não houver Redis local
./scripts/demo.sh         # [processos] [tentativas] [rodadas] — padrão 40 5 2
```

Fluxo interno: valida `php` + extensões (`redis`, `pcntl`); testa o Redis com um ping via
phpredis (sem depender de redis-cli no host); se indisponível, tenta `docker compose up -d
redis` e espera o healthcheck; então executa as três provas com a MESMA bateria e encerra com um
bloco de leitura do contraste. Saída de erro sempre explícita — a demo prefere falhar claro a
mostrar demo pela metade.

Execução real da própria demo nesta sessão (2026-07-30, PHP 8.4.21, Redis 7.0.15, 40×5,
2 rodadas):

```
naive:        round 1: obtained=84 | round 2: obtained=91   (esperado: 50)
              Verdict: RACE CONDITION DEMONSTRATED
token_bucket: round 1: obtained=50 | round 2: obtained=50   (esperado: 50)
              Verdict: NO OVER-ADMISSION — atomic by construction
leaky_bucket: round 1: obtained=50 | round 2: obtained=50   (esperado: 50)
              Verdict: NO OVER-ADMISSION — atomic by construction
```

## Checklist de honestidade (estado ao fim da Fase 5)

Feito e provado com execução real: race do naive (Fase 1); atomicidade de token e leaky
(Fases 2–3); EVALSHA com reidratação NOSCRIPT transparente, incluindo `SCRIPT FLUSH` no meio da
operação (Fase 4); `failure_mode` open/closed honrado (Fase 2); headers completos, incluindo
`X-RateLimit-Reset` (Fase 4); comandos de operação `inspect`/`reset`/`dry-run` (Fase 4);
Compose do Redis e demo reproduzível (Fase 5).

Ainda NÃO é produção — dito sem rodeio:

- Sem observabilidade: nenhuma métrica, tracing ou alerta; os logs estruturados do middleware
  são o único sinal.
- Sem teste de carga: k6 ficou fora por regra do exercício (opcional, não obrigatório); os
  números do projeto medem CORREÇÃO sob concorrência, não throughput/latência sob carga.
- Sem edge/Nginx: rate limiting em camada 7 de aplicação apenas; defesa em profundidade no edge
  fica fora do escopo.
- Redis único: sem Sentinel/Cluster; as implicações de failover para EVALSHA são cobertas
  (reidratação), mas split-brain e HA não são tratados.
- IP como identidade pressupõe IP verdadeiro: atrás de proxy/CDN é preciso configurar trusted
  proxies — não incluído.
- Multi-tenant fora de escopo por regra.
- `php artisan test` e `composer install`: PENDENTE DE EXECUÇÃO no ambiente desta entrega
  (Packagist bloqueado pelo proxy — verificado novamente nesta sessão). Os testes estão
  escritos; nenhum resultado de teste foi inventado. Rode localmente após `composer install`.

## Critérios de aceite da Fase 5

1. `docker-compose.yml` mínimo com só o Redis, documentado.
2. Demo em script único com as três provas e contraste — executada de verdade nesta sessão.
3. README de portfólio: problema → prova → solução → como rodar em ~90 segundos, com tabela
   comparativa alinhada às docs e checklist de honestidade.
4. Nenhuma métrica inventada em nenhum documento.
