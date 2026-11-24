@extends('layouts.app')

@section('title', 'Registro')

@section('content')
    <div class="section-head">
        <div>
            <h1>Registro de ações</h1>
            <p class="muted">Logins, senhas erradas, tokens, suspensões e mudanças de estrutura. As mudanças de conteúdo ficam no histórico de versões de cada cliente.</p>
        </div>
        <form method="get" class="inline-row">
            <select name="acao" data-autosubmit>
                <option value="">Todas as ações</option>
                @foreach (\App\Support\Audit::LABELS as $key => $label)
                    <option value="{{ $key }}" @selected(request('acao') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <div class="card table-card">
        <table class="table">
            <thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Cliente</th><th>Detalhes</th><th>IP</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td>{{ $log->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ $log->user?->name ?? '—' }}</td>
                        <td>{{ \App\Support\Audit::LABELS[$log->action] ?? $log->action }}</td>
                        <td>{{ $log->tenant?->name ?? '—' }}</td>
                        <td class="small">
                            @foreach ($log->details ?? [] as $k => $v)
                                <span class="muted">{{ $k }}:</span> {{ is_scalar($v) ? (is_bool($v) ? ($v ? 'sim' : 'não') : $v) : json_encode($v, JSON_UNESCAPED_UNICODE) }}@if (! $loop->last) · @endif
                            @endforeach
                        </td>
                        <td class="small muted">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">Nada registrado ainda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $logs->links('brain.partials.pagination') }}
@endsection
