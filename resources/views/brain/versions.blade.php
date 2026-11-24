@extends('layouts.app')

@section('title', 'Histórico de versões')
@section('main-class', 'shell')

@section('content')
<div class="layout">
    @include('brain.partials.nav', ['nav' => $nav, 'active' => 'versoes'])

    <section class="content">
        <div class="section-head">
            <div>
                <h1><span class="h-icon">🕘</span> Histórico de versões</h1>
                <p class="muted">Cada alteração vira uma versão. Dá para ver o texto exato que a IA recebeu e voltar para qualquer versão.</p>
            </div>
        </div>

        @if ($versions->isEmpty())
            <div class="empty card">
                <div class="empty-icon">🕘</div>
                <h2>Nenhuma versão ainda</h2>
                <p class="muted">Assim que você salvar algo no cérebro, a primeira versão aparece aqui.</p>
            </div>
        @else
            <div class="card table-card">
                <table class="table">
                    <thead>
                        <tr><th>Versão</th><th>O que mudou</th><th>Quem</th><th>Quando</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($versions as $v)
                            <tr>
                                <td>
                                    <strong>v{{ $v->version }}</strong>
                                    @if ($v->version === $current) <span class="badge on">em uso</span> @endif
                                </td>
                                <td>{{ $v->description }}</td>
                                <td>{{ $v->user?->name ?? 'Sistema' }}</td>
                                <td title="{{ $v->created_at->format('d/m/Y H:i') }}">{{ $v->created_at->format('d/m H:i') }}</td>
                                <td class="right">
                                    <a class="btn small ghost" href="{{ route('brain.versions.show', $v->version) }}">Ver</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $versions->links('brain.partials.pagination') }}
        @endif
    </section>
</div>
@endsection
