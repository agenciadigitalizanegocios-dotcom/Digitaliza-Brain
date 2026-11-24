@extends('layouts.app')

@section('title', 'Versão '.$version->version)
@section('main-class', 'shell')

@section('content')
<div class="layout">
    @include('brain.partials.nav', ['nav' => $nav, 'active' => 'versoes'])

    <section class="content">
        <a href="{{ route('brain.versions.index') }}" class="back">← Histórico de versões</a>

        <div class="section-head">
            <div>
                <h1>Versão {{ $version->version }}
                    @if ($version->version === $current) <span class="badge on">em uso</span> @endif
                </h1>
                <p class="muted">
                    {{ $version->description }} · {{ $version->created_at->format('d/m/Y \à\s H:i') }}
                    · {{ $version->user?->name ?? 'Sistema' }}
                </p>
            </div>

            @if ($version->version !== $current && ($canEdit ?? true))
                <form method="post" action="{{ route('brain.versions.restore', $version->version) }}"
                      data-confirm="Voltar o cérebro para a versão {{ $version->version }}? O robô passa a usar esta versão na hora. Nada se perde: o estado atual continua no histórico.">
                    @csrf
                    <button class="btn primary">Restaurar esta versão</button>
                </form>
            @endif
        </div>

        <div class="card">
            <div class="card-head">
                <h2>Texto que a IA recebe</h2>
                <button type="button" class="btn small" data-copy="#prompt">Copiar</button>
            </div>
            <pre id="prompt" class="prompt">{{ $version->prompt }}</pre>
        </div>
    </section>
</div>
@endsection
