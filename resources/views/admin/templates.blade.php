@extends('layouts.app')

@section('title', 'Modelos')

@section('content')
    <div class="section-head">
        <div>
            <h1>Modelos de nicho</h1>
            <p class="muted">Todo cliente nasce de um modelo, que é copiado para ele. Mudar um modelo não muda os clientes: eles ganham o selo “atualização pendente”.</p>
        </div>
    </div>

    <div class="card table-card">
        <table class="table">
            <thead><tr><th>Modelo</th><th>Abas</th><th>Versão</th><th>Clientes</th><th></th></tr></thead>
            <tbody>
                @foreach ($templates as $template)
                    <tr>
                        <td><strong>{{ $template->name }}</strong><br><span class="muted small">{{ $template->description }}</span></td>
                        <td>{{ collect($template->structure['tabs'] ?? [])->where('archived', false)->count() }}</td>
                        <td>v{{ $template->version }}</td>
                        <td>{{ $template->tenants_count }}</td>
                        <td class="right actions-cell">
                            <a class="btn small primary" href="{{ route('admin.structure.show', ['modelos', $template->id]) }}">Editar estrutura</a>
                            <button type="button" class="btn small ghost" data-open-dialog="#dup-{{ $template->id }}">Duplicar</button>
                            <button type="button" class="btn small ghost" data-open-dialog="#ren-{{ $template->id }}">Renomear</button>
                            @if (! $template->tenants_count)
                                <form method="post" action="{{ route('admin.templates.destroy', $template) }}" data-confirm="Excluir o modelo {{ $template->name }}?">
                                    @csrf @method('DELETE')
                                    <button class="btn small ghost danger">Excluir</button>
                                </form>
                            @endif
                        </td>
                    </tr>

                    <dialog id="dup-{{ $template->id }}" class="modal">
                        <form method="post" action="{{ route('admin.templates.duplicate', $template) }}">
                            @csrf
                            <div class="modal-head"><h2>Duplicar {{ $template->name }}</h2><button type="button" class="btn ghost icon" data-close>✕</button></div>
                            <div class="field"><label class="label">Nome do novo modelo</label><input type="text" name="name" required placeholder="Ótica"></div>
                            <p class="help">Bom para criar um nicho novo partindo de um parecido.</p>
                            <div class="actions"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary">Duplicar</button></div>
                        </form>
                    </dialog>
                    <dialog id="ren-{{ $template->id }}" class="modal">
                        <form method="post" action="{{ route('admin.templates.rename', $template) }}">
                            @csrf @method('PATCH')
                            <div class="modal-head"><h2>Renomear</h2><button type="button" class="btn ghost icon" data-close>✕</button></div>
                            <div class="field"><label class="label">Nome</label><input type="text" name="name" value="{{ $template->name }}" required></div>
                            <div class="field"><label class="label">Descrição</label><input type="text" name="description" value="{{ $template->description }}"></div>
                            <div class="actions"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary">Salvar</button></div>
                        </form>
                    </dialog>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
