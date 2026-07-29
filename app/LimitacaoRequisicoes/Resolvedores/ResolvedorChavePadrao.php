<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Resolvedores;

use App\LimitacaoRequisicoes\Contratos\ResolvedorChaveLimitacao;
use App\LimitacaoRequisicoes\Suporte\EstrategiaChave;
use App\LimitacaoRequisicoes\Suporte\PoliticaLimitacao;
use Illuminate\Http\Request;

/**
 * Resolvedor padrão de chave de limitação.
 *
 * Responsabilidade: transformar (requisição, política) na chave canônica
 * limitacao:{estrategia}:{identificador}:{nomeRota}. Toda requisição do
 * mesmo cliente para a mesma rota DEVE produzir a mesma chave — é essa
 * estabilidade que faz o contador do Redis significar "consumo do cliente
 * na janela".
 */
final readonly class ResolvedorChavePadrao
    implements ResolvedorChaveLimitacao
{
    /**
     * Recebe: prefixo raiz configurado (config limitacao_requisicoes.prefixo_chave).
     * Faz: guarda para composição da chave. Retorna: instância imutável.
     * Efeitos colaterais: nenhum.
     */
    public function __construct(
        private string $prefixoChave,
    ) {
    }

    /**
     * Recebe: requisição HTTP e política vigente. Faz: identifica o cliente
     * conforme a estratégia e monta a chave canônica; usa o nome da política
     * como {nomeRota} (a política já é indexada pelo nome da rota, o que
     * mantém a chave estável mesmo se a rota for renomeada com alias).
     * Retorna: chave resolvida. Efeitos colaterais: nenhum.
     */
    public function resolver(Request $request, PoliticaLimitacao $politica): string
    {
        [$estrategiaAplicada, $identificador] = $this->identificarCliente($request, $politica->estrategiaChave);

        return sprintf(
            '%s:%s:%s:%s',
            $this->prefixoChave,
            $estrategiaAplicada->value,
            $identificador,
            $politica->nome,
        );
    }

    /**
     * Recebe: requisição e estratégia configurada. Faz: aplica a estratégia,
     * registrando qual estratégia CONCRETA prevaleceu (usuario_ou_ip vira
     * "usuario" ou "ip" na chave — assim um mesmo cliente não ganha dois
     * saldos ao autenticar no meio da janela em fase futura de análise).
     * Retorna: par [estratégia efetiva, identificador]. Efeitos colaterais:
     * nenhum.
     *
     * @return array{0: EstrategiaChave, 1: string}
     */
    private function identificarCliente(Request $request, EstrategiaChave $estrategia): array
    {
        $usuario = $request->user();

        return match ($estrategia) {
            EstrategiaChave::Usuario => [
                EstrategiaChave::Usuario,
                // Sem usuário autenticado numa política "usuario", cai para
                // "anonimo" de forma explícita: melhor um balde único visível
                // do que uma exceção em rota pública mal configurada.
                $usuario !== null ? (string) $usuario->getAuthIdentifier() : 'anonimo',
            ],
            EstrategiaChave::Ip => [
                EstrategiaChave::Ip,
                (string) $request->ip(),
            ],
            EstrategiaChave::UsuarioOuIp => $usuario !== null
                ? [EstrategiaChave::Usuario, (string) $usuario->getAuthIdentifier()]
                : [EstrategiaChave::Ip, (string) $request->ip()],
        };
    }
}
