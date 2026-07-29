<?php

declare(strict_types=1);

namespace App\RateLimiting\Support;

/**
 * Estratégias de identificação do cliente para composição da chave de
 * limitação.
 *
 * Responsabilidade: enumerar, de forma fechada e tipada, como o limitador
 * decide QUEM está sendo limitado. O valor string de cada caso é o mesmo
 * usado em config/rate_limiting.php e dentro da chave Redis.
 */
enum KeyStrategy: string
{
    // Limita por usuário autenticado (id do usuário como identificador).
    case User = 'user';

    // Limita por endereço IP de origem.
    case Ip = 'ip';

    // Usa o usuário autenticado quando existir; caso contrário, cai para o IP.
    case UserOrIp = 'user_or_ip';
}
