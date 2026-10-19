@props(['action' => 'clearFilters', 'label' => 'Clear filters', 'disabled' => false, 'icon' => null])
<button type="button" {{ $attributes->class(['ft-filter-reset']) }} data-ft-ui-component="filter-reset" wire:click="{{ $action }}" @disabled($disabled)>
    @if($icon)<span class="ft-filter-reset__icon" aria-hidden="true">{{ $icon }}</span>@endif
    <span>{{ $label }}</span>
</button>
