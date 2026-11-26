<?php

namespace App\Models;

use App\Fields\FieldTypes;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Produto do catálogo. Os valores dos campos ficam em "data" (chave do campo => valor). */
class Product extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'product_type_id', 'data', 'status', 'published', 'featured', 'external_id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'published' => 'boolean',
            'featured' => 'boolean',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    public function title(): string
    {
        $key = $this->type?->title_field;

        return trim((string) ($key ? ($this->data[$key] ?? '') : '')) ?: 'Sem título';
    }

    /** Preço formatado ("R$ 1.500,00/mês") ou vazio. */
    public function priceText(): string
    {
        $key = $this->type?->price_field;
        $value = $key ? ($this->data[$key] ?? null) : null;

        return is_numeric($value) ? FieldTypes::formatMoney((int) $value).($this->type->price_suffix ?? '') : '';
    }

    /** Parte da URL da vitrine: "casa-2-quartos-centro-15" (o número no fim é o id). */
    public function urlSlug(): string
    {
        return trim(Str::limit(Str::slug($this->title()), 80, ''), '-').'-'.$this->id;
    }

    /** Aparece na vitrine e na busca do robô. */
    public function isVisible(): bool
    {
        return $this->published && $this->status !== 'indisponivel';
    }
}
