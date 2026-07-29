<?php

declare(strict_types=1);

namespace App\RateLimiting\Resolvers;

use App\RateLimiting\Contracts\RateLimitKeyResolver;
use App\RateLimiting\Support\KeyStrategy;
use App\RateLimiting\Support\RateLimitPolicy;
use Illuminate\Http\Request;

/**
 * Resolvedor padrão de chave de limitação.
 *
 * Responsabilidade: transformar (requisição, política) na chave canônica
 * rate-limit:{strategy}:{identifier}:{routeName}. Toda requisição do
 * mesmo cliente para a mesma rota DEVE produzir a mesma chave — é essa
 * estabilidade que faz o contador do Redis significar "consumo do cliente
 * na janela".
 */
final readonly class DefaultKeyResolver
    implements RateLimitKeyResolver
{
    /**
     * Recebe: prefixo raiz configurado (config rate_limiting.key_prefix).
     * Faz: guarda para composição da chave. Retorna: instância imutável.
     * Efeitos colaterais: nenhum.
     */
    public function __construct(
        private string $keyPrefix,
    ) {
    }

    /**
     * Recebe: requisição HTTP e política vigente. Faz: identifica o cliente
     * conforme a estratégia e monta a chave canônica; usa o nome da política
     * como {routeName} (a política já é indexada pelo nome da rota, o que
     * mantém a chave estável mesmo se a rota for renomeada com alias).
     * Retorna: chave resolvida. Efeitos colaterais: nenhum.
     */
    public function resolve(Request $request, RateLimitPolicy $policy): string
    {
        [$appliedStrategy, $identifier] = $this->identifyClient($request, $policy->keyStrategy);

        return sprintf(
            '%s:%s:%s:%s',
            $this->keyPrefix,
            $appliedStrategy->value,
            $identifier,
            $policy->name,
        );
    }

    /**
     * Recebe: requisição e estratégia configurada. Faz: aplica a estratégia,
     * registrando qual estratégia CONCRETA prevaleceu (user_or_ip vira
     * "user" ou "ip" na chave — assim um mesmo cliente não ganha dois
     * saldos ao autenticar no meio da janela em fase futura de análise).
     * Retorna: par [estratégia efetiva, identificador]. Efeitos colaterais:
     * nenhum.
     *
     * @return array{0: KeyStrategy, 1: string}
     */
    private function identifyClient(Request $request, KeyStrategy $strategy): array
    {
        $user = $request->user();

        return match ($strategy) {
            KeyStrategy::User => [
                KeyStrategy::User,
                // Sem usuário autenticado numa política "user", cai para
                // "anonymous" de forma explícita: melhor um balde único visível
                // do que uma exceção em rota pública mal configurada.
                $user !== null ? (string) $user->getAuthIdentifier() : 'anonymous',
            ],
            KeyStrategy::Ip => [
                KeyStrategy::Ip,
                (string) $request->ip(),
            ],
            KeyStrategy::UserOrIp => $user !== null
                ? [KeyStrategy::User, (string) $user->getAuthIdentifier()]
                : [KeyStrategy::Ip, (string) $request->ip()],
        };
    }
}
