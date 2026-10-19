@props([
    'flat' => false,
])

<section {{ $attributes->class(['ft-card', 'ft-card--flat' => $flat]) }} data-ft-ui-component="card">
    @if(isset($header))<div class="ft-card__header">{{ $header }}</div>@endif
    <div class="ft-card__body">{{ $slot }}</div>
    @if(isset($footer))<div class="ft-card__footer">{{ $footer }}</div>@endif
</section>
