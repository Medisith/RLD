<?php

declare(strict_types=1);

namespace App\RateLimiting\Http;

use App\RateLimiting\Algorithms\RateLimitAlgorithmFactory;
use App\RateLimiting\Contracts\RateLimitKeyResolver;
use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Exceptions\RedisUnavailableException;
use App\RateLimiting\Support\FailureMode;
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
 * veredito do RateLimitAlgorithm. A partir da Fase 3 o algoritmo é
 * selecionado POR POLÍTICA (naive | token_bucket | leaky_bucket, por rota)
 * via RateLimitAlgorithmFactory.
 *
 * Zero dependência do rate limiter nativo do Laravel: nenhum uso de
 * ThrottleRequests, da facade RateLimiter ou do alias "throttle".
 *
 * Resiliência (honrada a partir da Fase 2): quando o Redis está
 * indisponível (RedisUnavailableException), o "failure_mode" da config
 * decide — "open" deixa a requisição passar sem contagem (log de alerta,
 * sem headers de saldo: não há números honestos a dar); "closed" nega com
 * 503. Bug de script Lua (LuaScriptFailureException) NUNCA é absorvido:
 * propaga e falha alto, como todo defeito de código deve falhar.
 */
final readonly class AdvancedRateLimiterMiddleware
{
    private const string HEADER_LIMIT = 'X-RateLimit-Limit';

    private const string HEADER_REMAINING = 'X-RateLimit-Remaining';

    private const string HEADER_RETRY = 'Retry-After';

    // Retry-After sugerido no 503 de fail-closed: curto o bastante para o
    // cliente voltar logo após um blip de Redis, longo o bastante para não
    // transformar o incidente em martelo de retries.
    private const int RETRY_AFTER_ON_FAILURE_SECONDS = 5;

    /**
     * Recebe: a fábrica de algoritmos e o resolvedor de chave via contratos
     * (injetados pelo RateLimitingServiceProvider). Faz: guarda
     * dependências. Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        private RateLimitAlgorithmFactory $algorithmFactory,
        private RateLimitKeyResolver $keyResolver,
    ) {
    }

    /**
     * Recebe: requisição e próximo estágio do pipeline. Faz: quando o
     * limitador está habilitado, resolve política e chave, seleciona o
     * algoritmo declarado pela política e (a) nega com 429 JSON + headers,
     * (b) deixa seguir e anexa os headers informativos, ou (c) aplica o
     * failure_mode se o Redis estiver fora. Retorna: a resposta HTTP.
     * Efeitos colaterais: consumo de saldo no Redis via algoritmo; logs de
     * negação e de falha de infraestrutura.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('rate_limiting.enabled', true)) {
            return $next($request);
        }

        $policy = $this->policyForRequest($request);
        $resolvedKey = $this->keyResolver->resolve($request, $policy);

        // Seleção POR POLÍTICA: cada rota escolhe seu algoritmo na config.
        $algorithm = $this->algorithmFactory->forAlgorithm($policy->algorithm);

        try {
            $rateLimitResult = $algorithm->attempt(
                key: $resolvedKey,
                policy: $policy,
                cost: $policy->defaultCost,
            );
        } catch (RedisUnavailableException $failure) {
            // Somente indisponibilidade de INFRAESTRUTURA cai aqui.
            // LuaScriptFailureException e InvalidRateLimitPolicyException
            // propagam: são bugs, não incidentes.
            return $this->handleInfrastructureFailure($request, $next, $resolvedKey, $failure);
        }

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

    /**
     * Recebe: requisição, próximo estágio, chave em decisão e a falha de
     * infraestrutura. Faz: aplica o failure_mode da config — "open" registra
     * alerta e deixa a requisição seguir SEM contagem e SEM headers de saldo
     * (não há números honestos sem Redis); "closed" registra erro e nega com
     * 503 + Retry-After. Valor de failure_mode desconhecido lança
     * InvalidRateLimitPolicyException (config errada é bug, não incidente).
     * Retorna: a resposta HTTP conforme o modo. Efeitos colaterais: logs.
     */
    private function handleInfrastructureFailure(
        Request $request,
        Closure $next,
        string $resolvedKey,
        RedisUnavailableException $failure,
    ): Response {
        $rawMode = (string) config('rate_limiting.failure_mode', '');

        $failureMode = FailureMode::tryFrom($rawMode)
            ?? throw InvalidRateLimitPolicyException::forReason(
                "unknown failure_mode '{$rawMode}' — valid values: open, closed"
            );

        if ($failureMode === FailureMode::Open) {
            Log::warning('Rate limiter Redis unavailable — failure_mode=open, letting request through UNCOUNTED.', [
                'key' => $resolvedKey,
                'error' => $failure->getMessage(),
            ]);

            return $next($request);
        }

        Log::error('Rate limiter Redis unavailable — failure_mode=closed, rejecting request.', [
            'key' => $resolvedKey,
            'error' => $failure->getMessage(),
        ]);

        return new JsonResponse(
            data: [
                'message' => 'Rate limiter unavailable. Request rejected by closed failure mode.',
                'code' => 'RATE_LIMITER_UNAVAILABLE',
                'retry_after' => self::RETRY_AFTER_ON_FAILURE_SECONDS,
            ],
            status: Response::HTTP_SERVICE_UNAVAILABLE,
            headers: [
                self::HEADER_RETRY => (string) self::RETRY_AFTER_ON_FAILURE_SECONDS,
            ],
        );
    }
}
