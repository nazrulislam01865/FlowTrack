@props(['as' => 'div', 'label' => 'Filters', 'loadingTarget' => null])
@if($as === 'section')
<section {{ $attributes->class(['ft-filter-bar']) }} data-ft-ui-component="filter-bar" role="group" aria-label="{{ $label }}" @if($loadingTarget) wire:loading.class="ft-filter-bar--updating" wire:target="{{ $loadingTarget }}" @endif>
    {{ $slot }}
</section>
@else
<div {{ $attributes->class(['ft-filter-bar']) }} data-ft-ui-component="filter-bar" role="group" aria-label="{{ $label }}" @if($loadingTarget) wire:loading.class="ft-filter-bar--updating" wire:target="{{ $loadingTarget }}" @endif>
    {{ $slot }}
</div>
@endif
