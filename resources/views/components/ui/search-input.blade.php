@props([
    'property' => 'search',
    'value' => '',
    'placeholder' => 'Search…',
    'label' => 'Search',
    'debounce' => 300,
    'clearAction' => null,
    'hint' => null,
    'shortcut' => '/',
    'hideLabel' => false,
])
<div
    {{ $attributes->class(['ft-search-input', 'ft-jl-control', 'ft-jl-control-search']) }}
    data-ft-ui-component="search-input"
    x-data
    @if($shortcut === '/') x-on:keydown.window="if ($event.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName)) { $event.preventDefault(); $refs.search.focus(); }" @endif
    @if($shortcut === 'mod+k') x-on:keydown.window.meta.k.prevent="$refs.search.focus()" x-on:keydown.window.ctrl.k.prevent="$refs.search.focus()" @endif
>
    <label class="ft-search-input__label {{ $hideLabel ? 'u-sr-only' : '' }}">{{ $label }}</label>
    <div class="ft-search-input__control ft-jl-search-wrap">
        <span class="ft-search-input__icon ft-jl-search-icon" aria-hidden="true">⌕</span>
        <input
            x-ref="search"
            class="ft-search-input__input ft-jl-search"
            type="text"
            role="searchbox"
            inputmode="search"
            @if($debounce === 700) wire:model.live.debounce.700ms="{{ $property }}"
            @elseif($debounce === 650) wire:model.live.debounce.650ms="{{ $property }}"
            @elseif($debounce === 400) wire:model.live.debounce.400ms="{{ $property }}"
            @elseif($debounce === 350) wire:model.live.debounce.350ms="{{ $property }}"
            @else wire:model.live.debounce.300ms="{{ $property }}" @endif
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            x-on:keydown.escape="if ($el.value) { @if($clearAction) $wire.call(@js($clearAction)) @else $wire.set(@js($property), '') @endif; }"
        >
        @if(filled($value))
            <button type="button" class="ft-search-input__clear ft-jl-clear-search" @if($clearAction) wire:click="{{ $clearAction }}" @else wire:click="$set('{{ $property }}', '')" @endif aria-label="Clear search">×</button>
        @endif
    </div>
    @if($hint)<small class="ft-search-input__hint">{{ $hint }}</small>@endif
</div>
