<?php

namespace App\Http\Controllers\Api;

use App\Catalog\Catalog;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductType;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;

/**
 * Busca de produtos para o robô (n8n). Mesmo token e mesmas regras da /brain (só rede interna).
 *   GET /api/v1/products?produto=imovel&bairro=Centro&aluguel_max=1500&quartos_min=2&aceita_pet=1&q=patio&limite=5
 *   GET /api/v1/products/{id}
 * Só produtos publicados e não indisponíveis.
 */
class ProductApiController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $this->tenantWithCatalog();
        $type = $this->type($request->query('produto'));

        $query = Catalog::visible(Product::with('images')->where('product_type_id', $type->id));
        Catalog::applyFilters($query, $type, $request->query());
        Catalog::applySort($query, $type, $request->query('ordem'));

        $limit = max(1, min(20, (int) $request->query('limite', 5)));
        $total = (clone $query)->count();
        $items = $query->limit($limit)->get()
            ->map(fn (Product $p) => Catalog::forApi($p, $type, $tenant->vitrineUrl($p->urlSlug())))
            ->all();

        return response()->json([
            'type' => $this->describe($type),
            'total' => $total,
            'count' => count($items),
            'vitrine' => $tenant->vitrineUrl(),
            'items' => $items,
        ]);
    }

    public function show(int $id)
    {
        $tenant = $this->tenantWithCatalog();
        $product = Catalog::visible(Product::with('images', 'type'))->find($id);

        if (! $product || $product->type->archived) {
            return response()->json(['message' => 'Produto não encontrado ou indisponível.'], 404);
        }

        return response()->json(Catalog::forApi($product, $product->type, $tenant->vitrineUrl($product->urlSlug())));
    }

    /** Tipos de produto e os filtros que cada um aceita (para montar a ferramenta da IA). */
    public function types()
    {
        $this->tenantWithCatalog();

        return response()->json([
            'types' => ProductType::where('archived', false)->orderBy('position')->get()->map(fn ($t) => $this->describe($t))->all(),
        ]);
    }

    private function tenantWithCatalog()
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant->moduleFlags()['catalogo'], 404, 'O módulo Catálogo está desligado para este cliente.');

        return $tenant;
    }

    private function type(?string $key): ProductType
    {
        $query = ProductType::where('archived', false)->orderBy('position');
        $type = $key ? (clone $query)->where('key', $key)->first() : $query->first();
        abort_unless($type, 404, 'Tipo de produto não encontrado.');

        return $type;
    }

    /** Como filtrar este tipo: nome do parâmetro e o que ele espera. */
    private function describe(ProductType $type): array
    {
        $filters = [];
        foreach ($type->filters ?? [] as $key) {
            $f = $type->field($key);
            if (! $f) {
                continue;
            }
            $filters[] = match ($f['type']) {
                'select' => ['param' => $key, 'label' => $f['label'], 'kind' => 'opção', 'options' => $f['options']],
                'checkbox' => ['param' => $key, 'label' => $f['label'], 'kind' => 'sim/não (use 1)'],
                'number' => ['param' => $key.'_min | '.$key.'_max', 'label' => $f['label'], 'kind' => 'número'],
                'money' => ['param' => $key.'_min | '.$key.'_max', 'label' => $f['label'], 'kind' => 'dinheiro em reais (ex.: 1500)'],
                default => ['param' => $key, 'label' => $f['label'], 'kind' => 'texto (contém)'],
            };
        }

        return ['key' => $type->key, 'name' => $type->name, 'filters' => $filters, 'search' => 'q (texto livre)'];
    }
}
