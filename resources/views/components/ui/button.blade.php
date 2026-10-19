@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'href' => null,
    'disabled' => false,
    'loading' => false,
    'loadingLabel' => 'Working…',
])

@if($href)
    <a
        @unless($disabled || $loading) href="{{ $href }}" @endunless
        {{ $attributes->class([
            'ft-btn',
            'ft-btn--primary' => $variant === 'primary',
            'ft-btn--secondary' => $variant === 'secondary',
            'ft-btn--tertiary' => $variant === 'tertiary',
            'ft-btn--danger' => $variant === 'danger',
            'ft-btn--sm' => $size === 'sm',
            'ft-btn--lg' => $size === 'lg',
        ]) }}
        data-ft-ui-component="button"
        @if($disabled || $loading) aria-disabled="true" tabindex="-1" @endif
        @if($loading) aria-busy="true" @endif
    >
        @if($loading)<span class="ft-btn__spinner" aria-hidden="true"></span>@endif
        <span>{{ $loading ? $loadingLabel : $slot }}</span>
    </a>
@else
    <button
        type="{{ $type }}"
        {{ $attributes->class([
            'ft-btn',
            'ft-btn--primary' => $variant === 'primary',
            'ft-btn--secondary' => $variant === 'secondary',
            'ft-btn--tertiary' => $variant === 'tertiary',
            'ft-btn--danger' => $variant === 'danger',
            'ft-btn--sm' => $size === 'sm',
            'ft-btn--lg' => $size === 'lg',
        ]) }}
        data-ft-ui-component="button"
        @disabled($disabled || $loading)
        @if($loading) aria-busy="true" @endif
    >
        @if($loading)<span class="ft-btn__spinner" aria-hidden="true"></span>@endif
        <span>{{ $loading ? $loadingLabel : $slot }}</span>
    </button>
@endif
