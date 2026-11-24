<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Modelo de nicho (Imobiliária, Escola técnica...). Não pertence a cliente: sem BelongsToTenant. */
class BrainTemplate extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'version', 'structure'];

    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'version' => 'integer',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'template_id');
    }
}
