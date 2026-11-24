<?php

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra toda consulta pela imobiliária atual.
 * Na web, sem imobiliária definida, não devolve nada (nunca vaza dado de outro cliente).
 * No terminal (seed, comandos), sem imobiliária definida, não filtra.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentTenant::class)->id();

        if ($tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenantId);

            return;
        }

        if (! app()->runningInConsole()) {
            $builder->whereRaw('1 = 0');
        }
    }
}
