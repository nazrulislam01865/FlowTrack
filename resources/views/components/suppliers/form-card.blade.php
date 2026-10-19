@props([
    'title',
    'copy' => null,
    'badge' => null,
    'class' => '',
])
<section {{ $attributes->merge(['class' => 'ft-supplier-form-card '.$class]) }}>
    <div class="ft-supplier-form-card-head">
        <div class="ft-supplier-form-card-title-row">
            <h2>{{ $title }}</h2>
            @if($badge)<span class="ft-supplier-form-card-badge">{{ $badge }}</span>@endif
        </div>
        @if($copy)<p>{{ $copy }}</p>@endif
    </div>
    {{ $slot }}
</section>
