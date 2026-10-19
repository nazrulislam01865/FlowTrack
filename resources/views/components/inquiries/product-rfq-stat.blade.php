@props([
    'label',
    'value' => 0,
    'icon' => 'product',
])

<article {{ $attributes->class(['ft-inquiry-prq-stat']) }}>
    <span class="ft-inquiry-prq-stat__icon" aria-hidden="true">
        @switch($icon)
            @case('suppliers')
                <svg viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3"></circle><path d="M3.5 19c.45-3.2 2.3-4.8 5.5-4.8s5.05 1.6 5.5 4.8"></path><circle cx="17.5" cy="9" r="2.2"></circle><path d="M15.7 14.2c2.8-.25 4.4 1.35 4.8 4.15"></path></svg>
                @break
            @case('sent')
                <svg viewBox="0 0 24 24" fill="none"><path d="M3.5 11.2 20 4.5l-5.1 15-3.2-6.2-8.2-2.1Z"></path><path d="m11.7 13.3 4.4-4.5"></path></svg>
                @break
            @case('quotes')
                <svg viewBox="0 0 24 24" fill="none"><rect x="5" y="3.5" width="14" height="17" rx="1.5"></rect><path d="M8.5 8h7M8.5 11.5h7M8.5 15h4.5"></path></svg>
                @break
            @default
                <svg viewBox="0 0 24 24" fill="none"><path d="m4 7 8-4 8 4-8 4-8-4Z"></path><path d="M4 7v9l8 4 8-4V7M12 11v9"></path></svg>
        @endswitch
    </span>
    <div class="ft-inquiry-prq-stat__copy">
        <strong>{{ number_format((int) $value) }}</strong>
        <span>{{ $label }}</span>
    </div>
</article>
