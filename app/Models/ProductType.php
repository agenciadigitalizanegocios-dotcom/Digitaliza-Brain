<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tipo de produto de um cliente (Imóvel, Armação, Curso...). Estrutura montada pela Digitaliza. */
class ProductType extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'key', 'name', 'plural', 'icon', 'intro', 'fields', 'title_field', 'price_field',
        'price_suffix', 'card_fields', 'filters', 'status_labels', 'position', 'archived'];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'card_fields' => 'array',
            'filters' => 'array',
            'status_labels' => 'array',
            'archived' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Campos ativos, por chave. */
    public function activeFields(): array
    {
        $out = [];
        foreach ($this->fields ?? [] as $f) {
            if (empty($f['archived'])) {
                $out[$f['key']] = $f;
            }
        }

        return $out;
    }

    /** Campos ativos que aparecem na vitrine. */
    public function publicFields(): array
    {
        return array_filter($this->activeFields(), fn ($f) => ($f['public'] ?? true) !== false);
    }

    public function field(string $key): ?array
    {
        return $this->activeFields()[$key] ?? null;
    }

    public function statusLabel(string $status): string
    {
        return $this->status_labels[$status] ?? ucfirst($status);
    }

    /** O tipo como array (formato do validador e dos modelos). */
    public function definition(): array
    {
        return $this->only(['key', 'name', 'plural', 'icon', 'intro', 'fields', 'title_field', 'price_field',
            'price_suffix', 'card_fields', 'filters', 'status_labels', 'archived']);
    }
}
