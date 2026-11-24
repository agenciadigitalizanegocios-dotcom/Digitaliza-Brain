@extends('layouts.app')

@section('title', 'Atualização do modelo · '.$tenant->name)

@section('content')
    <a href="{{ route('admin.tenants.index') }}" class="back">← Clientes</a>

    <div class="section-head">
        <div>
            <h1>Atualização do modelo · {{ $tenant->name }}</h1>
            <p class="muted">
                Modelo {{ $template->name }}: o cliente está na v{{ $tenant->template_version ?? '?' }}, o modelo está na v{{ $template->version }}.
                Até você aplicar, o cliente e o robô continuam exatamente como estão. Aplicar nunca apaga conteúdo.
            </p>
        </div>
    </div>

    @if ($errors->get('structure'))
        <div class="flash error"><strong>Não apliquei, porque:</strong>
            <ul>@foreach ($errors->get('structure') as $msg)<li>{{ $msg }}</li>@endforeach</ul>
        </div>
    @endif

    @if (! $changes)
        <div class="empty card">
            <h2>Nada a aplicar</h2>
            <p class="muted">O cliente já está igual ao modelo nesses pontos. Você pode dispensar o aviso.</p>
            <form method="post" action="{{ route('admin.tenants.update.dismiss', $tenant) }}">@csrf<button class="btn primary">Dispensar aviso</button></form>
        </div>
    @else
        <form method="post" action="{{ route('admin.tenants.update.apply', $tenant) }}" class="card">
            @csrf
            <p class="muted small">Marcado = seguro aplicar. Desmarcado = o cliente personalizou isso ou a mudança tira conteúdo do prompt. Conflitos não podem ser aplicados.</p>
            <ul class="changes">
                @foreach ($changes as $ch)
                    <li class="{{ $ch['applicable'] ? '' : 'is-off' }}">
                        <label class="check">
                            <input type="checkbox" name="changes[]" value="{{ $ch['id'] }}" @checked($ch['checked']) @disabled(! $ch['applicable'])>
                            {{ $ch['label'] }}
                        </label>
                        @if ($ch['warning'])<p class="help warn-text">{{ $ch['warning'] }}</p>@endif
                    </li>
                @endforeach
            </ul>
            <div class="actions">
                <button class="btn ghost" form="dismiss">Dispensar sem aplicar</button>
                <button class="btn primary">Aplicar selecionadas</button>
            </div>
        </form>
        <form id="dismiss" method="post" action="{{ route('admin.tenants.update.dismiss', $tenant) }}"
              data-confirm="Dispensar? O aviso some e nada muda neste cliente.">@csrf</form>
    @endif
@endsection
