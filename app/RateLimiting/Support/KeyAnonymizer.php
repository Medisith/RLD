<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * Pseudonimizador de chaves de limitação para logs (Fase 6).
 *
 * Responsabilidade: permitir logar a chave decidida SEM expor PII crua
 * (IP ou id de usuário). O identificador — e somente ele — é substituído
 * por um HMAC-SHA256 truncado, com a APP_KEY como segredo: o mesmo cliente
 * gera sempre o mesmo pseudônimo (correlação entre linhas de log preservada
 * para debug), mas o valor não é reversível nem consultável por quem não
 * tem o segredo da aplicação. Estratégia e rota permanecem legíveis, porque
 * são operacionais, não pessoais.
 *
 * Exemplo: rate-limit:ip:203.0.113.10:rate-limited.ping
 *       -> rate-limit:ip:a1b2c3d4e5f60718:rate-limited.ping
 */
final readonly class KeyAnonymizer
{
    // 16 hex chars (64 bits) — colisão irrelevante para correlação de log e
    // curto o bastante para não poluir as linhas.
    private const int PSEUDONYM_LENGTH = 16;

    /**
     * Recebe: o segredo do HMAC (em produção, a APP_KEY — injetada pelo
     * provider). Faz: guarda o segredo. Retorna: instância imutável.
     * Efeitos colaterais: nenhum.
     */
    public function __construct(
        private string $secret,
    ) {
    }

    /**
     * Recebe: uma chave canônica rate-limit:{strategy}:{identifier}:{route}.
     * Faz: substitui APENAS o identificador pelo pseudônimo HMAC. Suporta
     * identificadores com ":" (IPv6): prefixo é o 1º segmento, estratégia o
     * 2º, rota o último — tudo entre eles é identificador. Chave fora do
     * padrão (menos de 4 segmentos) é pseudonimizada por inteiro, por
     * segurança. Retorna: chave segura para log. Efeitos colaterais: nenhum.
     */
    public function anonymize(string $key): string
    {
        $segments = explode(':', $key);

        if (count($segments) < 4) {
            // Formato inesperado: não dá para saber onde está a PII —
            // pseudonimiza tudo em vez de arriscar vazamento parcial.
            return $this->pseudonym($key);
        }

        $prefix = $segments[0];
        $strategy = $segments[1];
        $routeName = $segments[count($segments) - 1];
        $identifier = implode(':', array_slice($segments, 2, count($segments) - 3));

        return sprintf(
            '%s:%s:%s:%s',
            $prefix,
            $strategy,
            $this->pseudonym($identifier),
            $routeName,
        );
    }

    /**
     * Recebe: valor sensível. Faz: HMAC-SHA256 com o segredo, truncado.
     * Retorna: pseudônimo estável e não reversível. Efeitos colaterais:
     * nenhum.
     */
    private function pseudonym(string $value): string
    {
        return substr(hash_hmac('sha256', $value, $this->secret), 0, self::PSEUDONYM_LENGTH);
    }
}
