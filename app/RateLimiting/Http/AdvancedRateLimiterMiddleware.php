<?php

declare(strict_types=1);

namespace App\RateLimiting\Http;

use App\RateLimiting\Contracts\RateLimitAlgorithm;
use App\RateLimiting\Contracts\RateLimitKeyResolver;
use App\RateLimiting\Support\RateLimitPolicy;
use App\RateLimiting\Support\RateLimitResult;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de limitação avançada de requisições (alias "rate-limit.advanced").
 *
 * Responsabilidade: orquestrar política -> chave -> algoritmo -> resposta
 * HTTP. Não contém lógica de contagem: decide apenas COMO responder ao
 * veredito do RateLimitAlgorithm (Fases 0 e 1: somente o limitador ingênuo,
 * intencionalmente vulnerável — ver NaiveRedisRateLimiter).
 *
 * Zero dependência do rate limiter nativo do Laravel: nenhum uso de
 * ThrottleRequests, da facade RateLimiter ou do alias "throttle".
 *
 * Falha de infraestrutura (Redis fora): a RedisUnavailableException propaga
 * e a requisição falha de forma explícita. O "failure_mode" (open|closed)
 * da config está reservado e será honrado em fase futura — decisão
 * registrada em docs/fases/fase-0-framing.md.
 */
final readonly class AdvancedRateLimiterMiddleware
{
    private const string HEADER_LIMIT = 'X-RateLimit-Limit';

    private const string HEADER_REMAINING = 'X-RateLimit-Remaining';

    private const string HEADER_RETRY = 'Retry-After';

    /**
     * Recebe: o algoritmo e o resolvedor de chave via contratos (injetados
     * pelo RateLimitingServiceProvider). Faz: guarda dependências.
     * Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        private RateLimitAlgorithm $algorithm,
        private RateLimitKeyResolver $keyResolver,
    ) {
    }

    /**
     * Recebe: requisição e próximo estágio do pipeline. Faz: quando o
     * limitador está habilitado, resolve política e chave, consulta o
     * algoritmo e (a) nega com 429 JSON + headers ou (b) deixa seguir e
     * anexa os headers informativos à resposta. Retorna: a resposta HTTP.
     * Efeitos colaterais: consumo de saldo no Redis via algoritmo; log de
     * negações; propaga RedisUnavailableException (falha explícita).
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('rate_limiting.enabled', true)) {
            return $next($request);
        }

        $policy = $this->policyForRequest($request);
        $resolvedKey = $this->keyResolver->resolve($request, $policy);

        $rateLimitResult = $this->algorithm->attempt(
            key: $resolvedKey,
            policy: $policy,
            cost: $policy->defaultCost,
        );

        if (! $rateLimitResult->allowed) {
            return $this->deniedResponse($rateLimitResult);
        }

        $response = $next($request);

        // Também na resposta permitida o cliente enxerga seu saldo — contrato
        // de produto definido na Fase 0.
        $response->headers->set(self::HEADER_LIMIT, (string) $rateLimitResult->limit);
        $response->headers->set(self::HEADER_REMAINING, (string) $rateLimitResult->remaining);

        return $response;
    }

    /**
     * Recebe: a requisição corrente. Faz: localiza a política da rota pelo
     * nome em config('rate_limiting.policies'); rotas sem política
     * própria herdam integralmente os valores globais da config (decisão
     * documentada: proteger por padrão em vez de liberar por omissão).
     * Retorna: RateLimitPolicy validada. Efeitos colaterais: nenhum;
     * config inválida lança InvalidRateLimitPolicyException na construção.
     */
    private function policyForRequest(Request $request): RateLimitPolicy
    {
        $globalConfig = (array) config('rate_limiting', []);

        // Rota sem nome ganha identificador estável derivado do caminho para
        // não colapsar todas as rotas anônimas numa única chave de contagem.
        $routeName = $request->route()?->getName() ?? 'unnamed:'.$request->path();

        // Acesso direto ao array (nunca data_get/config("...policies.{$routeName}")):
        // nomes de rota contêm ponto ("rate-limited.ping") e seriam quebrados em
        // segmentos pela notação de pontos do framework.
        /** @var array<string, mixed> $routeConfig */
        $routeConfig = (array) (($globalConfig['policies'] ?? [])[$routeName] ?? []);

        return RateLimitPolicy::fromConfig(
            name: $routeName,
            routeConfig: $routeConfig,
            globalConfig: $globalConfig,
        );
    }

    /**
     * Recebe: o veredito negado. Faz: registra a negação em log e monta a
     * resposta 429 com corpo JSON e headers do contrato de produto.
     * Retorna: JsonResponse 429. Efeitos colaterais: escrita em log.
     */
    private function deniedResponse(RateLimitResult $rateLimitResult): JsonResponse
    {
        Log::info('Request denied by the custom rate limiter.', [
            'key' => $rateLimitResult->key,
            'algorithm' => $rateLimitResult->algorithm,
            'limit' => $rateLimitResult->limit,
            'retry_after' => $rateLimitResult->retryAfter,
        ]);

        return new JsonResponse(
            data: [
                'message' => sprintf(
                    'Rate limit exceeded. Try again in %d seconds.',
                    $rateLimitResult->retryAfter,
                ),
                'code' => 'RATE_LIMIT_EXCEEDED',
                'limit' => $rateLimitResult->limit,
                'retry_after' => $rateLimitResult->retryAfter,
            ],
            status: Response::HTTP_TOO_MANY_REQUESTS,
            headers: [
                self::HEADER_LIMIT => (string) $rateLimitResult->limit,
                self::HEADER_REMAINING => (string) $rateLimitResult->remaining,
                self::HEADER_RETRY => (string) $rateLimitResult->retryAfter,
            ],
        );
    }
}
