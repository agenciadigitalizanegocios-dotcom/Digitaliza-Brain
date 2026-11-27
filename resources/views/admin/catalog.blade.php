@extends('layouts.app')

@section('title', 'Catálogo · '.$tenant->name)

@section('content')
    <a href="{{ route('admin.tenants.index') }}" class="back">← Clientes</a>

    <div class="section-head">
        <div>
            <h1>Catálogo · {{ $tenant->name }}</h1>
            <p class="muted">
                Tipos de produto e campos deste cliente. O cliente só cadastra os produtos.
                @unless ($tenant->moduleFlags()['catalogo'])
                    <strong>O módulo Catálogo está desligado</strong>: ligue em Clientes → Módulos para o cliente ver os produtos e a vitrine.
                @endunless
            </p>
            @if ($tenant->moduleFlags()['catalogo'])
                <p class="small">Vitrine: <a href="{{ $tenant->vitrineUrl() }}" target="_blank" rel="noopener">{{ $tenant->vitrineUrl() }} ↗</a></p>
            @endif
        </div>
    </div>

    @if ($errors->get('structure'))
        <div class="flash error"><strong>Não salvei, porque:</strong>
            <ul>@foreach ($errors->get('structure') as $msg)<li>{{ $msg }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="editor">
        <aside class="card editor-tabs">
            <h2>Tipos de produto</h2>
            <ul class="tab-list">
                @foreach ($types as $t)
                    <li class="{{ $type?->id === $t->id ? 'active' : '' }} {{ $t->archived ? 'is-off' : '' }}">
                        <a href="{{ route('admin.catalog.show', [$tenant, 'tipo' => $t->key]) }}">
                            <span>{{ $t->icon }}</span> {{ $t->name }}
                            @if ($t->archived) <span class="badge paused">arquivado</span> @endif
                        </a>
                    </li>
                @endforeach
            </ul>
            <form method="post" action="{{ route('admin.catalog.types.store', $tenant) }}" class="new-tab">
                @csrf
                <h3>Novo tipo</h3>
                <input type="text" name="name" placeholder="Nome (ex.: Armação)" required maxlength="60">
                <input type="text" name="icon" placeholder="Ícone 👓" maxlength="8" class="icon-input">
                <button class="btn primary small block">+ Adicionar tipo</button>
            </form>
        </aside>

        <section class="editor-main">
            @if ($type)
                @php
                    $fields = $type->activeFields();
                    $old = fn ($k, $d) => old($k, $d);
                @endphp
                <form method="post" action="{{ route('admin.catalog.types.update', [$tenant, $type->key]) }}" class="card form-grid">
                    @csrf @method('PUT')
                    <div class="wide card-head"><h2>{{ $type->icon }} {{ $type->name }} <code>{{ $type->key }}</code></h2></div>

                    <div class="field"><label class="label">Nome</label><input type="text" name="name" value="{{ $old('name', $type->name) }}" required></div>
                    <div class="field"><label class="label">Plural</label><input type="text" name="plural" value="{{ $old('plural', $type->plural) }}" placeholder="Imóveis"></div>
                    <div class="field"><label class="label">Ícone</label><input type="text" name="icon" value="{{ $old('icon', $type->icon) }}" maxlength="8"></div>
                    <div class="field"><label class="label">Texto de ajuda</label><input type="text" name="intro" value="{{ $old('intro', $type->intro) }}"></div>

                    <div class="field"><label class="label">Campo título</label>
                        <select name="title_field">
                            @foreach ($fields as $f)
                                @if (in_array($f['type'], ['text', 'textarea']))<option value="{{ $f['key'] }}" @selected($type->title_field === $f['key'])>{{ $f['label'] }}</option>@endif
                            @endforeach
                        </select>
                    </div>
                    <div class="field"><label class="label">Campo de preço</label>
                        <select name="price_field">
                            <option value="">Sem preço</option>
                            @foreach ($fields as $f)
                                @if ($f['type'] === 'money')<option value="{{ $f['key'] }}" @selected($type->price_field === $f['key'])>{{ $f['label'] }}</option>@endif
                            @endforeach
                        </select>
                    </div>
                    <div class="field"><label class="label">Depois do preço</label><input type="text" name="price_suffix" value="{{ $old('price_suffix', $type->price_suffix) }}" placeholder="/mês"></div>

                    <div class="field wide"><span class="label">Status (nomes que aparecem)</span>
                        <div class="inline-row">
                            @foreach (['disponivel' => 'Disponível', 'reservado' => 'Reservado', 'indisponivel' => 'Indisponível (some da vitrine)'] as $st => $hint)
                                <input type="text" name="status_labels[{{ $st }}]" value="{{ $type->statusLabel($st) }}" title="{{ $hint }}" placeholder="{{ $hint }}" required>
                            @endforeach
                        </div>
                    </div>

                    <div class="field wide"><span class="label">Aparecem no cartão da vitrine <span class="badge">{{ count($type->card_fields ?? []) }} de {{ \App\Catalog\ProductTypeValidator::MAX_CARD_FIELDS }}</span></span>
                        <div class="check-grid">
                            @foreach ($fields as $f)
                                @continue(in_array($f['type'], ['image', 'textarea']) || $f['key'] === $type->title_field || ($f['public'] ?? true) === false)
                                <label class="check small"><input type="checkbox" name="card_fields[]" value="{{ $f['key'] }}" @checked(in_array($f['key'], $type->card_fields ?? []))> {{ $f['label'] }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="field wide"><span class="label">Filtros da vitrine e da busca do robô <span class="badge">{{ count($type->filters ?? []) }} de {{ \App\Catalog\ProductTypeValidator::MAX_FILTERS }}</span></span>
                        <div class="check-grid">
                            @foreach ($fields as $f)
                                @continue(! in_array($f['type'], $filterable) || ($f['public'] ?? true) === false || ($f['send_to_ai'] ?? true) === false)
                                <label class="check small"><input type="checkbox" name="filters[]" value="{{ $f['key'] }}" @checked(in_array($f['key'], $type->filters ?? []))> {{ $f['label'] }}</label>
                            @endforeach
                        </div>
                        <p class="help">Só campos que aparecem na vitrine e vão para o robô. Texto: o visitante escolhe entre os valores cadastrados (ex.: bairros). Número: “a partir de”. Dinheiro: “até”. Sim/não: só os marcados.</p>
                    </div>

                    <div class="actions wide">
                        <button class="btn ghost danger" form="archive-type">{{ $type->archived ? 'Desarquivar tipo' : 'Arquivar tipo' }}</button>
                        <button class="btn primary">Salvar tipo</button>
                    </div>
                </form>
                <form id="archive-type" method="post" action="{{ route('admin.catalog.types.archive', [$tenant, $type->key]) }}"
                      data-confirm="{{ $type->archived ? 'Desarquivar este tipo?' : 'Arquivar este tipo? Ele e os produtos dele saem do painel, da vitrine e da busca do robô (nada é apagado).' }}">
                    @csrf @method('PATCH')
                </form>

                <div class="card">
                    <div class="card-head">
                        <h2>Campos <span class="badge">{{ count(array_filter($type->fields, fn ($f) => empty($f['archived']))) }} de {{ \App\Catalog\ProductTypeValidator::MAX_ACTIVE_FIELDS }} ativos</span></h2>
                        <button type="button" class="btn primary small" data-new-field>+ Novo campo</button>
                    </div>
                    <table class="table">
                        <thead><tr><th>Campo</th><th>Tipo</th><th></th><th></th></tr></thead>
                        <tbody>
                            @foreach ($type->fields as $f)
                                <tr class="{{ ! empty($f['archived']) ? 'is-off' : '' }}">
                                    <td><strong>{{ $f['label'] }}</strong><br><code>{{ $f['key'] }}</code></td>
                                    <td>{{ $fieldTypes[$f['type']] ?? $f['type'] }}</td>
                                    <td>
                                        @if ($f['required'] ?? false)<span class="badge">obrigatório</span>@endif
                                        @if (($f['public'] ?? true) === false)<span class="badge">não aparece na vitrine</span>@endif
                                        @if (($f['send_to_ai'] ?? true) === false)<span class="badge">não vai para o robô</span>@endif
                                        @if (! empty($f['archived']))<span class="badge paused">arquivado</span>@endif
                                    </td>
                                    <td class="right actions-cell">
                                        <button type="button" class="btn small" data-edit-field="{{ json_encode($f, JSON_UNESCAPED_UNICODE) }}"
                                                data-action="{{ route('admin.catalog.fields.update', [$tenant, $type->key, $f['key']]) }}">Editar</button>
                                        <form method="post" action="{{ route('admin.catalog.fields.move', [$tenant, $type->key, $f['key'], 'subir']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" @disabled($loop->first)>↑</button></form>
                                        <form method="post" action="{{ route('admin.catalog.fields.move', [$tenant, $type->key, $f['key'], 'descer']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" @disabled($loop->last)>↓</button></form>
                                        <form method="post" action="{{ route('admin.catalog.fields.archive', [$tenant, $type->key, $f['key']]) }}"
                                              data-confirm="{{ empty($f['archived']) ? 'Arquivar este campo? Some do painel, da vitrine e do robô, mas os valores ficam guardados.' : 'Desarquivar este campo?' }}">
                                            @csrf @method('PATCH')
                                            <button class="btn small ghost {{ empty($f['archived']) ? 'danger' : '' }}">{{ empty($f['archived']) ? 'Arquivar' : 'Desarquivar' }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <dialog id="field-modal" class="modal">
                    <form method="post" action="{{ route('admin.catalog.fields.store', [$tenant, $type->key]) }}" data-store-action="{{ route('admin.catalog.fields.store', [$tenant, $type->key]) }}">
                        @csrf
                        <input type="hidden" name="_method" value="POST">
                        <div class="modal-head">
                            <h2 data-title>Novo campo</h2>
                            <button type="button" class="btn ghost icon" data-close aria-label="Fechar">✕</button>
                        </div>
                        <p class="help" data-locked-note hidden></p>
                        <div class="form-grid">
                            <div class="field"><label class="label">Nome</label><input type="text" name="label" data-f="label" required maxlength="80"></div>
                            <div class="field"><label class="label">Tipo</label>
                                <select name="type" data-f="type">
                                    @foreach ($fieldTypes as $tk => $tl)@if ($tk !== 'image')<option value="{{ $tk }}">{{ $tl }}</option>@endif @endforeach
                                </select>
                                <p class="help">Depois de salvo, só troca entre texto curto e texto longo.</p>
                            </div>
                            <div class="field"><label class="check"><input type="hidden" name="required" value="0"><input type="checkbox" name="required" value="1" data-f="required"> Obrigatório</label></div>
                            <div class="field"><label class="check"><input type="hidden" name="public" value="0"><input type="checkbox" name="public" value="1" data-f="public" checked> Aparece na vitrine</label></div>
                            <div class="field"><label class="check"><input type="hidden" name="send_to_ai" value="0"><input type="checkbox" name="send_to_ai" value="1" data-f="send_to_ai" checked> Vai para o robô</label></div>
                            <div class="field"><label class="check"><input type="hidden" name="default" value="0"><input type="checkbox" name="default" value="1" data-f="default"> Marcado por padrão (sim/não)</label></div>
                            <div class="field wide"><label class="label">Exemplo (placeholder)</label><input type="text" name="placeholder" data-f="placeholder" maxlength="300"></div>
                            <div class="field wide"><label class="label">Ajuda</label><input type="text" name="help" data-f="help" maxlength="300"></div>
                            <div class="field"><label class="label">Mínimo (número)</label><input type="number" name="min" data-f="min"></div>
                            <div class="field"><label class="label">Máximo (número)</label><input type="number" name="max" data-f="max"></div>
                            <div class="field wide"><label class="label">Opções (lista de opções)</label>
                                <textarea name="options" rows="3" data-f="options" placeholder="Uma por linha. Ex.: Casa&#10;apartamento: Apartamento"></textarea>
                            </div>
                            <div class="field wide"><label class="label">Opções arquivadas (chaves separadas por vírgula)</label><input type="text" name="archived_options" data-f="archived_options"></div>
                        </div>
                        <div class="actions">
                            <button type="button" class="btn ghost" data-close>Cancelar</button>
                            <button class="btn primary">Salvar campo</button>
                        </div>
                    </form>
                </dialog>
            @else
                <div class="empty card">
                    <div class="empty-icon">📦</div>
                    <h2>Nenhum tipo de produto</h2>
                    <p class="muted">Crie o primeiro tipo ao lado (ex.: Imóvel, Armação, Curso).</p>
                </div>
            @endif
        </section>
    </div>
@endsection
