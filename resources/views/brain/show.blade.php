@extends('layouts.app')

@section('title', $section['label'])
@section('main-class', 'shell')

@section('content')
<div class="layout">
    @include('brain.partials.nav', ['nav' => $nav, 'active' => $tab])

    <section class="content">
        <div class="section-head">
            <div>
                <h1><span class="h-icon">{{ $section['icon'] }}</span> {{ $section['label'] }}</h1>
                <p class="muted">{{ $section['intro'] }}</p>
            </div>

            @if ($section['type'] === 'items')
@if ($canEdit ?? true)
                <button type="button" class="btn primary" data-open-modal>+ Adicionar {{ $section['singular'] ?: 'item' }}</button>
@endif
            @endif
        </div>

        @if ($lastVersion)
            <p class="version-line">
                Versão atual <strong>{{ $lastVersion->version }}</strong> · {{ $lastVersion->description }}
                · {{ $lastVersion->created_at->diffForHumans() }}
                @if ($lastVersion->user) por {{ $lastVersion->user->name }} @endif
            </p>
        @endif

        {{-- Abas de formulário --}}
        @if ($section['type'] === 'settings')
            <form method="post" action="{{ route('brain.settings.update', $tab) }}" class="card form-grid" data-dirty-check enctype="multipart/form-data">
                @csrf
                @method('PUT')
                <fieldset class="contents" @disabled(! ($canEdit ?? true))>

                @foreach ($section['fields'] as $f)
                    @include('brain.partials.field', [
                        'f' => $f,
                        'value' => old('fields.'.$f['key'], \App\Brain\FormValues::one($f, $settings->data[$f['key']] ?? null)),
                    ])
                @endforeach
                </fieldset>

@if ($canEdit ?? true)
                <div class="actions wide">
                    <span class="unsaved" hidden>Alterações não salvas</span>
                    <button class="btn primary">Salvar</button>
                </div>
@endif
            </form>
        @endif

        {{-- Abas de lista (cartões) --}}
        @if ($section['type'] === 'items')
            @if ($items->isEmpty())
                <div class="empty card">
                    <div class="empty-icon">{{ $section['icon'] }}</div>
                    <h2>Nada aqui ainda</h2>
                    <p class="muted">Adicione o primeiro item. Exemplo: <em>{{ $section['fields'][0]['placeholder'] ?? '' }}</em></p>
@if ($canEdit ?? true)
                    <button type="button" class="btn primary" data-open-modal>+ Adicionar {{ $section['singular'] ?: 'item' }}</button>
@endif
                </div>
            @else
                <div class="cards {{ ! empty($section['ordered']) ? 'ordered' : '' }}">
                    @foreach ($items as $item)
                        @php
                            $titleField = $section['title_field'];
                            $badgeField = $section['badge_field'] ?? null;
                            $badgeDef = $badgeField ? collect($section['fields'])->firstWhere('key', $badgeField) : null;
                            $badge = null;
                            if ($badgeDef) {
                                $raw = $item->data[$badgeField] ?? null;
                                $badge = $badgeDef['type'] === 'checkbox'
                                    ? ($raw ? ($section['badge_text'] ?? $badgeDef['label']) : null)
                                    : (filled($raw) ? \App\Fields\FieldTypes::display($badgeDef, $raw) : null);
                            }
                        @endphp

                        <article class="item-card {{ $item->active ? '' : 'is-off' }}">
                            <header>
                                @if (! empty($section['ordered']))
                                    <span class="order">{{ $loop->iteration }}</span>
                                @endif
                                @if ($badge)
                                    <span class="badge badge-{{ \Illuminate\Support\Str::slug($item->data[$badgeField] ?? 'x') }}">{{ $badge }}</span>
                                @endif
                                @unless ($item->active)
                                    <span class="badge paused">Pausado</span>
                                @endunless
                            </header>

                            @php $titleDef = collect($section['fields'])->firstWhere('key', $titleField); @endphp
                            <h3>{{ $titleDef ? \App\Fields\FieldTypes::display($titleDef, $item->data[$titleField] ?? null) : $item->value($titleField) }}</h3>

                            <dl>
                                @foreach ($section['fields'] as $f)
                                    @continue(in_array($f['key'], [$titleField, $badgeField]))
                                    @if ($f['type'] === 'image')
                                        @if (! empty($item->data[$f['key']]))
                                            <div><dt>{{ $f['label'] }}</dt><dd><img class="thumb" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($item->data[$f['key']]) }}" alt=""></dd></div>
                                        @endif
                                        @continue
                                    @endif
                                    @php $shown = \App\Fields\FieldTypes::display($f, $item->data[$f['key']] ?? null); @endphp
                                    @if ($shown !== '' && $f['type'] !== 'checkbox')
                                        <div>
                                            <dt>{{ $f['label'] }}</dt>
                                            <dd>{!! nl2br(e($shown)) !!}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>

