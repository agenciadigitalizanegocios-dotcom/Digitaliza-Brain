@extends('layouts.app')

@section('title', 'Clientes')

@section('content')
    <div class="section-head">
        <div>
            <h1>Clientes</h1>
            <p class="muted">Imobiliárias que usam o robô. Entre no painel de um cliente para preencher o cérebro junto com ele.</p>
        </div>
        <button type="button" class="btn primary" data-open-dialog="#new-tenant">+ Novo cliente</button>
    </div>

    @if (session('token'))
        <div class="card token-card">
            <h2>Token do n8n — {{ session('token')['tenant'] }}</h2>
            <p class="muted">Copie agora e guarde na credencial do n8n. Por segurança, ele não aparece de novo.</p>
            <div class="token-row">
                <code id="token-value">{{ session('token')['value'] }}</code>
                <button type="button" class="btn small" data-copy="#token-value">Copiar</button>
            </div>
            <p class="help">No n8n: HTTP Request → GET <code>{{ url('/api/v1/brain') }}</code> → Header <code>Authorization: Bearer &lt;token&gt;</code></p>
        </div>
    @endif

    @if ($tenants->isEmpty())
        <div class="empty card">
            <div class="empty-icon">🏢</div>
            <h2>Nenhum cliente ainda</h2>
            <button type="button" class="btn primary" data-open-dialog="#new-tenant">+ Novo cliente</button>
        </div>
    @else
        <div class="card table-card">
            <table class="table">
                <thead>
                    <tr><th>Cliente</th><th>Status</th><th>Modelo</th><th>Cérebro</th><th>Token n8n</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($tenants as $tenant)
                        @php $v = $versions[$tenant->id] ?? null; @endphp
                        <tr>
                            <td><strong>{{ $tenant->name }}</strong><br><span class="muted small">{{ $tenant->users_count }} usuário(s)</span></td>
                            <td><span class="status {{ $tenant->isActive() ? 'on' : 'off' }}">{{ $tenant->status }}</span></td>
                            <td>
                                {{ $tenant->template?->name ?? '—' }}
                                @if ($tenant->plan)<br><span class="muted small">Plano: {{ $tenant->plan }}</span>@endif
                                @if ($tenant->hasPendingUpdate())
                                    <br><a href="{{ route('admin.tenants.update', $tenant) }}" class="badge pending">Atualização pendente</a>
                                @endif
                            </td>
                            <td>
                                @if ($v)
                                    v{{ $v->version }}<br><span class="muted small">{{ \Illuminate\Support\Carbon::parse($v->updated_at)->format('d/m H:i') }}</span>
                                @else
                                    <span class="muted">vazio</span>
                                @endif
                            </td>
                            <td>{{ $tenant->api_token_hint ? '…'.$tenant->api_token_hint : '—' }}</td>
                            <td class="right actions-cell">
                                <form method="post" action="{{ route('admin.tenants.enter', $tenant) }}">
                                    @csrf
                                    <button class="btn small primary">Abrir cérebro</button>
                                </form>
                                <a class="btn small ghost" href="{{ route('admin.structure.show', ['clientes', $tenant->id]) }}">Estrutura</a>
                                <a class="btn small ghost" href="{{ route('admin.catalog.show', $tenant) }}">Catálogo</a>
                                <button type="button" class="btn small ghost" data-open-dialog="#mod-{{ $tenant->id }}">Módulos</button>
                                <form method="post" action="{{ route('admin.tenants.token', $tenant) }}"
                                      data-confirm="Gerar um token novo para {{ $tenant->name }}? O token atual para de funcionar e você precisa trocar no n8n.">
                                    @csrf
                                    <button class="btn small ghost">Novo token</button>
                                </form>
                                <form method="post" action="{{ route('admin.tenants.status', $tenant) }}"
                                      data-confirm="{{ $tenant->isActive() ? 'Suspender '.$tenant->name.'? O robô para de responder.' : 'Reativar '.$tenant->name.'?' }}">
                                    @csrf @method('PATCH')
                                    <button class="btn small ghost {{ $tenant->isActive() ? 'danger' : '' }}">{{ $tenant->isActive() ? 'Suspender' : 'Reativar' }}</button>
                                </form>
                            </td>
                        </tr>
                        <dialog id="mod-{{ $tenant->id }}" class="modal">
                            <form method="post" action="{{ route('admin.tenants.modules', $tenant) }}">
                                @csrf @method('PATCH')
                                <div class="modal-head"><h2>Módulos · {{ $tenant->name }}</h2><button type="button" class="btn ghost icon" data-close>✕</button></div>
                                <p class="help">Ferramentas que a IA pode usar. Por enquanto só liga/desliga: o Catálogo e a Agenda chegam nas próximas partes.</p>
                                @foreach (\App\Brain\Modules::ALL as $mk => $ml)
                                    <label class="check"><input type="hidden" name="modules[{{ $mk }}]" value="0"><input type="checkbox" name="modules[{{ $mk }}]" value="1" @checked($tenant->moduleFlags()[$mk])> {{ $ml }} <span class="badge">em breve</span></label>
                                @endforeach
                                <div class="actions"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary">Salvar</button></div>
                            </form>
                        </dialog>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <dialog id="new-tenant" class="modal" data-reopen="{{ $errors->any() ? '1' : '' }}">
        <form method="post" action="{{ route('admin.tenants.store') }}">
            @csrf
            <div class="modal-head">
                <h2>Novo cliente</h2>
                <button type="button" class="btn ghost icon" data-close aria-label="Fechar">✕</button>
            </div>

            <div class="form-grid">
                <div class="field wide {{ $errors->has('template') ? 'has-error' : '' }}">
                    <label class="label" for="t-template">Modelo</label>
                    <select id="t-template" name="template" required>
                        @foreach ($templates as $tpl)
                            <option value="{{ $tpl->slug }}" @selected(old('template', 'imobiliaria') === $tpl->slug)>{{ $tpl->name }} — {{ $tpl->description }}</option>
                        @endforeach
                    </select>
                    @error('template') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                @foreach ([
                    ['name', 'Nome do cliente', 'text', 'BS Imóveis'],
                    ['plan', 'Plano', 'text', 'Essencial'],
                    ['owner_name', 'Nome do dono', 'text', 'Fulano'],
                    ['owner_email', 'E-mail de acesso do dono', 'email', 'dono@imobiliaria.com.br'],
                    ['owner_password', 'Senha inicial (mín. 8)', 'text', ''],
                ] as $row)
                    @php [$key, $label, $type, $ph] = $row; @endphp
                    <div class="field {{ $errors->has($key) ? 'has-error' : '' }}">
                        <label class="label" for="t-{{ $key }}">{{ $label }}</label>
                        <input id="t-{{ $key }}" type="{{ $type }}" name="{{ $key }}" value="{{ $key === 'owner_password' ? '' : old($key) }}" placeholder="{{ $ph }}" @required($key !== 'plan')>
                        @error($key) <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <p class="help">Ao criar, o sistema gera o token do n8n desse cliente e mostra uma única vez.</p>

            <div class="actions">
                <button type="button" class="btn ghost" data-close>Cancelar</button>
                <button class="btn primary">Criar cliente</button>
            </div>
        </form>
    </dialog>
@endsection
