<?php

declare(strict_types=1);

namespace App\RateLimiting\Resolvers;

use App\RateLimiting\Exceptions\InvalidRateLimitPolicyException;
use App\RateLimiting\Support\RateLimitPolicy;
use App\RateLimiting\Support\TenantQuota;
use Illuminate\Http\Request;

/**
 * Resolvedor da quota composta por tenant (Fase 9).
 *
 * Responsabilidade: decidir se uma requisição está sujeita a um SEGUNDO
 * balde — o do tenant, compartilhado por todos os clientes da mesma
 * organização na mesma rota — e, em caso positivo, montar política e chave.
 *
 * Desligado por padrão (config rate_limiting.tenant.enabled = false): com a
 * flag em false o resolver devolve null sempre e o comportamento das fases
 * anteriores fica byte a byte idêntico.
 *
 * AVISO DE SEGURANÇA (repetido em docs/fases/fase-9): o identificador vem de
 * um HEADER. Header é dado do cliente. Omitir o header pula a quota de
 * tenant, e forjá-lo troca de balde. Logo, o header SÓ tem valor quando é
 * injetado (e sobrescrito) por uma camada confiável — gateway, BFF ou o
 * próprio middleware de autenticação a partir do token. Este resolver
 * sanitiza o formato, o que evita poluição de keyspace, mas NÃO transforma
 * o header em fronteira de confiança.
 *
 * Planos (Fase 11): o cliente diz QUEM é; o servidor decide QUANTO ele pode.
 * O plano nunca vem de header — sai do mapa estático
 * rate_limiting.tenant.assignments, com queda para default_plan. Assim,
 * forjar o header no máximo troca o balde por outro de um tenant existente;
 * jamais concede uma cota maior por conta própria.
 */
final readonly class TenantQuotaResolver
{
    // Identificador aceito: alfanumérico, ponto, hífen e sublinhado, até 64
    // caracteres. Restringir o formato protege o keyspace do Redis contra
    // cardinalidade artificial e impede que ":" quebre o padrão de chave.
    private const string TENANT_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /**
     * Recebe: a configuração completa de rate limiting (para mesclar os
     * defaults globais na política do tenant) e o prefixo de chave. Faz:
     * guarda as dependências. Retorna: instância imutável. Efeitos
     * colaterais: nenhum.
     *
     * @param  array<string, mixed>  $globalConfig
     */
    public function __construct(
        private array $globalConfig,
        private string $keyPrefix,
    ) {
    }

    /**
     * Recebe: a requisição corrente e o nome da rota (mesmo identificador
     * usado na política do cliente). Faz: verifica a flag, lê e sanitiza o
     * header configurado e monta a política do tenant mesclando
     * rate_limiting.tenant sobre a config global. Retorna: TenantQuota
     * pronta para o segundo check, ou null quando a quota não se aplica
     * (flag desligada, header ausente ou identificador fora do formato).
     * Efeitos colaterais: nenhum; config de tenant inválida lança
     * InvalidRateLimitPolicyException na construção da política — erro de
     * configuração falha explícito, como no resto do projeto.
     */
    public function resolve(Request $request, string $routeName): ?TenantQuota
    {
        /** @var array<string, mixed> $tenantConfig */
        $tenantConfig = (array) ($this->globalConfig['tenant'] ?? []);

        if (! (bool) ($tenantConfig['enabled'] ?? false)) {
            return null;
        }

        $headerName = (string) ($tenantConfig['header'] ?? 'X-Tenant-Id');
        $rawTenantId = trim((string) $request->header($headerName, ''));

        // Header ausente ou malformado: sem quota de tenant. Decisão
        // consciente de NÃO rejeitar com 4xx — o header não é fronteira de
        // confiança (ver aviso no cabeçalho), então recusar a requisição
        // daria falsa sensação de proteção e quebraria clientes legítimos
        // durante uma migração gradual.
        if ($rawTenantId === '' || preg_match(self::TENANT_ID_PATTERN, $rawTenantId) !== 1) {
            return null;
        }

        // Plano resolvido SEMPRE no servidor (Fase 11): o cliente identifica
        // quem é, nunca quanto pode. Sem atribuição explícita, cai no plano
        // padrão.
        $planName = $this->planFor($rawTenantId, $tenantConfig);
        $planConfig = $this->planConfig($planName, $tenantConfig);

        // A política do tenant reaproveita TODA a validação de invariantes
        // do RateLimitPolicy (capacidade, taxas por algoritmo, custo).
        // Precedência: plano > base do tenant > config global.
        // key_strategy é herdada da config global e ignorada aqui: a chave
        // do tenant não depende de estratégia de identificação de cliente.
        $policy = RateLimitPolicy::fromConfig(
            name: sprintf('tenant:%s:%s', $planName, $routeName),
            routeConfig: array_merge($tenantConfig, $planConfig),
            globalConfig: $this->globalConfig,
        );

        return new TenantQuota(
            tenantId: $rawTenantId,
            planName: $planName,
            policy: $policy,
            // A chave NÃO inclui o plano de propósito: a identidade do balde
            // é o tenant. Trocar de plano ajusta capacidade e taxa sem dar ao
            // tenant um balde novo e zerado — do contrário, uma mudança de
            // plano no meio da janela seria um reset gratuito de cota.
            key: sprintf('%s:tenant:%s:%s', $this->keyPrefix, $rawTenantId, $routeName),
        );
    }

    /**
     * Recebe: o identificador do tenant e a configuração de tenant. Faz:
     * consulta o mapa estático de atribuições e cai no plano padrão quando
     * o tenant não tem atribuição própria. Retorna: nome do plano. Efeitos
     * colaterais: nenhum.
     *
     * @param  array<string, mixed>  $tenantConfig
     */
    private function planFor(string $tenantId, array $tenantConfig): string
    {
        /** @var array<string, string> $assignments */
        $assignments = (array) ($tenantConfig['assignments'] ?? []);

        $assignedPlan = $assignments[$tenantId] ?? null;

        return is_string($assignedPlan) && $assignedPlan !== ''
            ? $assignedPlan
            : (string) ($tenantConfig['default_plan'] ?? '');
    }

    /**
     * Recebe: nome do plano e a configuração de tenant. Faz: localiza os
     * parâmetros do plano. Retorna: array de configuração do plano — vazio
     * quando não há planos declarados (retrocompatível com a Fase 9, em que
     * a cota do tenant era única). Efeitos colaterais: nenhum; lança
     * InvalidRateLimitPolicyException quando existem planos declarados mas o
     * nome resolvido não está entre eles: erro de configuração falha alto em
     * vez de silenciosamente conceder a cota-base a um tenant.
     *
     * @param  array<string, mixed>  $tenantConfig
     * @return array<string, mixed>
     */
    private function planConfig(string $planName, array $tenantConfig): array
    {
        /** @var array<string, array<string, mixed>> $plans */
        $plans = (array) ($tenantConfig['plans'] ?? []);

        if ($plans === []) {
            return [];
        }

        if (! isset($plans[$planName])) {
            throw InvalidRateLimitPolicyException::forReason(sprintf(
                "unknown tenant plan '%s' — declared plans: %s",
                $planName,
                implode(', ', array_keys($plans)),
            ));
        }

        return (array) $plans[$planName];
    }
}