@if ($canEdit ?? true)
                            <footer>
                                <button type="button" class="btn small"
                                        data-edit="{{ json_encode(['fields' => \App\Brain\FormValues::forForm($section['fields'], $item->data ?? [])], JSON_UNESCAPED_UNICODE) }}"
                                        data-action="{{ route('brain.items.update', [$tab, $item->id]) }}">Editar</button>

                                <form method="post" action="{{ route('brain.items.toggle', [$tab, $item->id]) }}">
                                    @csrf @method('PATCH')
                                    <button class="btn small ghost" title="{{ $item->active ? 'O robô deixa de usar, sem apagar' : 'O robô volta a usar' }}">
                                        {{ $item->active ? 'Pausar' : 'Ativar' }}
                                    </button>
                                </form>

                                @if (! empty($section['ordered']))
                                    <span class="movers">
                                        <form method="post" action="{{ route('brain.items.move', [$tab, $item->id, 'subir']) }}">
                                            @csrf @method('PATCH')
                                            <button class="btn small ghost icon" title="Subir" @disabled($loop->first)>↑</button>
                                        </form>
                                        <form method="post" action="{{ route('brain.items.move', [$tab, $item->id, 'descer']) }}">
                                            @csrf @method('PATCH')
                                            <button class="btn small ghost icon" title="Descer" @disabled($loop->last)>↓</button>
                                        </form>
                                    </span>
                                @endif

                                <form method="post" action="{{ route('brain.items.destroy', [$tab, $item->id]) }}"
                                      data-confirm="Excluir este item? Dá para recuperar pelo histórico de versões." class="push">
                                    @csrf @method('DELETE')
                                    <button class="btn small danger ghost">Excluir</button>
                                </form>
                            </footer>
@endif
                        </article>
                    @endforeach
                </div>
            @endif

            {{-- Modal de adicionar / editar --}}
            @php
                $storeAction = route('brain.items.store', $tab);
                $reopen = '';
                $oldAction = old('_action');
                if ($errors->any() && $oldAction && str_starts_with($oldAction, url('/cerebro/'.$tab))) {
                    $reopen = json_encode(['action' => $oldAction, 'mode' => old('_mode', 'new')]);
                }
            @endphp
            <dialog id="item-modal" class="modal" data-reopen="{{ $reopen }}">
                <form method="post" action="{{ $storeAction }}" enctype="multipart/form-data"
                      data-store-action="{{ $storeAction }}"
                      data-new-title="Adicionar {{ $section['singular'] ?: 'item' }}"
                      data-edit-title="Editar {{ $section['singular'] ?: 'item' }}">
                    @csrf
                    <input type="hidden" name="_method" value="POST">
                    <input type="hidden" name="_action" value="{{ $storeAction }}">
                    <input type="hidden" name="_mode" value="new">

                    <div class="modal-head">
                        <h2 data-title>Adicionar {{ $section['singular'] ?: 'item' }}</h2>
                        <button type="button" class="btn ghost icon" data-close aria-label="Fechar">✕</button>
                    </div>

                    <div class="form-grid">
                        @foreach ($section['fields'] as $f)
                            @include('brain.partials.field', [
                                'f' => $f,
                                'prefix' => 'm',
                                'value' => old('fields.'.$f['key'], ($f['type'] === 'checkbox' && ! $errors->any()) ? ($f['default'] ?? false) : null),
                            ])
                        @endforeach
                    </div>

                    <div class="actions">
                        <button type="button" class="btn ghost" data-close>Cancelar</button>
                        <button class="btn primary">Salvar</button>
                    </div>
                </form>
            </dialog>
        @endif
    </section>
</div>
@endsection
