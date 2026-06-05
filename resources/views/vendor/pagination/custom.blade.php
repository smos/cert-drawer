@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination Navigation" style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; margin-top: 20px; gap: 15px;">
        <div style="font-size: 0.85rem; color: #666; margin-right: 10px;">
            Showing {{ $paginator->firstItem() }} to {{ $paginator->lastItem() }} of {{ $paginator->total() }} results
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 2px;">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span style="display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid #ddd; background: #f8f9fa; color: #ccc; cursor: not-allowed; border-radius: 4px 0 0 4px; font-size: 0.85rem; height: 32px;">&laquo; Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" style="display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid #ddd; background: white; color: #333; text-decoration: none; border-radius: 4px 0 0 4px; font-size: 0.85rem; height: 32px;">&laquo; Previous</a>
            @endif

            {{-- Pagination Elements --}}
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span style="display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid #ddd; background: #f8f9fa; color: #666; font-size: 0.85rem; height: 32px;">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span style="display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid #3498db; background: #3498db; color: white; font-size: 0.85rem; font-weight: bold; height: 32px;">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" style="display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid #ddd; background: white; color: #333; text-decoration: none; font-size: 0.85rem; height: 32px;">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" style="display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid #ddd; background: white; color: #333; text-decoration: none; border-radius: 0 4px 4px 0; font-size: 0.85rem; height: 32px;">Next &raquo;</a>
            @else
                <span style="display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid #ddd; background: #f8f9fa; color: #ccc; cursor: not-allowed; border-radius: 0 4px 4px 0; font-size: 0.85rem; height: 32px;">Next &raquo;</span>
            @endif
        </div>
    </nav>
@endif
