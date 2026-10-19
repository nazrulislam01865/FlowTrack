@props([
    'selected' => false,
    'controls' => null,
    'type' => 'button',
])

<button
    type="{{ $type }}"
    {{ $attributes->class(['ft-tabs__tab']) }}
    data-ft-ui-component="tab"
    role="tab"
    aria-selected="{{ $selected ? 'true' : 'false' }}"
    @if($controls) aria-controls="{{ $controls }}" @endif
    tabindex="{{ $selected ? '0' : '-1' }}"
>
    {{ $slot }}
</button>
