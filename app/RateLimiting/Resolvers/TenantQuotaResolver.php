<?php

declare(strict_types=1);

namespace App\RateLimiting\Resolvers;

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

        // A política do tenant reaproveita TODA a validação de invariantes
        // do RateLimitPolicy (capacidade, taxas por algoritmo, custo).
        // key_strategy é herdada da config global e ignorada aqui: a chave
        // do tenant não depende de estratégia de identificação de cliente.
        $policy = RateLimitPolicy::fromConfig(
            name: 'tenant:'.$routeName,
            routeConfig: $tenantConfig,
            globalConfig: $this->globalConfig,
        );

        return new TenantQuota(
            tenantId: $rawTenantId,
            policy: $policy,
            key: sprintf('%s:tenant:%s:%s', $this->keyPrefix, $rawTenantId, $routeName),
        );
    }
}
