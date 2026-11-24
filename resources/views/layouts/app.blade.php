<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Painel') · Digitaliza Brain</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v=2">
</head>
<body>
<header class="topbar">
    <a class="brand" href="{{ url('/') }}">
        <span class="brand-mark">🧠</span>
        <span>Digitaliza Brain</span>
    </a>

    @auth
        <nav class="topnav">
            @isset($currentTenant)
                <span class="tenant-pill">
                    {{ $currentTenant->name }}
                    <span class="status {{ $currentTenant->isActive() ? 'on' : 'off' }}">{{ $currentTenant->status }}</span>
                </span>
            @endisset
            @if (auth()->user()->isAdmin())
                <a href="{{ route('admin.tenants.index') }}" class="toplink">Clientes</a>
                <a href="{{ route('admin.templates.index') }}" class="toplink">Modelos</a>
                <a href="{{ route('admin.audit') }}" class="toplink">Registro</a>
            @endif
            <span class="user">{{ auth()->user()->name }}</span>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button class="btn ghost small">Sair</button>
            </form>
        </nav>
    @endauth
</header>

@auth
    @if (auth()->user()->isAdmin() && ! empty($debugInProduction))
        <div class="banner warn"><strong>Atenção:</strong> APP_DEBUG está ligado em produção. Qualquer erro mostra a configuração do sistema. Coloque APP_DEBUG=false no .env.</div>
    @endif
    @if (auth()->user()->isAdmin() && isset($currentTenant))
        <div class="banner info">
            Você está no painel de <strong>{{ $currentTenant->name }}</strong> como admin da Digitaliza.
            · <a href="{{ route('admin.structure.show', ['clientes', $currentTenant->id]) }}">Editar a estrutura</a> ·
            <form method="post" action="{{ route('admin.tenants.leave') }}" class="inline">
                @csrf
                <button class="linkish">Voltar para a lista de clientes</button>
            </form>
        </div>
    @endif
    @if (isset($currentTenant) && ! $currentTenant->isActive())
        <div class="banner warn">Este cliente está suspenso: o robô não responde no WhatsApp até ser reativado.</div>
    @endif
@endauth

<main class="@yield('main-class', 'container')">
    @if (session('ok'))
        <div class="flash ok" data-autohide>{{ session('ok') }}</div>
    @endif
    @if (session('info'))
        <div class="flash info">{{ session('info') }}</div>
    @endif
    @error('limite')
        <div class="flash error">{{ $message }}</div>
    @enderror
    @if ($errors->any() && request()->routeIs('admin.structure.*') && ! $errors->has('structure'))
        <div class="flash error">@foreach ($errors->all() as $msg)<div>{{ $msg }}</div>@endforeach</div>
    @endif

    @yield('content')
</main>

<script src="{{ asset('js/app.js') }}?v=2" defer></script>
</body>
</html>
