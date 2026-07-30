<?php

declare(strict_types=1);

namespace App\RateLimiting\Exceptions;

/**
 * Lançada quando um script Lua não pode ser carregado, falha no servidor ou
 * devolve resposta fora do formato contratado.
 *
 * Responsabilidade: distinguir defeito de SCRIPT (bug nosso: arquivo
 * ausente, sintaxe Lua inválida, retorno malformado) de indisponibilidade
 * de INFRAESTRUTURA (RedisUnavailableException). A distinção importa para o
 * failure_mode: um bug de script não deve ser mascarado por fail-open — ele
 * precisa aparecer alto e cedo.
 */
final class LuaScriptFailureException extends RateLimitException
{
    /**
     * Recebe: caminho do arquivo .lua que não pôde ser lido. Faz: monta
     * mensagem explícita. Retorna: instância pronta para lançar. Efeitos
     * colaterais: nenhum.
     */
    public static function missingScript(string $path): self
    {
        return new self("Lua script not found or unreadable at '{$path}'.");
    }

    /**
     * Recebe: mensagem de erro reportada pelo servidor Redis ao executar o
     * script. Faz: embrulha em exceção de domínio. Retorna: instância pronta
     * para lançar. Efeitos colaterais: nenhum.
     */
    public static function serverError(string $errorMessage): self
    {
        return new self("Redis reported a Lua script error: {$errorMessage}");
    }

    /**
     * Recebe: chave em decisão e a resposta bruta recebida. Faz: registra o
     * formato inesperado (contrato: array [allowed, remaining, retry_after]).
     * Retorna: instância pronta para lançar. Efeitos colaterais: nenhum.
     */
    public static function unexpectedReply(string $key, mixed $reply): self
    {
        return new self(sprintf(
            "Unexpected Lua script reply for key '%s': expected [allowed, remaining, retry_after], got %s.",
            $key,
            var_export($reply, true),
        ));
    }
}
