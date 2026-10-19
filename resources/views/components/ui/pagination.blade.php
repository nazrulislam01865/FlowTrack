@props([
    'page' => 1,
    'lastPage' => 1,
    'previousAction' => 'previousPage',
    'nextAction' => 'nextPage',
    'label' => null,
])

<nav {{ $attributes->class(['ft-pagination']) }} data-ft-ui-component="pagination" aria-label="Pagination">
    <span class="ft-pagination__status">{{ $label ?: 'Page '.$page.' of '.$lastPage }}</span>
    <div class="ft-pagination__actions">
        <x-ui.button variant="secondary" size="sm" wire:click="{{ $previousAction }}" :disabled="$page <= 1" aria-label="Previous page">← Previous</x-ui.button>
        <x-ui.button variant="secondary" size="sm" wire:click="{{ $nextAction }}" :disabled="$page >= $lastPage" aria-label="Next page">Next →</x-ui.button>
    </div>
</nav>
