<?php

namespace App\Http\Controllers\Admin;

use App\Brain\StructureValidator;
use App\Catalog\Catalog;
use App\Catalog\ProductTypeValidator;
use App\Fields\FieldDefinitions;
use App\Fields\FieldTypes;
use App\Http\Controllers\Controller;
use App\Models\ProductType;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tipos de produto de um cliente (só admin): Imóvel, Armação, Curso...
 * Mesmas regras do editor do cérebro: chaves fixas, nada é excluído (só arquivado).
 */
class CatalogTypeController extends Controller
{
    public function show(Request $request, Tenant $tenant)
    {
        $this->enter($tenant);
        $types = ProductType::orderBy('position')->get();
        $type = $types->firstWhere('key', $request->query('tipo')) ?? $types->first();

        return view('admin.catalog', [
            'tenant' => $tenant,
            'types' => $types,
            'type' => $type,
            'fieldTypes' => FieldTypes::TYPES,
            'filterable' => ProductTypeValidator::FILTERABLE,
        ]);
    }

    public function storeType(Request $request, Tenant $tenant)
    {
        $this->enter($tenant);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:8'],
        ], [], ['name' => 'nome do tipo']);

        if (ProductType::where('archived', false)->count() >= ProductTypeValidator::MAX_TYPES) {
            throw ValidationException::withMessages(['structure' => ['No máximo '.ProductTypeValidator::MAX_TYPES.' tipos de produto ativos por cliente. Arquive um para criar outro.']]);
        }
        $key = StructureValidator::makeKey($data['name'], ProductType::pluck('key')->all(), 'tipo');
        $definition = [
            'key' => $key, 'name' => $data['name'], 'plural' => $data['name'], 'icon' => ($data['icon'] ?? null) ?: '📦',
            'intro' => '', 'title_field' => 'titulo', 'price_field' => null, 'price_suffix' => null,
            'card_fields' => [], 'filters' => [], 'status_labels' => Catalog::defaultStatusLabels(), 'archived' => false,
            'fields' => [FieldDefinitions::make('titulo', 'Título', 'text', true)],
        ];
        $this->assertValid($definition, null, ProductType::pluck('key')->all());

        $row = Catalog::typeRow($definition, $tenant->id, (int) ProductType::max('position') + 1);
        foreach (['fields', 'card_fields', 'filters', 'status_labels'] as $k) {
            $row[$k] = json_decode($row[$k], true);
        }
        ProductType::create($row);
        Audit::log('catalogo_tipo', $tenant->id, ['criou' => $data['name']]);

        return redirect()->route('admin.catalog.show', [$tenant, 'tipo' => $key])->with('ok', 'Tipo de produto criado.');
    }

    public function updateType(Request $request, Tenant $tenant, string $type)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'plural' => ['nullable', 'string', 'max:60'],
            'icon' => ['nullable', 'string', 'max:8'],
            'intro' => ['nullable', 'string', 'max:500'],
            'title_field' => ['required', 'string', 'max:40'],
            'price_field' => ['nullable', 'string', 'max:40'],
            'price_suffix' => ['nullable', 'string', 'max:20'],
            'card_fields' => ['nullable', 'array'],
            'card_fields.*' => ['string', 'max:40'],
            'filters' => ['nullable', 'array'],
            'filters.*' => ['string', 'max:40'],
            'status_labels' => ['required', 'array'],
            'status_labels.*' => ['nullable', 'string', 'max:30'],
        ]);

        return $this->mutate($tenant, $type, function (array $d) use ($data) {
            foreach (['name', 'plural', 'intro', 'title_field', 'price_suffix'] as $k) {
                $d[$k] = $data[$k] ?? ($k === 'name' ? $d['name'] : null);
            }
            $d['icon'] = ($data['icon'] ?? null) ?: $d['icon'];
            $d['price_field'] = ($data['price_field'] ?? null) ?: null;
            $d['card_fields'] = array_values($data['card_fields'] ?? []);
            $d['filters'] = array_values($data['filters'] ?? []);
            $d['status_labels'] = array_map('trim', array_intersect_key($data['status_labels'], array_flip(ProductTypeValidator::STATUSES)))
                + Catalog::defaultStatusLabels();

            return [$d, 'editou o tipo'];
        });
    }

    public function archiveType(Tenant $tenant, string $type)
    {
        return $this->mutate($tenant, $type, function (array $d) {
            $d['archived'] = empty($d['archived']);

            return [$d, $d['archived'] ? 'arquivou o tipo' : 'desarquivou o tipo'];
        });
    }

    public function storeField(Request $request, Tenant $tenant, string $type)
    {
        $data = FieldDefinitions::validate($request);

        $response = $this->mutate($tenant, $type, function (array $d) use ($data) {
            $key = ProductTypeValidator::newFieldKey($data['label'], array_column($d['fields'], 'key'));
            $d['fields'][] = FieldDefinitions::fill(FieldDefinitions::make($key, $data['label'], $data['type'], (bool) ($data['required'] ?? false)), $data);

            return [$d, "adicionou o campo “{$data['label']}”"];
        });

        return $this->warnRequired($response, $type, $data);
    }

    public function updateField(Request $request, Tenant $tenant, string $type, string $field)
    {
        $data = FieldDefinitions::validate($request);

        $response = $this->mutate($tenant, $type, function (array $d) use ($data, $field) {
            $found = false;
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === $field) {
                    $f = FieldDefinitions::fill($f, $data);
                    $found = true;
                }
            }
            unset($f);
            abort_unless($found, 404);

            return [$d, "editou o campo “{$data['label']}”"];
        });

        return $this->warnRequired($response, $type, $data);
    }

    public function archiveField(Tenant $tenant, string $type, string $field)
    {
        return $this->mutate($tenant, $type, function (array $d) use ($field) {
            $msg = null;
            foreach ($d['fields'] as &$f) {
                if ($f['key'] === $field) {
                    $f['archived'] = empty($f['archived']);
                    $msg = ($f['archived'] ? 'arquivou' : 'desarquivou')." o campo “{$f['label']}”";
                }
            }
            unset($f);
            abort_unless($msg, 404);
            // Campo arquivado sai dos filtros e do cartão.
            $d['filters'] = array_values(array_diff($d['filters'] ?? [], [$field]));
            $d['card_fields'] = array_values(array_diff($d['card_fields'] ?? [], [$field]));

            return [$d, $msg];
        });
    }

    public function moveField(Tenant $tenant, string $type, string $field, string $direction)
    {
        return $this->mutate($tenant, $type, function (array $d) use ($field, $direction) {
            $list = array_values($d['fields']);
            $i = array_search($field, array_column($list, 'key'), true);
            abort_if($i === false, 404);
            $j = $direction === 'subir' ? $i - 1 : $i + 1;
            if ($j >= 0 && $j < count($list)) {
                [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            }
            $d['fields'] = $list;

            return [$d, 'mudou a ordem dos campos'];
        });
    }

    // ---------------------------------------------------------------------

    private function enter(Tenant $tenant): void
    {
        app(CurrentTenant::class)->set($tenant);
    }

    private function mutate(Tenant $tenant, string $typeKey, callable $change)
    {
        $this->enter($tenant);
        $model = ProductType::where('key', $typeKey)->firstOrFail();
        $old = $model->definition();
        [$new, $what] = $change($old);

        $this->assertValid($new, $old, ProductType::where('id', '!=', $model->id)->pluck('key')->all());

        $model->fill(collect($new)->except('key')->all())->save();
        Audit::log('catalogo_tipo', $tenant->id, ['tipo' => $model->name, 'o_que' => $what]);

        return redirect()->route('admin.catalog.show', [$tenant, 'tipo' => $typeKey])->with('ok', 'Tipo de produto salvo.');
    }

    /** Campo obrigatório num tipo que já tem produtos: avisa quantos ficam sem ele. */
    private function warnRequired($response, string $typeKey, array $data)
    {
        if (! empty($data['required'])) {
            $total = \App\Models\Product::whereHas('type', fn ($q) => $q->where('key', $typeKey))->count();
            if ($total > 0) {
                $response->with('ok', "Campo salvo. Atenção: {$total} produto(s) já cadastrado(s) estão sem esse campo. "
                    .'Eles continuam na vitrine; o cliente preenche quando for editar cada um.');
            }
        }

        return $response;
    }

    private function assertValid(array $definition, ?array $old, array $otherKeys): void
    {
        $errors = ProductTypeValidator::validate($definition, $old, $otherKeys);
        if ($errors) {
            throw ValidationException::withMessages(['structure' => $errors]);
        }
    }
}
