@extends('layouts.app')

@php $isNew = ! $product->exists; @endphp
@section('title', $isNew ? 'Novo '.mb_strtolower($type->name) : $product->title())
@section('main-class', 'shell')

@section('content')
<div class="layout">
    @include('brain.partials.nav', ['nav' => \App\Brain\Navigation::build(), 'active' => 'produtos'])

    <section class="content">
        <a href="{{ route('products.index', ['produto' => $type->key]) }}" class="back">← {{ $type->plural ?: $type->name }}</a>

        <div class="section-head">
            <div>
                <h1>{{ $type->icon }} {{ $isNew ? 'Cadastrar '.mb_strtolower($type->name) : $product->title() }}</h1>
                @if (! $isNew && $product->isVisible())
                    <p class="small"><a href="{{ $currentTenant->vitrineUrl($product->urlSlug()) }}" target="_blank" rel="noopener">Ver na vitrine ↗</a></p>
                @endif
            </div>
        </div>

        @error('limite') <div class="flash error">{{ $message }}</div> @enderror

        <form method="post" enctype="multipart/form-data" class="card form-grid" data-dirty-check
              action="{{ $isNew ? route('products.store', $type->key) : route('products.update', $product) }}">
            @csrf
            @unless ($isNew) @method('PUT') @endunless
            <fieldset class="contents" @disabled(! ($canEdit ?? true))>

            <div class="field">
                <label class="label" for="status">Status</label>
                <select id="status" name="status">
                    @foreach (['disponivel', 'reservado', 'indisponivel'] as $st)
                        <option value="{{ $st }}" @selected(old('status', $product->status) === $st)>{{ $type->statusLabel($st) }}</option>
                    @endforeach
                </select>
                <p class="help">“{{ $type->statusLabel('indisponivel') }}” some da vitrine e da busca do robô.</p>
            </div>
            <div class="field">
                <span class="label">Vitrine</span>
                <label class="check"><input type="hidden" name="published" value="0"><input type="checkbox" name="published" value="1" @checked(old('published', $product->published))> Publicado na vitrine</label>
                <label class="check"><input type="hidden" name="featured" value="0"><input type="checkbox" name="featured" value="1" @checked(old('featured', $product->featured))> Destaque (aparece primeiro)</label>
            </div>

            @foreach ($type->activeFields() as $f)
                @include('brain.partials.field', [
                    'f' => $f,
                    'value' => old('fields.'.$f['key'], \App\Brain\FormValues::one($f, $product->data[$f['key']] ?? ($isNew && $f['type'] === 'checkbox' ? ($f['default'] ?? false) : null))),
                ])
            @endforeach

            <div class="field wide {{ $errors->has('photos') || $errors->has('photos.*') ? 'has-error' : '' }}">
                <label class="label" for="photos">{{ $isNew ? 'Fotos' : 'Adicionar fotos' }}</label>
                <input id="photos" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                <p class="help">Até {{ \App\Catalog\Catalog::PHOTOS_PER_UPLOAD }} fotos por vez (no máximo {{ \App\Catalog\Catalog::MAX_PHOTOS }} no produto), JPG, PNG ou WEBP, até 5 MB cada. A primeira é a capa.</p>
                @foreach ($errors->get('photos*') as $msgs)
                    @foreach ((array) $msgs as $msg)<p class="field-error">{{ $msg }}</p>@endforeach
                @endforeach
            </div>
            </fieldset>

            @if ($canEdit ?? true)
                <div class="actions wide">
                    <span class="unsaved" hidden>Alterações não salvas</span>
                    <button class="btn primary">{{ $isNew ? 'Cadastrar' : 'Salvar' }}</button>
                </div>
            @endif
        </form>

        @unless ($isNew)
            <div class="card">
                <div class="card-head"><h2>Fotos ({{ $product->images->count() }})</h2></div>
                @if ($product->images->isEmpty())
                    <p class="muted">Sem fotos. Produto com foto chama muito mais atenção na vitrine.</p>
                @else
                    <div class="photo-grid">
                        @foreach ($product->images as $img)
                            <figure class="photo">
                                <img src="{{ $img->url() }}" alt="" loading="lazy">
                                @if ($loop->first)<span class="badge on">Capa</span>@endif
                                @if ($canEdit ?? true)
                                    <figcaption>
                                        <form method="post" action="{{ route('products.photos.move', [$product, $img->id, 'subir']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" title="Para a frente" @disabled($loop->first)>←</button></form>
                                        <form method="post" action="{{ route('products.photos.move', [$product, $img->id, 'descer']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" title="Para trás" @disabled($loop->last)>→</button></form>
                                        <form method="post" action="{{ route('products.photos.destroy', [$product, $img->id]) }}" data-confirm="Remover esta foto?">@csrf @method('DELETE')<button class="btn small ghost danger">Remover</button></form>
                                    </figcaption>
                                @endif
                            </figure>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($canEdit ?? true)
                <form method="post" action="{{ route('products.destroy', $product) }}" class="danger-zone"
                      data-confirm="Excluir este produto e todas as fotos? Não dá para desfazer. Se for só tirar da vitrine, use o status ou desmarque “Publicado”.">
                    @csrf @method('DELETE')
                    <button class="btn ghost danger">Excluir produto</button>
                </form>
            @endif
        @endunless
    </section>
</div>
@endsection
