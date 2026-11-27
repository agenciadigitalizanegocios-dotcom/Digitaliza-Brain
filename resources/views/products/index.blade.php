@extends('layouts.app')

@section('title', 'Produtos')
@section('main-class', 'shell')

@section('content')
<div class="layout">
    @include('brain.partials.nav', ['nav' => \App\Brain\Navigation::build(), 'active' => 'produtos'])

    <section class="content">
        <div class="section-head">
            <div>
                <h1><span class="h-icon">🛍️</span> Produtos</h1>
                <p class="muted">{{ $type?->intro ?: 'O que aparece na vitrine e o que o robô oferece aos clientes.' }}</p>
                <p class="small"><a href="{{ $currentTenant->vitrineUrl() }}" target="_blank" rel="noopener">Abrir a vitrine ↗</a></p>
            </div>
            @if ($type && ($canEdit ?? true))
                <a class="btn primary" href="{{ route('products.create', $type->key) }}">+ Cadastrar {{ mb_strtolower($type->name) }}</a>
            @endif
        </div>

        @if ($types->count() > 1)
            <nav class="pill-tabs">
                @foreach ($types as $t)
                    <a href="{{ route('products.index', ['produto' => $t->key]) }}" class="{{ $type?->id === $t->id ? 'active' : '' }}">{{ $t->icon }} {{ $t->plural ?: $t->name }}</a>
                @endforeach
            </nav>
        @endif

        @if (! $type)
            <div class="empty card">
                <div class="empty-icon">🛍️</div>
                <h2>Nenhum tipo de produto ainda</h2>
                <p class="muted">A Digitaliza monta os tipos de produto (ex.: Imóvel) e os campos de cada um. Fale com a gente.</p>
            </div>
        @else
            <form method="get" class="toolbar">
                <input type="hidden" name="produto" value="{{ $type->key }}">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Buscar pelo título ou descrição">
                <select name="status" data-autosubmit>
                    <option value="">Todos os status</option>
                    @foreach (['disponivel', 'reservado', 'indisponivel'] as $st)
                        <option value="{{ $st }}" @selected(request('status') === $st)>{{ $type->statusLabel($st) }}</option>
                    @endforeach
                </select>
                <select name="publicado" data-autosubmit>
                    <option value="">Na vitrine ou não</option>
                    <option value="nao" @selected(request('publicado') === 'nao')>Só os fora da vitrine</option>
                </select>
                <button class="btn small">Filtrar</button>
            </form>

            @if ($products->isEmpty())
                <div class="empty card">
                    <div class="empty-icon">{{ $type->icon }}</div>
                    <h2>Nada encontrado</h2>
                    <p class="muted">Cadastre o primeiro {{ mb_strtolower($type->name) }} ou mude os filtros.</p>
                </div>
            @else
                <div class="product-grid">
                    @foreach ($products as $p)
                        @php $cover = $p->images->first(); @endphp
                        <article class="product-card {{ $p->isVisible() ? '' : 'is-off' }}">
                            <a href="{{ route('products.edit', $p) }}" class="product-cover">
                                @if ($cover)
                                    <img src="{{ $cover->url() }}" alt="" loading="lazy">
                                @else
                                    <span class="no-photo">{{ $type->icon }}</span>
                                @endif
                            </a>
                            <div class="product-body">
                                <div class="badges">
                                    <span class="badge badge-{{ $p->status }}">{{ $type->statusLabel($p->status) }}</span>
                                    @if ($p->featured)<span class="badge on">Destaque</span>@endif
                                    @unless ($p->published)<span class="badge paused">Fora da vitrine</span>@endunless
                                </div>
                                <h3><a href="{{ route('products.edit', $p) }}">{{ $p->title() }}</a></h3>
                                @if ($p->priceText())<p class="price">{{ $p->priceText() }}</p>@endif
                                <p class="muted small">
                                    @foreach ($type->card_fields ?? [] as $k)
                                        @php $f = $type->field($k); $v = $f ? \App\Fields\FieldTypes::display($f, $p->data[$k] ?? null) : ''; @endphp
                                        @if ($v !== '') {{ $f['type'] === 'number' ? $v.' '.mb_strtolower($f['label']) : $v }}@if (! $loop->last) · @endif @endif
                                    @endforeach
                                </p>
                            </div>
                            @if ($canEdit ?? true)
                                <footer>
                                    <a class="btn small" href="{{ route('products.edit', $p) }}">Editar</a>
                                    <form method="post" action="{{ route('products.toggle', $p) }}">
                                        @csrf @method('PATCH')
                                        <button class="btn small ghost">{{ $p->published ? 'Tirar da vitrine' : 'Publicar' }}</button>
                                    </form>
                                </footer>
                            @endif
                        </article>
                    @endforeach
                </div>
                {{ $products->links('brain.partials.pagination') }}
            @endif
        @endif
    </section>
</div>
@endsection
