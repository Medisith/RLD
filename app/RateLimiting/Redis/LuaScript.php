<?php

declare(strict_types=1);

namespace App\RateLimiting\Redis;

use App\RateLimiting\Exceptions\LuaScriptFailureException;

/**
 * Value object de um script Lua versionado — fonte + SHA-1 (Fase 4).
 *
 * Responsabilidade: carregar o arquivo .lua UMA vez por processo e computar
 * o SHA-1 UMA vez, na construção. É o "cache do SHA em memória de processo"
 * do fluxo EVALSHA: os algoritmos guardam a instância (via memoização do
 * RateLimitAlgorithmFactory) e o adaptador usa `sha1` para EVALSHA e
 * `source` para reidratar via SCRIPT LOAD quando o Redis responder
 * NOSCRIPT (restart, failover, SCRIPT FLUSH).
 *
 * Os arquivos .lua versionados em app/RateLimiting/Redis/scripts/ continuam
 * sendo a ÚNICA fonte de verdade — nenhum Lua embutido em string PHP.
 */
final readonly class LuaScript
{
    /**
     * Recebe: fonte e caminho de origem (para diagnóstico). Faz: computa o
     * SHA-1 exatamente como o Redis computa para EVALSHA (sha1 do fonte
     * bruto). Retorna: instância imutável. Efeitos colaterais: nenhum.
     */
    private function __construct(
        // Fonte Lua completa — enviada ao Redis apenas em SCRIPT LOAD.
        public string $source,
        // SHA-1 do fonte — enviado em toda decisão via EVALSHA.
        public string $sha1,
        // Caminho do arquivo de origem, para mensagens de erro rastreáveis.
        public string $path,
    ) {
    }

    /**
     * Recebe: caminho absoluto de um arquivo .lua versionado. Faz: lê o
     * arquivo e computa o SHA-1. Retorna: LuaScript pronto para uso.
     * Efeitos colaterais: leitura de arquivo; lança
     * LuaScriptFailureException se o arquivo não existir ou estiver vazio —
     * falha na construção, nunca no meio de uma decisão.
     */
    public static function fromFile(string $path): self
    {
        $source = @file_get_contents($path);

        if ($source === false || $source === '') {
            throw LuaScriptFailureException::missingScript($path);
        }

        return new self(
            source: $source,
            sha1: sha1($source),
            path: $path,
        );
    }
}
