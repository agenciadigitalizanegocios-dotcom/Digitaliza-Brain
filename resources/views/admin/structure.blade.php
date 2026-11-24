@extends('layouts.app')

@php
    $isTemplate = $kind === 'modelos';
    $ownerName = $owner->name;
    $base = fn ($name, $extra = []) => route('admin.structure.'.$name, array_merge([$kind, $owner->id], $extra));
    $tabFormat = $tab['ai']['format'] ?? null;
    $tabCore = $tab ? \App\Brain\PromptFormats::isCore($tabFormat) : false;
    $usedKeys = $tab ? \App\Brain\PromptFormats::usedKeys($tabFormat) : [];
    $structureErrors = $errors->get('structure');
@endphp

@section('title', 'Estrutura · '.$ownerName)

@section('content')
    <a href="{{ $isTemplate ? route('admin.templates.index') : route('admin.tenants.index') }}" class="back">← {{ $isTemplate ? 'Modelos' : 'Clientes' }}</a>

    <div class="section-head">
        <div>
            <h1>Estrutura · {{ $ownerName }}</h1>
            <p class="muted">
                @if ($isTemplate)
                    Modelo de nicho, versão {{ $owner->version }}. Mudanças aqui não alteram os clientes: eles ganham o selo “atualização pendente”.
                @else
                    Abas e campos só deste cliente. Toda mudança vira versão no histórico e o robô usa na hora.
                @endif
            </p>
        </div>
    </div>

    @if ($structureErrors)
        <div class="flash error">
            <strong>Não salvei, porque:</strong>
            <ul>@foreach ($structureErrors as $msg)<li>{{ $msg }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="editor">
        {{-- Coluna das abas --}}
        <aside class="card editor-tabs">
            <h2>Abas</h2>
            <ul class="tab-list">
                @foreach ($structure['tabs'] as $t)
                    @php $core = \App\Brain\PromptFormats::isCore($t['ai']['format'] ?? null); @endphp
                    <li class="{{ ($tab['key'] ?? null) === $t['key'] ? 'active' : '' }} {{ ! empty($t['archived']) ? 'is-off' : '' }}">
                        <a href="{{ route('admin.structure.show', [$kind, $owner->id, 'aba' => $t['key']]) }}">
                            <span>{{ $t['icon'] }}</span> {{ $t['label'] }}
                            @if ($core) <span title="Núcleo: não pode ser arquivada">🔒</span> @endif
                            @if (! empty($t['archived'])) <span class="badge paused">arquivada</span> @endif
                        </a>
                        <span class="row-actions">
                            <form method="post" action="{{ $base('tabs.move', [$t['key'], 'subir']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" title="Subir" @disabled($loop->first)>↑</button></form>
                            <form method="post" action="{{ $base('tabs.move', [$t['key'], 'descer']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" title="Descer" @disabled($loop->last)>↓</button></form>
                        </span>
                    </li>
                @endforeach
            </ul>

            <form method="post" action="{{ $base('tabs.store') }}" class="new-tab">
                @csrf
                <h3>Nova aba</h3>
                <input type="text" name="label" placeholder="Nome (ex.: Cursos)" required maxlength="60">
                <div class="inline-row">
                    <input type="text" name="icon" placeholder="Ícone 🙂" maxlength="8" class="icon-input">
                    <select name="type">
                        <option value="items">Lista de cartões</option>
                        <option value="settings">Formulário</option>
                    </select>
                </div>
                <button class="btn primary small block">+ Adicionar aba</button>
            </form>
        </aside>

        <section class="editor-main">
            @if ($tab)
                {{-- Configuração da aba --}}
                <form method="post" action="{{ $base('tabs.update', [$tab['key']]) }}" class="card form-grid">
                    @csrf @method('PUT')
                    <div class="wide card-head">
                        <h2>{{ $tab['icon'] }} {{ $tab['label'] }} <code>{{ $tab['key'] }}</code>
                            <span class="badge">{{ $tab['type'] === 'items' ? 'lista' : 'formulário' }}</span>
                            @if ($tabCore) <span class="badge">🔒 núcleo</span> @endif
                        </h2>
                    </div>

                    <div class="field"><label class="label">Nome</label><input type="text" name="label" value="{{ old('label', $tab['label']) }}" required></div>
                    <div class="field"><label class="label">Ícone</label><input type="text" name="icon" value="{{ old('icon', $tab['icon']) }}" maxlength="8"></div>
                    <div class="field wide"><label class="label">Texto de ajuda para o cliente</label><textarea name="intro" rows="2" data-autosize>{{ old('intro', $tab['intro'] ?? '') }}</textarea></div>

                    @if ($tab['type'] === 'items')
                        <div class="field"><label class="label">Nome no singular</label><input type="text" name="singular" value="{{ old('singular', $tab['singular'] ?? '') }}" placeholder="curso"></div>
                        <div class="field"><label class="check"><input type="hidden" name="ordered" value="0"><input type="checkbox" name="ordered" value="1" @checked($tab['ordered'] ?? false)> Itens em ordem (numerados, com ↑↓)</label></div>
                        @php $activeFields = array_filter($tab['fields'], fn ($f) => empty($f['archived'])); @endphp
                        <div class="field"><label class="label">Campo título do cartão</label>
                            <select name="title_field">
                                @foreach ($activeFields as $f)
                                    @if (in_array($f['type'], ['text', 'textarea', 'select']))
                                        <option value="{{ $f['key'] }}" @selected(($tab['title_field'] ?? null) === $f['key'])>{{ $f['label'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="field"><label class="label">Campo do selo (opcional)</label>
                            <select name="badge_field">
                                <option value="">Nenhum</option>
                                @foreach ($activeFields as $f)
                                    @if (in_array($f['type'], ['text', 'number', 'select', 'checkbox', 'money']))
                                        <option value="{{ $f['key'] }}" @selected(($tab['badge_field'] ?? null) === $f['key'])>{{ $f['label'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
                        <div class="field"><label class="label">Texto do selo (para sim/não)</label><input type="text" name="badge_text" value="{{ old('badge_text', $tab['badge_text'] ?? '') }}" placeholder="Obrigatória"></div>
                    @endif

                    <div class="wide divider"><h3>Como a IA lê esta aba</h3></div>
                    <div class="field"><label class="label">Formato do prompt</label>
                        <select name="ai_format" @if ($tabCore) disabled @endif>
                            @foreach ($formats as $fk => $fl)
                                @php
                                    $fits = in_array($fk, \App\Brain\PromptFormats::GENERIC, true)
                                        ? ($fk === 'campos') === ($tab['type'] === 'settings')
                                        : (\App\Brain\PromptFormats::USES[$fk]['type'] ?? null) === $tab['type'];
                                @endphp
                                @if ($fits)
                                    <option value="{{ $fk }}" @selected($tabFormat === $fk)>{{ $fl }}</option>
                                @endif
                            @endforeach
                        </select>
                        @if ($tabCore)<input type="hidden" name="ai_format" value="{{ $tabFormat }}"><p class="help">Aba do núcleo: o formato não pode mudar.</p>
                        @else<p class="help">Os formatos específicos usam campos fixos (com 🔒). Troque para o genérico para liberar.</p>@endif
                    </div>
                    <div class="field"><label class="label">Ordem no prompt</label><input type="number" name="ai_order" value="{{ old('ai_order', $tab['ai']['order'] ?? 0) }}" min="0" max="999" required></div>
                    <div class="field wide"><label class="label">Título no prompt</label><input type="text" name="ai_title" value="{{ old('ai_title', $tab['ai']['title'] ?? '') }}" placeholder="CURSOS"></div>
                    <div class="field wide"><label class="label">Instrução antes (como a IA deve usar isto)</label><textarea name="ai_before" rows="2" data-autosize>{{ old('ai_before', $tab['ai']['before'] ?? '') }}</textarea></div>
                    <div class="field wide"><label class="label">Instrução depois</label><textarea name="ai_after" rows="2" data-autosize>{{ old('ai_after', $tab['ai']['after'] ?? '') }}</textarea></div>
                    @if ($tab['type'] === 'items')
                        <div class="field wide"><label class="label">Modelo de linha (só no formato genérico de lista)</label>
                            <input type="text" name="ai_line" value="{{ old('ai_line', $tab['ai']['line'] ?? '') }}" placeholder="- {nome} ({turnos}): {sobre}">
                            <p class="help">Use {chave_do_campo}. Vazio = “- Título. Rótulo: valor; Rótulo: valor”.</p>
                        </div>
                    @endif

                    <div class="actions wide">
                        @unless ($tabCore)
                            <button class="btn ghost danger" form="archive-tab">{{ empty($tab['archived']) ? 'Arquivar aba' : 'Desarquivar aba' }}</button>
                        @endunless
                        <button class="btn primary">Salvar aba</button>
                    </div>
                </form>
                <form id="archive-tab" method="post" action="{{ $base('tabs.archive', [$tab['key']]) }}"
                      data-confirm="{{ empty($tab['archived']) ? 'Arquivar esta aba? Ela some do painel e do prompt, mas o conteúdo fica guardado.' : 'Desarquivar esta aba?' }}">
                    @csrf @method('PATCH')
                </form>

                {{-- Campos --}}
                <div class="card">
                    <div class="card-head">
                        <h2>Campos</h2>
                        <button type="button" class="btn primary small" data-new-field>+ Novo campo</button>
                    </div>
                    <table class="table">
                        <thead><tr><th>Campo</th><th>Tipo</th><th></th><th></th></tr></thead>
                        <tbody>
                            @foreach ($tab['fields'] as $f)
                                @php $locked = in_array($f['key'], $usedKeys, true); @endphp
                                <tr class="{{ ! empty($f['archived']) ? 'is-off' : '' }}">
                                    <td><strong>{{ $f['label'] }}</strong> @if ($locked)🔒@endif <br><code>{{ $f['key'] }}</code></td>
                                    <td>{{ $types[$f['type']] ?? $f['type'] }}</td>
                                    <td>
                                        @if ($f['required'] ?? false)<span class="badge">obrigatório</span>@endif
                                        @if (($f['send_to_ai'] ?? true) === false)<span class="badge">não vai para a IA</span>@endif
                                        @if (! empty($f['archived']))<span class="badge paused">arquivado</span>@endif
                                    </td>
                                    <td class="right actions-cell">
                                        <button type="button" class="btn small" data-edit-field="{{ json_encode($f, JSON_UNESCAPED_UNICODE) }}"
                                                data-action="{{ $base('fields.update', [$tab['key'], $f['key']]) }}" data-locked="{{ $locked ? '1' : '' }}">Editar</button>
                                        <form method="post" action="{{ $base('fields.move', [$tab['key'], $f['key'], 'subir']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" @disabled($loop->first)>↑</button></form>
                                        <form method="post" action="{{ $base('fields.move', [$tab['key'], $f['key'], 'descer']) }}">@csrf @method('PATCH')<button class="btn small ghost icon" @disabled($loop->last)>↓</button></form>
                                        @unless ($locked && empty($f['archived']))
                                            <form method="post" action="{{ $base('fields.archive', [$tab['key'], $f['key']]) }}"
                                                  data-confirm="{{ empty($f['archived']) ? 'Arquivar este campo? Some da tela e do prompt, mas os valores ficam guardados.' : 'Desarquivar este campo?' }}">
                                                @csrf @method('PATCH')
                                                <button class="btn small ghost {{ empty($f['archived']) ? 'danger' : '' }}">{{ empty($f['archived']) ? 'Arquivar' : 'Desarquivar' }}</button>
                                            </form>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Itens iniciais (só no modelo) --}}
                @if ($isTemplate && $tab['type'] === 'items')
                    @php $starters = collect($structure['starter_items'] ?? [])->map(fn ($s, $i) => $s + ['index' => $i])->where('section', $tab['key']); @endphp
                    <div class="card">
                        <div class="card-head"><h2>Itens iniciais</h2></div>
                        <p class="muted small">Copiados uma única vez, quando um cliente é criado com este modelo. Mudar aqui não gera pendência.</p>
                        @foreach ($starters as $st)
                            <div class="starter">
                                <span>{{ \App\Brain\Structure::itemTitle($tab, $st['data']) }}</span>
                                <form method="post" action="{{ route('admin.structure.starter.destroy', [$owner->id, $st['index']]) }}" data-confirm="Remover este item inicial?">
                                    @csrf @method('DELETE')
                                    <button class="btn small ghost danger">Remover</button>
                                </form>
                            </div>
                        @endforeach
                        <form method="post" action="{{ route('admin.structure.starter.store', [$owner->id, $tab['key']]) }}" class="form-grid">
                            @csrf
                            @foreach ($tab['fields'] as $f)
                                @continue(! empty($f['archived']) || $f['type'] === 'image')
                                @include('brain.partials.field', ['f' => $f, 'prefix' => 's', 'value' => ($f['type'] === 'checkbox' ? ($f['default'] ?? false) : null)])
                            @endforeach
                            <div class="actions wide"><button class="btn small">+ Adicionar item inicial</button></div>
                        </form>
                    </div>
                @endif

                {{-- Prévia --}}
                <div class="card preview">
                    <div class="card-head"><h2>Prévia (como o cliente vê)</h2></div>
                    <fieldset disabled class="form-grid">
                        @foreach ($tab['fields'] as $f)
                            @continue(! empty($f['archived']))
                            @include('brain.partials.field', ['f' => $f, 'prefix' => 'p', 'value' => null])
                        @endforeach
                    </fieldset>
                </div>
            @endif

            {{-- Fechamento do prompt --}}
            <form method="post" action="{{ $base('closing') }}" class="card">
                @csrf @method('PUT')
                <input type="hidden" name="aba" value="{{ $tab['key'] ?? '' }}">
                <div class="card-head"><h2>Fechamento do prompt</h2></div>
                <p class="muted small">Vira o bloco “# IMPORTANTE” no fim do prompt. Uma frase por linha.</p>
                <textarea name="closing" rows="3" data-autosize>{{ implode("\n", $structure['closing'] ?? []) }}</textarea>
                <div class="actions"><button class="btn primary small">Salvar fechamento</button></div>
            </form>
        </section>
    </div>

    {{-- Modal de campo --}}
    @if ($tab)
        <dialog id="field-modal" class="modal">
            <form method="post" action="{{ $base('fields.store', [$tab['key']]) }}" data-store-action="{{ $base('fields.store', [$tab['key']]) }}">
                @csrf
                <input type="hidden" name="_method" value="POST">
                <div class="modal-head">
                    <h2 data-title>Novo campo</h2>
                    <button type="button" class="btn ghost icon" data-close aria-label="Fechar">✕</button>
                </div>
                <p class="help" data-locked-note hidden>🔒 Campo usado pelo formato do prompt: não pode ser arquivado nem mudar de tipo.</p>
                <div class="form-grid">
                    <div class="field"><label class="label">Nome</label><input type="text" name="label" data-f="label" required maxlength="80"></div>
                    <div class="field"><label class="label">Tipo</label>
                        <select name="type" data-f="type">
                            @foreach ($types as $tk => $tl)<option value="{{ $tk }}">{{ $tl }}</option>@endforeach
                        </select>
                        <p class="help">Depois de salvo, só troca entre texto curto e texto longo.</p>
                    </div>
                    <div class="field"><label class="check"><input type="hidden" name="required" value="0"><input type="checkbox" name="required" value="1" data-f="required"> Obrigatório</label></div>
                    <div class="field"><label class="check"><input type="hidden" name="send_to_ai" value="0"><input type="checkbox" name="send_to_ai" value="1" data-f="send_to_ai" checked> Vai para a IA</label></div>
                    <div class="field wide"><label class="label">Exemplo (placeholder)</label><input type="text" name="placeholder" data-f="placeholder" maxlength="300"></div>
                    <div class="field wide"><label class="label">Ajuda</label><input type="text" name="help" data-f="help" maxlength="300"></div>
                    <div class="field"><label class="label">Mínimo (número)</label><input type="number" name="min" data-f="min"></div>
                    <div class="field"><label class="label">Máximo (número)</label><input type="number" name="max" data-f="max"></div>
                    <div class="field"><label class="check"><input type="hidden" name="default" value="0"><input type="checkbox" name="default" value="1" data-f="default"> Marcado por padrão (sim/não)</label></div>
                    <div class="field wide"><label class="label">Opções (lista de opções)</label>
                        <textarea name="options" rows="3" data-f="options" placeholder="Uma por linha. Ex.: Saúde&#10;gestao: Gestão"></textarea>
                        <p class="help">As chaves das opções nunca mudam; para tirar uma opção, arquive.</p>
                    </div>
                    <div class="field wide"><label class="label">Opções arquivadas (chaves separadas por vírgula)</label><input type="text" name="archived_options" data-f="archived_options"></div>
                </div>
                <div class="actions">
                    <button type="button" class="btn ghost" data-close>Cancelar</button>
                    <button class="btn primary">Salvar campo</button>
                </div>
            </form>
        </dialog>
    @endif
@endsection
