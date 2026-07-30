<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

use App\RateLimiting\Exceptions\RedisUnavailableException;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registrador de métricas mínimas do limitador (Fase 6).
 *
 * Responsabilidade: manter os quatro contadores (RateLimitMetric) num hash
 * do Redis via HINCRBY — compartilhado entre processos PHP-FPM e legível
 * por um processo Artisan separado (rate-limit:metrics), que é o que torna
 * a métrica DEMONSTRÁVEL localmente. Contadores em memória de processo
 * morreriam a cada request e seriam invisíveis fora dele.
 *
 * Regra inegociável: métrica NUNCA derruba requisição. increment() engole
 * qualquer falha de infraestrutura e degrada para uma linha de log métrico
 * estruturada ("rate_limit_metric") — assim, mesmo com o Redis fora (caso
 * típico do redis_errors_total), o sinal sobrevive nos logs.
 *
 * Escopo honesto: isto não é Prometheus — sem labels, sem histogramas, sem
 * scraping; contadores crescem sem TTL até rate-limit:metrics --reset.
 * Limite documentado em docs/fases/fase-6-observability-and-hardening.md.
 */
final readonly class RateLimitMetrics
{
    // Hash único de contadores. Fora do padrão de chave por cliente de
    // propósito: cardinalidade 1, sem PII.
    public const string COUNTERS_KEY = 'rate-limit:metrics:counters';

    /**
     * Recebe: a fábrica de conexões Redis do framework. Faz: guarda a
     * dependência. Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    public function __construct(
        private RedisFactory $redisFactory,
    ) {
    }

    /**
     * Recebe: a métrica a incrementar. Faz: HINCRBY +1 no hash de
     * contadores; em falha de infraestrutura, degrada para log métrico
     * estruturado em vez de propagar. Retorna: void. Efeitos colaterais:
     * escrita no Redis OU uma linha de log; nunca lança exceção.
     */
    public function increment(RateLimitMetric $metric): void
    {
        try {
            $this->redisFactory->connection()->client()
                ->hIncrBy(self::COUNTERS_KEY, $metric->value, 1);
        } catch (Throwable $failure) {
            // Fallback documentado: o incremento vira log estruturado. Sem
            // PII (nome de métrica apenas) e sem quebrar o request.
            Log::info('rate_limit_metric', [
                'metric' => $metric->value,
                'delta' => 1,
                'redis_write_failed' => true,
                'error' => $failure->getMessage(),
            ]);
        }
    }

    /**
     * Recebe: nada. Faz: lê o hash de contadores e normaliza — toda métrica
     * do enum aparece, com 0 quando nunca incrementada. Retorna: mapa
     * nome => valor. Efeitos colaterais: nenhum; lança
     * RedisUnavailableException quando o Redis não responde (quem exibe
     * decide como reportar — o comando Artisan falha claro).
     *
     * @return array<string, int>
     */
    public function snapshot(): array
    {
        try {
            /** @var array<string, string> $rawCounters */
            $rawCounters = (array) $this->redisFactory->connection()->client()
                ->hGetAll(self::COUNTERS_KEY);
        } catch (Throwable $failure) {
            throw RedisUnavailableException::from($failure);
        }

        $normalized = [];

        foreach (RateLimitMetric::cases() as $metric) {
            $normalized[$metric->value] = (int) ($rawCounters[$metric->value] ?? 0);
        }

        return $normalized;
    }

    /**
     * Recebe: nada. Faz: zera todos os contadores (DEL do hash) — uso em
     * demo e testes. Retorna: void. Efeitos colaterais: remove o hash;
     * lança RedisUnavailableException quando o Redis não responde.
     */
    public function reset(): void
    {
        try {
            $this->redisFactory->connection()->client()->del(self::COUNTERS_KEY);
        } catch (Throwable $failure) {
            throw RedisUnavailableException::from($failure);
        }
    }
}
