@props([
    'id',
    'title',
    'size' => 'md',
    'open' => false,
])

<div
    {{ $attributes->class(['ft-modal', 'ft-modal--sm' => $size === 'sm', 'ft-modal--lg' => $size === 'lg']) }}
    data-ft-ui-component="modal"
    @unless($open || $attributes->has('x-show')) hidden @endunless
>
    <div class="ft-modal__backdrop" aria-hidden="true"></div>
    <section class="ft-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" tabindex="-1">
        <header class="ft-modal__header">
            <h2 id="{{ $id }}-title" class="ft-modal__title">{{ $title }}</h2>
            @if(isset($close)){{ $close }}@endif
        </header>
        <div class="ft-modal__body">{{ $slot }}</div>
        @if(isset($footer))<footer class="ft-modal__footer">{{ $footer }}</footer>@endif
    </section>
</div>
