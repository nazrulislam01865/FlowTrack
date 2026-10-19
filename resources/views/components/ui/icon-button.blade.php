@props([
    'label',
    'size' => 'md',
    'type' => 'button',
    'disabled' => false,
    'loading' => false,
])

<button
    type="{{ $type }}"
    {{ $attributes->class([
        'ft-icon-btn',
        'ft-icon-btn--sm' => $size === 'sm',
        'ft-icon-btn--lg' => $size === 'lg',
    ]) }}
    data-ft-ui-component="icon-button"
    aria-label="{{ $label }}"
    title="{{ $label }}"
    @disabled($disabled || $loading)
    @if($loading) aria-busy="true" @endif
>
    @if($loading)
        <span class="ft-icon-btn__spinner" aria-hidden="true"></span>
    @else
        {{ $slot }}
    @endif
</button>
