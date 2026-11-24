@if ($paginator->hasPages())
    <nav class="pagination">
        @if ($paginator->onFirstPage())
            <span class="btn small ghost" aria-disabled="true">← Mais novas</span>
        @else
            <a class="btn small ghost" href="{{ $paginator->previousPageUrl() }}">← Mais novas</a>
        @endif

        <span class="muted">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="btn small ghost" href="{{ $paginator->nextPageUrl() }}">Mais antigas →</a>
        @else
            <span class="btn small ghost" aria-disabled="true">Mais antigas →</span>
        @endif
    </nav>
@endif
