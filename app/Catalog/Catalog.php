<?php

namespace App\Catalog;

use App\Fields\FieldTypes;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Regras do catálogo compartilhadas pela vitrine, pelo painel e pela API do robô:
 * filtros, ordenação e o formato de cada produto na resposta.
 */
final class Catalog
{
    public const MAX_PRODUCTS = 1000;

    public const MAX_PHOTOS = 20;

    /** Fotos por envio do formulário (o servidor aceita até 40 MB por envio). */
    public const PHOTOS_PER_UPLOAD = 6;

    /** Linha de product_types a partir da definição do modelo (para o DB::table()->insert). */
    public static function typeRow(array $type, int $tenantId, int $position): array
    {
        return [
            'tenant_id' => $tenantId,
            'key' => $type['key'],
            'name' => $type['name'],
            'plural' => $type['plural'] ?? null,
            'icon' => $type['icon'] ?? null,
            'intro' => $type['intro'] ?? null,
            'fields' => json_encode($type['fields'] ?? [], JSON_UNESCAPED_UNICODE),
            'title_field' => $type['title_field'] ?? null,
            'price_field' => $type['price_field'] ?? null,
            'price_suffix' => $type['price_suffix'] ?? null,
            'card_fields' => json_encode($type['card_fields'] ?? []),
            'filters' => json_encode($type['filters'] ?? []),
            'status_labels' => json_encode($type['status_labels'] ?? self::defaultStatusLabels(), JSON_UNESCAPED_UNICODE),
            'position' => $position,
            'archived' => false,
        ];
    }

    public static function defaultStatusLabels(): array
    {
        return ['disponivel' => 'Disponível', 'reservado' => 'Reservado', 'indisponivel' => 'Indisponível'];
    }

    /** Copia os tipos de produto do modelo para um cliente que ainda não tem nenhum. */
    public static function provision(Tenant $tenant, array $templateStructure): void
    {
        if (ProductType::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            return;
        }
        foreach ($templateStructure['catalog_types'] ?? [] as $i => $type) {
            $row = self::typeRow($type, $tenant->id, $i + 1);
            foreach (['fields', 'card_fields', 'filters', 'status_labels'] as $k) {
                $row[$k] = json_decode($row[$k], true);
            }
            ProductType::withoutGlobalScopes()->create($row);
        }
    }

    /** Só o que aparece para o público e para o robô: publicado e não indisponível. */
    public static function visible(Builder $query): Builder
    {
        return $query->where('published', true)->where('status', '!=', 'indisponivel');
    }

