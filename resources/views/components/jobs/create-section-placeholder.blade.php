@props(['number', 'title', 'section', 'rows' => 3])

<section
    class="ft-create-section ft-progressive-section-placeholder"
    wire:key="create-{{ $section }}-placeholder"
    role="status"
    aria-live="polite"
    aria-busy="true"
    x-data
    x-init="
        if (!window.IntersectionObserver) {
            $wire.loadCreateSection(@js($section));
            return;
        }
        const observer = new IntersectionObserver((entries) => {
            if (!entries[0]?.isIntersecting) return;
            observer.disconnect();
            $wire.loadCreateSection(@js($section));
        }, { rootMargin: '240px 0px' });
        observer.observe($el);
    "
>
    <div class="ft-create-section-title"><span>{{ $number }}</span><h2>{{ $title }}</h2></div>
    <div class="ft-progressive-skeleton" aria-hidden="true">
        @for($row = 0; $row < $rows; $row++)
            <span style="--ft-skeleton-width: {{ 100 - (($row % 3) * 12) }}%"></span>
        @endfor
    </div>
    <small class="muted">Loading this section when needed…</small>
</section>
