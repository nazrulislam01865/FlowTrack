@props([
    'title',
    'description' => null,
])

<header {{ $attributes->class(['ft-section-header']) }} data-ft-ui-component="section-header">
    <div class="ft-section-header__copy">
        <h2 class="ft-section-header__title">{{ $title }}</h2>
        @if(filled($description))<p class="ft-section-header__description">{{ $description }}</p>@endif
    </div>
    @if(isset($actions))<div class="ft-section-header__actions">{{ $actions }}</div>@endif
</header>
