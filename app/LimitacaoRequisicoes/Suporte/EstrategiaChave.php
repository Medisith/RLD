<?php

declare(strict_types=1);

namespace App\LimitacaoRequisicoes\Suporte;

/**
 * Estratégias de identificação do cliente para composição da chave de
 * limitação.
 *
 * Responsabilidade: enumerar, de forma fechada e tipada, como o limitador
 * decide QUEM está sendo limitado. O valor string de cada caso é o mesmo
 * usado em config/limitacao_requisicoes.php e dentro da chave Redis.
 */
enum EstrategiaChave: string
{
    // Limita por usuário autenticado (id do usuário como identificador).
    case Usuario = 'usuario';

    // Limita por endereço IP de origem.
    case Ip = 'ip';

    // Usa o usuário autenticado quando existir; caso contrário, cai para o IP.
    case UsuarioOuIp = 'usuario_ou_ip';
}
