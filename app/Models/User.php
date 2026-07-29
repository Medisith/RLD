<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Model de usuário (esqueleto padrão do Laravel).
 *
 * Responsabilidade: existir para o guard de autenticação padrão. Nas
 * Fases 0 e 1 a rota de teste é pública e a estratégia de chave
 * "usuario_ou_ip" cai para o IP quando não há usuário autenticado.
 */
class User extends Authenticatable
{
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Recebe: nada. Faz: declara os casts de atributos. Retorna: mapa de
     * casts. Efeitos colaterais: nenhum.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
