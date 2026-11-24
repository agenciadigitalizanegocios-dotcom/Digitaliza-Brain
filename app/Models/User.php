<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    /** Admin da Digitaliza: vê todos os clientes. Não tem tenant. */
    public const ROLE_ADMIN = 'admin';

    /** Dono da imobiliária. */
    public const ROLE_OWNER = 'dono';

    /** Corretor (usado nas próximas etapas). */
    public const ROLE_AGENT = 'corretor';

    protected $fillable = ['tenant_id', 'name', 'email', 'password', 'role'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
