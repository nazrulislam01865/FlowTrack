@props([
    'title' => 'Nothing to show',
    'description' => null,
])

<div {{ $attributes->class(['ft-empty-state']) }} data-ft-ui-component="empty-state">
    @if(isset($icon))<div class="ft-empty-state__icon" aria-hidden="true">{{ $icon }}</div>@endif
    <h3 class="ft-empty-state__title">{{ $title }}</h3>
    @if(filled($description))<p class="ft-empty-state__description">{{ $description }}</p>@endif
    @if(isset($action))<div class="ft-empty-state__action">{{ $action }}</div>@endif
</div>
