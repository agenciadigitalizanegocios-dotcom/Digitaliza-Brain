<?php

namespace App\Http\Controllers;

use App\Catalog\Catalog;
use App\Models\Product;
use App\Models\ProductType;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;

/**
 * Vitrine pública de cada cliente. Sem login.
 * Só mostra: cliente ativo com o módulo Catálogo ligado, produtos publicados e não indisponíveis,
 * e só os campos marcados para aparecer na vitrine.
 */
class VitrineController extends Controller
{
    /** Raiz do endereço da vitrine: não lista os clientes (cada um tem o seu link). */
    public function home()
    {
        abort(404);
    }

    public function index(Request $request, string $slug)
    {
        $tenant = $this->tenant($slug);
        $types = ProductType::where('archived', false)->orderBy('position')->get();
        $type = $types->firstWhere('key', $request->query('produto')) ?? $types->first();
        abort_unless($type, 404);

        $input = $request->only(array_merge(['q'], $this->filterParams($type)));
        $query = Catalog::visible(Product::with('images')->where('product_type_id', $type->id));
        Catalog::applyFilters($query, $type, $input);
        Catalog::applySort($query, $type, $request->query('ordem'));

        $choices = [];
        foreach ($type->filters ?? [] as $key) {
            $f = $type->field($key);
            if ($f && $f['type'] === 'text') {
                $choices[$key] = Catalog::distinctValues($type, $key);
            }
        }

        return $this->robots($tenant, view('vitrine.index', [
            'tenant' => $tenant,
            'settings' => $tenant->vitrineSettings(),
            'types' => $types,
            'type' => $type,
            'products' => $query->paginate(12)->withQueryString(),
            'choices' => $choices,
            'input' => $input,
            'filtering' => (bool) array_filter($input, fn ($v) => $v !== null && $v !== ''),
        ]));
    }

    public function show(string $slug, string $product)
    {
        $tenant = $this->tenant($slug);

        // O id é o número no fim do endereço: "casa-2-quartos-centro-15".
        abort_unless(preg_match('/-(\d+)$/', $product, $m) || ctype_digit($product), 404);
        $id = (int) ($m[1] ?? $product);

        $model = Catalog::visible(Product::with('images', 'type'))->find($id);
        abort_unless($model && ! $model->type->archived, 404);

        // Endereço antigo (título mudou): manda para o atual.
        if ($product !== $model->urlSlug()) {
            return redirect()->to($tenant->vitrineUrl($model->urlSlug()), 301);
        }

        return $this->robots($tenant, view('vitrine.show', [
            'tenant' => $tenant,
            'settings' => $tenant->vitrineSettings(),
            'product' => $model,
            'type' => $model->type,
        ]));
    }

    /** Vitrine em montagem: avisa os buscadores para não indexar. */
    private function robots(Tenant $tenant, $view)
    {
        $response = response($view);
        if ($tenant->vitrineSettings()['building']) {
            $response->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::where('slug', $slug)->first();
        abort_unless($tenant && $tenant->hasVitrine(), 404);
        app(CurrentTenant::class)->set($tenant);

        return $tenant;
    }

    /** Nomes dos parâmetros de filtro aceitos para o tipo. */
    private function filterParams(ProductType $type): array
    {
        $params = [];
        foreach ($type->filters ?? [] as $key) {
            $f = $type->field($key);
            if (! $f) {
                continue;
            }
            if (in_array($f['type'], ['number', 'money'], true)) {
                $params[] = $key.'_min';
                $params[] = $key.'_max';
            } else {
                $params[] = $key;
            }
        }

        return $params;
    }
}
