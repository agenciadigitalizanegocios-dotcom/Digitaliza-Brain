@php
    $done = collect($nav)->where('done', true)->count();
    $total = count($nav);
@endphp

<aside class="sidenav">
    <div class="progress">
        <div class="progress-label">
            <strong>{{ $done }} de {{ $total }}</strong> seções preenchidas
        </div>
        <div class="progress-bar"><span style="width: {{ round($done / max($total, 1) * 100) }}%"></span></div>
    </div>

    <nav>
        @foreach ($nav as $key => $entry)
            <a href="{{ route('brain.tab', $key) }}" class="navitem {{ ($active ?? null) === $key ? 'active' : '' }}">
                <span class="navicon">{{ $entry['section']['icon'] }}</span>
                <span class="navlabel">{{ $entry['section']['label'] }}</span>
                @if ($entry['count'] !== null)
                    <span class="count {{ $entry['count'] ? '' : 'zero' }}">{{ $entry['count'] }}</span>
                @else
                    <span class="dot {{ $entry['done'] ? 'ok' : '' }}" title="{{ $entry['done'] ? 'Preenchido' : 'Falta preencher' }}"></span>
                @endif
            </a>
        @endforeach

        @if (isset($currentTenant) && $currentTenant->moduleFlags()['catalogo'])
            <a href="{{ route('products.index') }}" class="navitem {{ ($active ?? null) === 'produtos' ? 'active' : '' }} sep">
                <span class="navicon">🛍️</span>
                <span class="navlabel">Produtos</span>
            </a>
            <a href="{{ route('vitrine.settings') }}" class="navitem {{ ($active ?? null) === 'vitrine' ? 'active' : '' }}">
                <span class="navicon">🎨</span>
                <span class="navlabel">Vitrine</span>
            </a>
        @endif

        <a href="{{ route('brain.versions.index') }}" class="navitem {{ ($active ?? null) === 'versoes' ? 'active' : '' }} sep">
            <span class="navicon">🕘</span>
            <span class="navlabel">Histórico de versões</span>
        </a>
    </nav>
</aside>
