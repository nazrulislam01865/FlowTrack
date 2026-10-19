        <div class="list-pagination">
            <span>@if($jobs->total()) Showing {{ $jobs->firstItem() }}–{{ $jobs->lastItem() }} of {{ number_format($jobs->total()) }} orders @else No orders found @endif</span>
            <div class="page-controls">
                <button class="btn small page-arrow" type="button" wire:click="previousPage" @disabled($jobs->onFirstPage()) aria-label="Previous page">←</button>
                @foreach($pageNumbers as $pageNumber)
                    <button type="button" class="btn small page-number {{ $pageNumber === $currentPage ? 'primary' : '' }}" wire:click="gotoPage({{ $pageNumber }})" @if($pageNumber === $currentPage) aria-current="page" @endif>{{ $pageNumber }}</button>
                @endforeach
                <button class="btn small page-arrow" type="button" wire:click="nextPage" @disabled(!$jobs->hasMorePages()) aria-label="Next page">→</button>
            </div>
        </div>
    </section>
