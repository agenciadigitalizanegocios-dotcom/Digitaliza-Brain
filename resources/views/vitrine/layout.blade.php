<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ $tenant->name }}</title>
    @if ($settings['building'])<meta name="robots" content="noindex, nofollow">@endif
    <meta name="description" content="@yield('description', \Illuminate\Support\Str::limit($settings['about'] ?: 'Veja as opções de '.$tenant->name.'.', 155))">
    <link rel="canonical" href="@yield('canonical', $tenant->vitrineUrl())">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $tenant->name }}">
    <meta property="og:title" content="@yield('title') · {{ $tenant->name }}">
    <meta property="og:description" content="@yield('description', \Illuminate\Support\Str::limit($settings['about'] ?: 'Veja as opções de '.$tenant->name.'.', 155))">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    <link rel="stylesheet" href="{{ asset('css/vitrine.css') }}?v=1">
</head>
<body style="--brand: {{ $settings['color'] }}">
<header class="v-header">
    <div class="v-wrap v-header-row">
        <a href="{{ $tenant->vitrineUrl() }}" class="v-brand">
            @if ($settings['logo'])
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($settings['logo']) }}" alt="{{ $tenant->name }}">
            @else
                <span>{{ $tenant->name }}</span>
            @endif
        </a>
        @if ($settings['whatsapp'])
            <a class="v-btn v-btn-wa" href="https://wa.me/{{ $settings['whatsapp'] }}" target="_blank" rel="noopener">Falar no WhatsApp</a>
        @endif
    </div>
</header>

<main class="v-wrap v-main">
    @yield('content')
</main>

<footer class="v-footer">
    <div class="v-wrap v-footer-grid">
        <div>
            <strong>{{ $tenant->name }}</strong>
            @if ($settings['about'])<p>{{ $settings['about'] }}</p>@endif
        </div>
        <ul class="v-contacts">
            @if ($settings['whatsapp'])<li><a href="https://wa.me/{{ $settings['whatsapp'] }}" target="_blank" rel="noopener">WhatsApp</a></li>@endif
            @if ($settings['phone'])<li><a href="tel:{{ preg_replace('/[^0-9+]/', '', $settings['phone']) }}">{{ $settings['phone'] }}</a></li>@endif
            @if ($settings['email'])<li><a href="mailto:{{ $settings['email'] }}">{{ $settings['email'] }}</a></li>@endif
            @if ($settings['instagram'])<li><a href="https://instagram.com/{{ $settings['instagram'] }}" target="_blank" rel="noopener">{{ '@'.$settings['instagram'] }}</a></li>@endif
            @if ($settings['address'])<li>{{ $settings['address'] }}</li>@endif
        </ul>
    </div>
    <p class="v-wrap v-credit">Vitrine por Digitaliza Negócios</p>
</footer>
<script src="{{ asset('js/vitrine.js') }}?v=1" defer></script>
</body>
</html>
