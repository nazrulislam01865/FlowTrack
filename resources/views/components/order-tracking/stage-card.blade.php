@props(['stage', 'variant' => 'compact'])
<article
    class="ft-tracking-stage ft-tracking-stage--{{ $variant }} is-{{ $stage['state'] }}"
    data-stage="{{ $stage['sequence'] }}"
>
    @if($variant === 'detailed')
        <span class="ft-tracking-stage-icon">
            <x-order-tracking.icon :name="$stage['icon']" :size="23" />
        </span>
    @endif

    @if(($stage['marker'] ?? null) === 'check')
        <span class="ft-tracking-stage-check"><x-order-tracking.icon name="check" :size="14" /></span>
    @elseif(($stage['marker'] ?? null) === 'dot')
        <span class="ft-tracking-stage-dot" aria-hidden="true"></span>
    @endif

    <div class="ft-tracking-stage-copy">
        <small>STAGE {{ $stage['sequence'] }}</small>
        <strong>{{ $stage['name'] }}</strong>
        <span class="ft-tracking-stage-status">{{ $stage['status'] }}</span>
    </div>

    <progress
        class="ft-tracking-stage-line"
        max="100"
        value="{{ max(0, min(100, (int) ($stage['progress'] ?? 0))) }}"
        aria-label="{{ $stage['name'] }} progress"
    ></progress>
</article>