    /**
     * Aplica os filtros.
     *   texto e opções: {chave}=valor (texto: "contém"; opções: igual)
     *   número e dinheiro: {chave}_min / {chave}_max (dinheiro em reais: "1500" ou "1.500,00")
     *   sim/não: {chave}=1
     *   busca livre: q=palavras (título e textos)
     * Só valem os campos marcados como filtro no tipo (e "q").
     */
    public static function applyFilters(Builder $query, ProductType $type, array $input): Builder
    {
        $fields = $type->activeFields();

        foreach ($type->filters ?? [] as $key) {
            $f = $fields[$key] ?? null;
            if (! $f) {
                continue;
            }
            $col = 'data->'.$key;

            switch ($f['type']) {
                case 'select':
                    if (filled($input[$key] ?? null) && isset($f['options'][$input[$key]])) {
                        $query->where($col, $input[$key]);
                    }
                    break;
                case 'text':
                    if (filled($input[$key] ?? null)) {
                        $query->where($col, 'like', '%'.self::escapeLike(mb_substr((string) $input[$key], 0, 100)).'%');
                    }
                    break;
                case 'checkbox':
                    if (filter_var($input[$key] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        $query->where($col, true);
                    }
                    break;
                case 'number':
                case 'money':
                    foreach (['min' => '>=', 'max' => '<='] as $suffix => $op) {
                        $raw = $input[$key.'_'.$suffix] ?? null;
                        if ($raw === null || $raw === '') {
                            continue;
                        }
                        $value = self::numeric($f['type'], (string) $raw);
                        if ($value !== null) {
                            $query->where($col, $op, $value);
                        }
                    }
                    break;
            }
        }

        $q = trim((string) ($input['q'] ?? ''));
        if ($q !== '') {
            $q = self::escapeLike(mb_substr($q, 0, 100));
            $textKeys = array_keys(array_filter($fields, fn ($f) => in_array($f['type'], ['text', 'textarea'], true)
                && ($f['public'] ?? true) !== false));
            $query->where(function (Builder $w) use ($textKeys, $q) {
                foreach ($textKeys as $k) {
                    $w->orWhere('data->'.$k, 'like', '%'.$q.'%');
                }
            });
        }

        return $query;
    }

    /** Ordenação: destaques primeiro; depois recentes, menor ou maior preço. */
    public static function applySort(Builder $query, ProductType $type, ?string $sort): Builder
    {
        $query->orderByDesc('featured');
        $price = $type->price_field ? 'data->'.$type->price_field : null;

        return match (true) {
            $sort === 'menor_preco' && $price !== null => $query->orderBy($price)->orderByDesc('id'),
            $sort === 'maior_preco' && $price !== null => $query->orderByDesc($price)->orderByDesc('id'),
            default => $query->orderByDesc('id'),
        };
    }

    /** Valores que existem hoje num campo de texto filtrável (para virar lista na vitrine). */
    public static function distinctValues(ProductType $type, string $key): array
    {
        // Guarda por 5 minutos; muda a chave quando algum produto do tipo é alterado.
        $stamp = Product::where('product_type_id', $type->id)->max('updated_at').'|'.Product::where('product_type_id', $type->id)->count();
        $cacheKey = 'vitrine:valores:'.$type->tenant_id.':'.$type->id.':'.$key.':'.md5((string) $stamp);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, fn () => self::loadDistinct($type, $key));
    }

    private static function loadDistinct(ProductType $type, string $key): array
    {
        $values = self::visible(Product::query()->where('product_type_id', $type->id))
            ->get(['data'])
            ->map(fn ($p) => trim((string) ($p->data[$key] ?? '')))
            ->filter()
            ->unique(fn ($v) => mb_strtolower($v))
            ->sort(fn ($a, $b) => strcoll($a, $b))
            ->values()
            ->all();

        return array_slice($values, 0, 200);
    }

    /**
     * Produto no formato da API do robô.
     * Só campos que vão para a IA (send_to_ai) — ex.: o endereço completo não sai.
     */
    public static function forApi(Product $product, ProductType $type, string $vitrineUrl): array
    {
        $fields = [];
        $lines = [];
        foreach ($type->activeFields() as $key => $f) {
            if (($f['send_to_ai'] ?? true) === false || $f['type'] === 'image') {
                continue;
            }
            $value = $product->data[$key] ?? null;
            $fields[$key] = FieldTypes::apiValue($f, $value);
            $text = FieldTypes::aiText($f, $value);
            if ($text !== '' && $key !== $type->title_field) {
                $lines[] = $f['label'].': '.$text;
            }
        }

        $photos = $product->images->map->url()->values()->all();

        return [
            'id' => $product->id,
            'type' => $type->key,
            'title' => $product->title(),
            'status' => $product->status,
            'status_label' => $type->statusLabel($product->status),
            'featured' => $product->featured,
            'price' => $type->price_field ? FieldTypes::apiValue(['type' => 'money'], $product->data[$type->price_field] ?? null) : null,
            'price_text' => $product->priceText() ?: null,
            'url' => $vitrineUrl,
            'cover' => $photos[0] ?? null,
            'photos' => $photos,
            'fields' => $fields,
            // Resumo pronto para a IA usar na resposta.
            'summary' => trim($product->title().($product->priceText() ? ' — '.$product->priceText() : '').'. '.implode('; ', $lines)),
        ];
    }

    private static function numeric(string $type, string $raw): ?int
    {
        if ($type === 'money') {
            try {
                return FieldTypes::parseMoney($raw);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return is_numeric($raw) ? (int) $raw : null;
    }

    /** Tira os curingas do LIKE do que o visitante digitou (o SQLite não tem escape padrão). */
    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ' ', $value);
    }
}
