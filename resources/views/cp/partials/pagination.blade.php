@if ($paginator->hasPages())
    @if ($paginator->onFirstPage())
        <span style="opacity:.4;">&laquo;</span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}">&laquo;</a>
    @endif

    <span>{{ __('Page :current of :last', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</span>

    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}">&raquo;</a>
    @else
        <span style="opacity:.4;">&raquo;</span>
    @endif
@endif
