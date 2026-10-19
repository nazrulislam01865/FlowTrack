@props([
    'model',
    'label',
    'options' => [],
])

<label class="ft-product-filter-select" title="{{ $label }}">
    <span class="ft-product-visually-hidden">{{ $label }}</span>
    <select wire:model.live="{{ $model }}" aria-label="{{ $label }}">
        {{ $slot }}
    </select>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path d="m8 10 4 4 4-4"/>
    </svg>
</label>
