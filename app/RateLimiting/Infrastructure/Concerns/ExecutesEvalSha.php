<?php

declare(strict_types=1);

namespace App\RateLimiting\Infrastructure\Concerns;

use App\RateLimiting\Exceptions\LuaScriptFailureException;
use App\RateLimiting\Redis\LuaScript;
use Redis;

/**
 * Rotina compartilhada de EVALSHA com reidratação NOSCRIPT (Fase 4).
 *
 * Responsabilidade: concentrar em UM lugar a sequência delicada
 * EVALSHA -> (NOSCRIPT?) -> SCRIPT LOAD -> verificação de SHA -> retry,
 * usada pelos dois adaptadores (LaravelRedisClient e NativeRedisClient),
 * ambos apoiados na extensão phpredis. Manter a lógica duplicada nos dois
 * adaptadores seria convite a divergência exatamente no caminho mais
 * difícil de testar (o frio, pós-restart/failover/SCRIPT FLUSH).
 *
 * Comportamento do phpredis assumido (verificado empiricamente): comandos
 * EVALSHA com script desconhecido retornam false e registram
 * "NOSCRIPT ..." em getLastError(), em vez de lançar exceção.
 */
trait ExecutesEvalSha
{
    /**
     * Recebe: cliente phpredis conectado, o script (fonte + SHA-1), KEYS e
     * ARGV. Faz: EVALSHA com o SHA pré-computado; em NOSCRIPT, recarrega o
     * fonte com SCRIPT LOAD, confere que o SHA devolvido pelo servidor é o
     * mesmo do arquivo versionado e repete o EVALSHA uma única vez.
     * Retorna: resposta bruta do Redis. Efeitos colaterais: os do script;
     * lança LuaScriptFailureException para erro de script reportado pelo
     * servidor, SHA divergente ou NOSCRIPT persistente pós-reidratação.
     *
     * @param  list<string>  $keys
     * @param  list<int|float|string>  $arguments
     */
    protected function runEvalShaOnClient(Redis $client, LuaScript $script, array $keys, array $arguments): mixed
    {
        $flatArguments = [...$keys, ...$arguments];
        $keyCount = count($keys);

        // Caminho quente: 1 round-trip enviando só o SHA (40 bytes).
        $client->clearLastError();
        $reply = $client->evalSha($script->sha1, $flatArguments, $keyCount);

        if ($reply === false && $this->lastErrorIsNoScript($client)) {
            // Caminho frio (restart/failover/SCRIPT FLUSH): reidrata a partir
            // do arquivo versionado — a fonte de verdade continua no repo.
            $client->clearLastError();
            $loadedSha = $client->script('load', $script->source);

            if (! is_string($loadedSha) || strtolower($loadedSha) !== $script->sha1) {
                throw LuaScriptFailureException::shaMismatch(
                    path: $script->path,
                    expectedSha: $script->sha1,
                    loadedSha: is_string($loadedSha) ? $loadedSha : var_export($loadedSha, true),
                );
            }

            // Observabilidade (Fase 6): reidratação concluída — o adaptador
            // decide se/como conta (LaravelRedisClient incrementa
            // evalsha_reload_total; NativeRedisClient mantém o no-op).
            $this->reportEvalShaReload();

            $client->clearLastError();
            $reply = $client->evalSha($script->sha1, $flatArguments, $keyCount);

            if ($reply === false && $this->lastErrorIsNoScript($client)) {
                // Impossível em operação normal: acabou de carregar e o
                // servidor nega conhecer o script. Falha alto, sem retry loop.
                throw LuaScriptFailureException::noScriptAfterReload($script->path);
            }
        }

        if ($reply === false) {
            $serverError = $client->getLastError();

            if ($serverError !== null) {
                // Erro de EXECUÇÃO do script (bug de Lua): nunca silencioso.
                $client->clearLastError();

                throw LuaScriptFailureException::serverError($serverError);
            }
        }

        return $reply;
    }

    /**
     * Recebe: nada. Faz: hook chamado após cada reidratação NOSCRIPT bem
     * sucedida — no-op por padrão; o adaptador que tiver observabilidade
     * disponível sobrescreve (métrica evalsha_reload_total). Retorna: void.
     * Efeitos colaterais: nenhum por padrão; a implementação que sobrescreve
     * DEVE ser best-effort (nunca falhar a decisão por causa de métrica).
     */
    protected function reportEvalShaReload(): void
    {
        // Intencionalmente vazio.
    }

    /**
     * Recebe: cliente phpredis. Faz: verifica se o último erro registrado é
     * NOSCRIPT (script desconhecido pelo servidor). Retorna: true/false.
     * Efeitos colaterais: nenhum (não limpa o erro — quem decide é o
     * chamador).
     */
    private function lastErrorIsNoScript(Redis $client): bool
    {
        $lastError = $client->getLastError();

        return $lastError !== null && str_starts_with($lastError, 'NOSCRIPT');
    }
}
