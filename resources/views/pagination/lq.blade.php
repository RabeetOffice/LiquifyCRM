@if ($paginator->hasPages())
    <nav class="lq-pagination" role="navigation" aria-label="Pagination">
        <span>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</span>

        <div class="lq-pagination-links">
            @if ($paginator->onFirstPage())
                <button type="button" class="lq-page-btn" disabled aria-label="Previous">‹</button>
            @else
                <button type="button" class="lq-page-btn" wire:click="previousPage" wire:loading.attr="disabled" aria-label="Previous">‹</button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="lq-page-btn" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="lq-page-btn" aria-current="page">{{ $page }}</span>
                        @else
                            <button type="button" class="lq-page-btn" wire:click="gotoPage({{ $page }})">{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <button type="button" class="lq-page-btn" wire:click="nextPage" wire:loading.attr="disabled" aria-label="Next">›</button>
            @else
                <button type="button" class="lq-page-btn" disabled aria-label="Next">›</button>
            @endif
        </div>
    </nav>
@endif
