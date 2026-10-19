@props([
    'title',
    'subtitle' => null,
])

<header {{ $attributes->class(['ft-page-header']) }} data-ft-ui-component="page-header">
    <div class="ft-page-header__copy">
        <h1 class="ft-page-header__title">{{ $title }}</h1>
        @if(filled($subtitle))<p class="ft-page-header__subtitle">{{ $subtitle }}</p>@endif
    </div>
    @if(isset($actions))<div class="ft-page-header__actions">{{ $actions }}</div>@endif
</header>
