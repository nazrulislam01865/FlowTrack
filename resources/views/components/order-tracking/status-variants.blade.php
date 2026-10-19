@props(['section', 'currentStatusKey' => null])
@php
    $stage = (int) ($section['stage'] ?? 1);
    $layout = $section['layout'] ?? 'three';
    $inlineHeading = (bool) ($section['inline_heading'] ?? false);
    $softPanel = (bool) ($section['soft_panel'] ?? false);
    $footerNote = $section['footer_note'] ?? null;
    $footerLabel = $section['footer_label'] ?? null;
@endphp

<section
    class="ft-tracking-variants ft-tracking-variants--stage-{{ $stage }} is-{{ $layout }} {{ $softPanel ? 'is-soft-panel' : '' }}"
    aria-labelledby="tracking-stage-{{ $stage }}-variants-title"
>
    <header class="ft-tracking-variants-head {{ $inlineHeading ? 'is-inline' : '' }}">
        @if($stage !== 7)
            <span class="ft-tracking-variants-stage-label">{{ $section['stage_label'] }}</span>
        @endif
        <h2 id="tracking-stage-{{ $stage }}-variants-title">{{ $section['title'] }}</h2>
        @if(filled($section['note'] ?? null))
            <p>{{ $section['note'] }}</p>
        @endif
    </header>

    <div class="ft-tracking-variant-list">
        @foreach($section['items'] as $item)
            <article class="ft-tracking-variant-card is-{{ $item['tone'] }} {{ $currentStatusKey === $item['key'] ? 'is-current' : '' }}">
                <span class="ft-tracking-variant-icon" aria-hidden="true">
                    <x-order-tracking.icon :name="$item['icon']" :size="$layout === 'stacked' ? 28 : 25" />
                </span>
                <div class="ft-tracking-variant-copy">
                    <strong>{{ $item['title'] }}</strong>
                    <p>{{ $item['message'] }}</p>
                </div>
            </article>
        @endforeach
    </div>

    @if($footerNote || $footerLabel)
        <footer class="ft-tracking-variants-footer">
            @if($footerNote)
                <p>{{ $footerNote }}</p>
            @endif
            @if($footerLabel)
                <span>{{ $footerLabel }}</span>
            @endif
        </footer>
    @endif
</section>
