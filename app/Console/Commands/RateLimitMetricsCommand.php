<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\RateLimiting\Exceptions\RedisUnavailableException;
use App\RateLimiting\Support\RateLimitMetrics;
use Illuminate\Console\Command;

/**
 * Comando de operação: exibe (e opcionalmente zera) os contadores de
 * métricas do limitador (Fase 6).
 *
 * Responsabilidade: dar visibilidade local e demonstrável dos quatro
 * contadores (allowed_total, denied_total, redis_errors_total,
 * evalsha_reload_total) sem introduzir stack de observabilidade externa.
 *
 * Por que comando Artisan e NÃO endpoint HTTP interno: (1) não cria
 * superfície HTTP nova para proteger; (2) funciona igual em qualquer
 * ambiente, sem depender de APP_DEBUG; (3) é consistente com as demais
 * ferramentas de ops do projeto (inspect/reset/dry-run). Decisão registrada
 * em docs/fases/fase-6-observability-and-hardening.md.
 *
 * Não vaza segredos nem PII: os contadores são agregados globais.
 */
class RateLimitMetricsCommand extends Command
{
    protected $signature = 'rate-limit:metrics
        {--reset : Zera todos os contadores (uso em demo/testes)}';

    protected $description = 'Shows (or resets) the rate limiter metric counters';

    /**
     * Recebe: a flag opcional --reset e o registrador de métricas. Faz:
     * zera os contadores quando pedido; sempre exibe o snapshot atual em
     * tabela. Retorna: SUCCESS; FAILURE quando o Redis está indisponível
     * (falha explícita — sem números inventados). Efeitos colaterais:
     * DEL do hash de contadores quando --reset.
     */
    public function handle(RateLimitMetrics $metrics): int
    {
        try {
            if ((bool) $this->option('reset')) {
                $metrics->reset();
                $this->info('Metric counters reset.');
            }

            $snapshot = $metrics->snapshot();
        } catch (RedisUnavailableException $failure) {
            $this->error('Redis unavailable — no metrics to show: '.$failure->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            array_map(
                fn (string $name, int $value): array => [$name, (string) $value],
                array_keys($snapshot),
                array_values($snapshot),
            ),
        );

        $this->line('Counters are best-effort (HINCRBY on Redis; falls back to "rate_limit_metric" log lines when Redis is down).');

        return self::SUCCESS;
    }
}
