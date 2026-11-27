<?php

namespace App\Http\Controllers;

use App\Brain\FieldValidator;
use App\Catalog\Catalog;
use App\Fields\FieldTypes;
use App\Fields\ImageStore;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductType;
use App\Support\Audit;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Produtos no painel do cliente (/cerebro/produtos): listar, cadastrar, editar, galeria de fotos,
 * publicar na vitrine. Só com o módulo Catálogo ligado; corretor só visualiza.
 */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        $types = ProductType::where('archived', false)->orderBy('position')->get();
        $type = $types->firstWhere('key', $request->query('produto')) ?? $types->first();

        $products = collect();
        if ($type) {
            $query = Product::with('images')->where('product_type_id', $type->id);
            if (in_array($request->query('status'), ['disponivel', 'reservado', 'indisponivel'], true)) {
                $query->where('status', $request->query('status'));
            }
            if ($request->query('publicado') === 'nao') {
                $query->where('published', false);
            }
            Catalog::applyFilters($query, $type, ['q' => $request->query('q')]);
            $products = $query->orderByDesc('featured')->orderByDesc('id')->paginate(24)->withQueryString();
        }

        return view('products.index', ['types' => $types, 'type' => $type, 'products' => $products]);
    }

    public function create(string $type)
    {
        $type = $this->type($type);

        return view('products.form', ['type' => $type, 'product' => new Product(['status' => 'disponivel', 'published' => true, 'data' => []])]);
    }

    public function store(Request $request, string $type)
    {
        $type = $this->type($type);

        if (Product::count() >= Catalog::MAX_PRODUCTS) {
            throw ValidationException::withMessages(['limite' => 'Este cliente chegou ao máximo de '.Catalog::MAX_PRODUCTS.' produtos.']);
        }

        [$data, $meta] = $this->validateProduct($request, $type, []);

        $product = DB::transaction(function () use ($type, $data, $meta, $request) {
            $product = Product::create(['product_type_id' => $type->id, 'data' => $data] + $meta);
            $this->addPhotos($request, $product);

            return $product;
        });
        Audit::log('produto', app(CurrentTenant::class)->id(), ['acao' => 'cadastrou', 'produto' => $product->title(), 'id' => $product->id]);

        return redirect()->route('products.edit', [$product])->with('ok', 'Produto cadastrado.'
            .($product->published ? ' Já aparece na vitrine.' : ''));
    }

    public function edit(int $product)
    {
        $product = Product::with('images', 'type')->findOrFail($product);

        return view('products.form', ['type' => $product->type, 'product' => $product]);
    }

    public function update(Request $request, int $product)
    {
        $product = Product::with('type')->findOrFail($product);
        $old = $product->data ?? [];
        [$data, $meta] = $this->validateProduct($request, $product->type, $old);

        DB::transaction(function () use ($product, $old, $data, $meta, $request) {
            // Merge: campos arquivados continuam guardados.
            $product->update(['data' => array_merge($old, $data)] + $meta);
            $this->addPhotos($request, $product);
        });

        return redirect()->route('products.edit', [$product])->with('ok', 'Alterações salvas.');
    }

    public function toggle(int $product)
    {
        $product = Product::findOrFail($product);
        $product->update(['published' => ! $product->published]);
        Audit::log('produto', app(CurrentTenant::class)->id(), ['acao' => $product->published ? 'publicou' : 'tirou da vitrine', 'produto' => $product->title(), 'id' => $product->id]);

        return back()->with('ok', $product->published ? 'Publicado na vitrine.' : 'Tirado da vitrine (continua cadastrado).');
    }

    public function destroy(int $product)
    {
        $product = Product::with('images')->findOrFail($product);
        $typeKey = $product->type?->key;

        $paths = $product->images->pluck('path')->all();
        Audit::log('produto', app(CurrentTenant::class)->id(), ['acao' => 'excluiu', 'produto' => $product->title(), 'id' => $product->id]);
        $product->delete(); // as fotos saem do banco junto (cascade)
        Storage::disk('public')->delete($paths);

        return redirect()->route('products.index', ['produto' => $typeKey])->with('ok', 'Produto excluído.');
    }

    public function destroyPhoto(int $product, int $photo)
    {
        $image = ProductImage::where('product_id', $product)->findOrFail($photo);
        $image->delete();
        Storage::disk('public')->delete($image->path);
        $this->renumber($product);

        return redirect()->route('products.edit', [$product])->with('ok', 'Foto removida.');
    }

    public function movePhoto(int $product, int $photo, string $direction)
    {
        $list = ProductImage::where('product_id', $product)->orderBy('position')->get()->values();
        $i = $list->search(fn ($img) => $img->id === $photo);
        abort_if($i === false, 404);
        $j = $direction === 'subir' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $list->count()) {
            $tmp = $list[$i];
            $list[$i] = $list[$j];
            $list[$j] = $tmp;
            foreach ($list as $pos => $img) {
                $img->update(['position' => $pos + 1]);
            }
        }

        return redirect()->route('products.edit', [$product]);
    }

    // ---------------------------------------------------------------------

    private function type(string $key): ProductType
    {
        return ProductType::where('key', $key)->where('archived', false)->firstOrFail();
    }

    /** Valida campos (só os ativos), status, publicado, destaque e as fotos novas. */
    private function validateProduct(Request $request, ProductType $type, array $existing): array
    {
        $request->validate([
            'status' => ['required', Rule::in(['disponivel', 'reservado', 'indisponivel'])],
            'published' => ['nullable', 'boolean'],
            'featured' => ['nullable', 'boolean'],
            'photos' => ['nullable', 'array', 'max:'.Catalog::PHOTOS_PER_UPLOAD],
            'photos.*' => FieldTypes::imageRules(),
        ], [], ['photos' => 'fotos', 'photos.*' => 'foto']);

        $data = FieldValidator::validate($request, array_values($type->activeFields()), $existing);

        return [$data, [
            'status' => $request->input('status'),
            'published' => $request->boolean('published'),
            'featured' => $request->boolean('featured'),
        ]];
    }

    private function addPhotos(Request $request, Product $product): void
    {
        $files = $request->file('photos', []);
        if (! $files) {
            return;
        }
        $count = ProductImage::where('product_id', $product->id)->count();
        if ($count + count($files) > Catalog::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photos' => 'No máximo '.Catalog::MAX_PHOTOS.' fotos por produto.']);
        }
        $tenantId = app(CurrentTenant::class)->id();
        foreach ($files as $file) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => ImageStore::store($file, $tenantId, 'photos'),
                'position' => ++$count,
            ]);
        }
    }

    private function renumber(int $productId): void
    {
        foreach (ProductImage::where('product_id', $productId)->orderBy('position')->get()->values() as $i => $img) {
            if ($img->position !== $i + 1) {
                $img->update(['position' => $i + 1]);
            }
        }
    }
}
