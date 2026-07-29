<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Http;

use App\LimitacaoRequisicoes\Contratos\AlgoritmoLimitacao;
use App\LimitacaoRequisicoes\Contratos\ResolvedorChaveLimitacao;
use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;
use App\LimitacaoRequisicoes\Suporte\ResultadoLimitacao;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de limitação avançada de requisições (alias "limitacao.avancada").
 *
 * Responsabilidade: orquestrar política -> chave -> algoritmo -> resposta
 * HTTP. Não contém lógica de contagem: decide apenas COMO responder ao
 * veredito do AlgoritmoLimitacao (Fases 0 e 1: somente o limitador ingênuo,
 * intencionalmente vulnerável — ver LimitadorIngenuoRedis).
 *
 * Zero dependência do rate limiter nativo do Laravel: nenhum uso de
 * ThrottleRequests, da facade RateLimiter ou do alias "throttle".
 *
 * Falha de infraestrutura (Redis fora): a ExcecaoRedisIndisponivel propaga
 * e a requisição falha de forma explícita. O "modo_falha" (aberto|fechado)
 * da config está reservado e será honrado em fase futura — decisão
 * registrada em docs/fases/fase-0-framing.md.
 */
final readonly class MiddlewareLimitacaoAvancada
{
    private const string CABECALHO_LIMITE = 'X-RateLimit-Limit';

    private const string CABECALHO_RESTANTE = 'X-RateLimit-Remaining';

    private const string CABECALHO_RETRY = 'Retry-After';

    /**
     * Recebe: o algoritmo e o resolvedor de chave via contratos (injetados
     * pelo LimitacaoRequisicoesServiceProvider). Faz: guarda dependências.
     * Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        private AlgoritmoLimitacao $algoritmo,
        private ResolvedorChaveLimitacao $resolvedorChave,
    ) {
    }

    /**
     * Recebe: requisição e próximo estágio do pipeline. Faz: quando o
     * limitador está habilitado, resolve política e chave, consulta o
     * algoritmo e (a) nega com 429 JSON + headers ou (b) deixa seguir e
     * anexa os headers informativos à resposta. Retorna: a resposta HTTP.
     * Efeitos colaterais: consumo de saldo no Redis via algoritmo; log de
     * negações; propaga ExcecaoRedisIndisponivel (falha explícita).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('limitacao_requisicoes.habilitado', true)) {
            return $next($request);
        }

        $politica = $this->politicaParaRequisicao($request);
        $chaveResolvida = $this->resolvedorChave->resolver($request, $politica);

        $resultadoLimitacao = $this->algoritmo->tentar(
            chave: $chaveResolvida,
            politica: $politica,
            custo: $politica->custoPadrao,
        );

        if (! $resultadoLimitacao->permitido) {
            return $this->responderNegado($resultadoLimitacao);
        }

        $response = $next($request);

        // Também na resposta permitida o cliente enxerga seu saldo — contrato
        // de produto definido na Fase 0.
        $response->headers->set(self::CABECALHO_LIMITE, (string) $resultadoLimitacao->limite);
        $response->headers->set(self::CABECALHO_RESTANTE, (string) $resultadoLimitacao->restante);

        return $response;
    }

    /**
     * Recebe: a requisição corrente. Faz: localiza a política da rota pelo
     * nome em config('limitacao_requisicoes.politicas'); rotas sem política
     * própria herdam integralmente os valores globais da config (decisão
     * documentada: proteger por padrão em vez de liberar por omissão).
     * Retorna: PoliticaLimitacao validada. Efeitos colaterais: nenhum;
     * config inválida lança ExcecaoPoliticaInvalida na construção.
     */
    private function politicaParaRequisicao(Request $request): PoliticaLimitacao
    {
        $configuracaoGlobal = (array) config('limitacao_requisicoes', []);

        // Rota sem nome ganha identificador estável derivado do caminho para
        // não colapsar todas as rotas anônimas numa única chave de contagem.
        $nomeRota = $request->route()?->getName() ?? 'sem_nome:'.$request->path();

        // Acesso direto ao array (nunca data_get/config("...politicas.{$nomeRota}")):
        // nomes de rota contêm ponto ("limitado.ping") e seriam quebrados em
        // segmentos pela notação de pontos do framework.
        /** @var array<string, mixed> $configuracaoRota */
        $configuracaoRota = (array) (($configuracaoGlobal['politicas'] ?? [])[$nomeRota] ?? []);

        return PoliticaLimitacao::daConfiguracao(
            nome: $nomeRota,
            configuracaoRota: $configuracaoRota,
            configuracaoGlobal: $configuracaoGlobal,
        );
    }

    /**
     * Recebe: o veredito negado. Faz: registra a negação em log (mensagem de
     * negócio em português, com chave e algoritmo para rastreio) e monta a
     * resposta 429 com corpo JSON e headers do contrato de produto.
     * Retorna: JsonResponse 429. Efeitos colaterais: escrita em log.
     */
    private function responderNegado(ResultadoLimitacao $resultadoLimitacao): JsonResponse
    {
        Log::info('Requisição negada pelo limitador de requisições.', [
            'chave' => $resultadoLimitacao->chave,
            'algoritmo' => $resultadoLimitacao->algoritmo,
            'limite' => $resultadoLimitacao->limite,
            'tentar_novamente_em' => $resultadoLimitacao->tentarNovamenteEm,
        ]);

        return new JsonResponse(
            data: [
                'mensagem' => sprintf(
                    'Limite de requisições excedido. Tente novamente em %d segundos.',
                    $resultadoLimitacao->tentarNovamenteEm,
                ),
                'codigo' => 'LIMITE_REQUISICOES_EXCEDIDO',
                'limite' => $resultadoLimitacao->limite,
                'tentar_novamente_em' => $resultadoLimitacao->tentarNovamenteEm,
            ],
            status: Response::HTTP_TOO_MANY_REQUESTS,
            headers: [
                self::CABECALHO_LIMITE => (string) $resultadoLimitacao->limite,
                self::CABECALHO_RESTANTE => (string) $resultadoLimitacao->restante,
                self::CABECALHO_RETRY => (string) $resultadoLimitacao->tentarNovamenteEm,
            ],
        );
    }
}
