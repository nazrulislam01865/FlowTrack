@props([
    'step',
    'title',
    'description' => null,
    'required' => false,
])

<section {{ $attributes->class(['ft-form-section']) }}>
    <header class="ft-form-section-header">
        <div class="ft-form-section-heading">
            <span class="ft-form-step" aria-hidden="true">{{ $step }}</span>
            <div class="ft-form-section-copy">
                <div class="ft-form-section-title-row">
                    <h3>{{ $title }}</h3>
                    @if($required)
                        <span class="ft-form-required-badge">Required</span>
                    @endif
                </div>
                @if($description)
                    <p>{{ $description }}</p>
                @endif
            </div>
        </div>

        @isset($action)
            <div class="ft-form-section-action">{{ $action }}</div>
        @endisset
    </header>

    <div class="ft-form-section-body">
        {{ $slot }}
    </div>
</section>
