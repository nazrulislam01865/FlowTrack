@props(['value'])
@php $sizeValue = trim((string) $value); @endphp
@if($sizeValue === '')
    <span class="ft-product-size-empty">—</span>
@else
    <div class="ft-product-size-control" x-data="{ copied: false, left: 0, top: 0 }">
        <button
            x-ref="trigger"
            type="button"
            class="ft-product-size-trigger"
            title="View full size details"
            x-on:click="
                const r = $refs.trigger.getBoundingClientRect();
                const w = Math.min(360, window.innerWidth - 24);
                left = Math.max(12, Math.min(r.left, window.innerWidth - w - 12));
                top = r.bottom + 8;
                if (top + 180 > window.innerHeight) top = Math.max(12, r.top - 188);
                $refs.panel.style.left = left + 'px';
                $refs.panel.style.top = top + 'px';
                $refs.panel.style.width = w + 'px';
                $refs.panel.showPopover();
            "
        >{{ $sizeValue }}</button>
        <div x-ref="panel" popover="auto" class="ft-product-size-popover">
            <strong>Full size details</strong>
            <p>{{ $sizeValue }}</p>
            <button type="button" x-on:click="navigator.clipboard?.writeText(@js($sizeValue)); copied=true; setTimeout(()=>copied=false,1600)" x-text="copied ? 'Copied' : 'Copy details'"></button>
        </div>
    </div>
@endif
