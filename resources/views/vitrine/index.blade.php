@extends('vitrine.layout')

@section('title', $type->plural ?: $type->name)
@section('canonical', $tenant->vitrineUrl())

@section('content')
    <div class="v-hero">
        <h1>{{ $type->plural ?: $type->name }}</h1>
        <p>{{ $products->total() }} {{ $products->total() === 1 ? 'opção' : 'opções' }}{{ $filtering ? ' com esses filtros' : '' }}</p>
    </div>

    @if ($types->count() > 1)
        <nav class="v-tabs">
            @foreach ($types as $t)
                <a href="{{ $tenant->vitrineUrl() }}?produto={{ $t->key }}" class="{{ $t->id === $type->id ? 'active' : '' }}">{{ $t->icon }} {{ $t->plural ?: $t->name }}</a>
            @endforeach
        </nav>
    @endif

    <div class="v-layout">
        <form method="get" class="v-filters" action="{{ $tenant->vitrineUrl() }}">
            @if ($types->count() > 1)<input type="hidden" name="produto" value="{{ $type->key }}">@endif
            <label class="v-field">
                <span>Buscar</span>
                <input type="search" name="q" value="{{ $input['q'] ?? '' }}" placeholder="Palavra-chave">
            </label>

            @foreach ($type->filters ?? [] as $key)
                @php $f = $type->field($key); @endphp
                @continue(! $f)
                @switch($f['type'])
                    @case('select')
                        <label class="v-field"><span>{{ $f['label'] }}</span>
                            <select name="{{ $key }}">
                                <option value="">Todos</option>
                                @foreach (\App\Fields\FieldTypes::activeOptions($f) as $ok => $ol)
                                    <option value="{{ $ok }}" @selected(($input[$key] ?? '') === (string) $ok)>{{ $ol }}</option>
                                @endforeach
                            </select>
                        </label>
                        @break
                    @case('text')
                        <label class="v-field"><span>{{ $f['label'] }}</span>
                            <select name="{{ $key }}">
                                <option value="">Todos</option>
                                @foreach ($choices[$key] ?? [] as $choice)
                                    <option value="{{ $choice }}" @selected(($input[$key] ?? '') === $choice)>{{ $choice }}</option>
                                @endforeach
                            </select>
                        </label>
                        @break
                    @case('number')
                        <label class="v-field"><span>{{ $f['label'] }} (a partir de)</span>
                            <input type="number" name="{{ $key }}_min" min="0" value="{{ $input[$key.'_min'] ?? '' }}" inputmode="numeric">
                        </label>
                        @break
                    @case('money')
                        <label class="v-field"><span>{{ $f['label'] }} até (R$)</span>
                            <input type="text" name="{{ $key }}_max" value="{{ $input[$key.'_max'] ?? '' }}" inputmode="decimal" placeholder="Ex.: 1.500">
                        </label>
                        @break
                    @case('checkbox')
                        <label class="v-check"><input type="checkbox" name="{{ $key }}" value="1" @checked(! empty($input[$key]))> {{ $f['label'] }}</label>
                        @break
                @endswitch
            @endforeach

            @if ($type->price_field)
                <label class="v-field"><span>Ordenar</span>
                    <select name="ordem">
                        <option value="">Mais recentes</option>
                        <option value="menor_preco" @selected(request('ordem') === 'menor_preco')>Menor preço</option>
                        <option value="maior_preco" @selected(request('ordem') === 'maior_preco')>Maior preço</option>
                    </select>
                </label>
            @endif

            <button class="v-btn">Filtrar</button>
            @if ($filtering)<a class="v-clear" href="{{ $tenant->vitrineUrl() }}{{ $types->count() > 1 ? '?produto='.$type->key : '' }}">Limpar filtros</a>@endif
        </form>

        <section>
            @if ($products->isEmpty())
                <div class="v-empty">
                    <p>Nenhuma opção encontrada{{ $filtering ? ' com esses filtros' : ' no momento' }}.</p>
                    @if ($settings['whatsapp'])
                        <a class="v-btn v-btn-wa" href="https://wa.me/{{ $settings['whatsapp'] }}?text={{ rawurlencode('Olá! Estou procurando '.mb_strtolower($type->name).' e não achei na vitrine. Podem me ajudar?') }}" target="_blank" rel="noopener">Pedir ajuda no WhatsApp</a>
                    @endif
                </div>
            @else
                <div class="v-grid">
                    @foreach ($products as $p)
                        @php $cover = $p->images->first(); @endphp
                        <a class="v-card" href="{{ $tenant->vitrineUrl($p->urlSlug()) }}">
                            <div class="v-card-img">
                                @if ($cover)
                                    <img src="{{ $cover->url() }}" alt="{{ $p->title() }}" loading="lazy">
                                @else
                                    <span class="v-no-photo">{{ $type->icon }}</span>
                                @endif
                                @if ($p->status === 'reservado')<span class="v-badge">{{ $type->statusLabel('reservado') }}</span>@endif
                                @if ($p->featured)<span class="v-badge v-badge-hl">Destaque</span>@endif
                            </div>
                            <div class="v-card-body">
                                @if ($p->priceText())<p class="v-price">{{ $p->priceText() }}</p>@endif
                                <h2>{{ $p->title() }}</h2>
                                <p class="v-meta">
                                    @foreach ($type->card_fields ?? [] as $k)
                                        @php
                                            $f = $type->field($k);
                                            $v = ($f && ($f['public'] ?? true) !== false) ? \App\Fields\FieldTypes::display($f, $p->data[$k] ?? null) : '';
                                        @endphp
                                        @if ($v !== '')<span>{{ $f['type'] === 'number' ? $v.' '.mb_strtolower($f['label']) : ($f['type'] === 'checkbox' ? $f['label'] : $v) }}</span>@endif
                                    @endforeach
                                </p>
                            </div>
                        </a>
                    @endforeach
                </div>

                @if ($products->hasPages())
                    <nav class="v-pages">
                        @if ($products->onFirstPage())<span>← Anterior</span>@else<a href="{{ $products->previousPageUrl() }}">← Anterior</a>@endif
                        <span>Página {{ $products->currentPage() }} de {{ $products->lastPage() }}</span>
                        @if ($products->hasMorePages())<a href="{{ $products->nextPageUrl() }}">Próxima →</a>@else<span>Próxima →</span>@endif
                    </nav>
                @endif
            @endif
        </section>
    </div>
@endsection
