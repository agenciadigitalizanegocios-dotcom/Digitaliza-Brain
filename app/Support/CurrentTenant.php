<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Guarda qual imobiliária está sendo usada nesta requisição.
 * É preenchido pelo middleware do painel (usuário logado) ou da API (token).
 */
class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }
}
