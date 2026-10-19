@props([
    'hold' => [],
    'canReleaseHold' => false,
])
@php
    $hold = is_array($hold) ? $hold : [];
    $holdFrom = trim((string) ($hold['holdFromLabel'] ?? '')) ?: '—';
    $sourceName = trim((string) ($hold['sourceName'] ?? '')) ?: '—';
    $heldBy = trim((string) ($hold['heldBy'] ?? '')) ?: 'Unknown user';
    $reason = trim((string) ($hold['reason'] ?? ''));
    $startedAt = $hold['startedAt'] ?? null;
@endphp
<div class="ft-order-hold-state-card" x-data="{ detailsOpen: false }">
    <div class="ft-order-hold-state-card__top">
        <div class="ft-order-hold-state-card__icon" aria-hidden="true">
            <span class="ft-order-hold-pause-icon"><i></i><i></i></span>
        </div>
        <div class="ft-order-hold-state-card__copy">
            <strong>Order activities are paused</strong>
            <span>{{ $holdFrom }} hold · held by {{ $heldBy }}</span>
        </div>
        <span class="ft-order-hold-state-card__badge">On Hold</span>
    </div>

    <div class="ft-order-hold-state-card__facts">
        <div><span>Hold from</span><b>{{ $holdFrom }}</b></div>
        <div><span>Held by</span><b>{{ $heldBy }}</b></div>
        @if($startedAt)
            <div><span>Started</span><b>{{ \App\Support\UserLocalTime::format($startedAt, 'M j · g:i A') }}</b></div>
        @endif
    </div>

    @if($reason !== '')
        <div class="ft-order-hold-state-card__reason">
            <span>Reason</span>
            <div><x-ui.mention-text :text="$reason" /></div>
        </div>
    @endif

    <div class="ft-order-hold-state-card__actions">
        <button
            type="button"
            class="ft-order-hold-details-toggle"
            x-on:click="detailsOpen = !detailsOpen"
            x-bind:aria-expanded="detailsOpen.toString()"
            data-order-hold-view-control
        >
            <span x-text="detailsOpen ? 'Hide details' : 'View details'">View details</span>
            <span class="ft-order-hold-chevron" :class="detailsOpen ? 'is-open' : ''" aria-hidden="true">⌄</span>
        </button>

        @if($canReleaseHold)
            <button
                type="button"
                class="ft-order-release-hold-button"
                wire:click="releaseOrderHold"
                wire:loading.attr="disabled"
                wire:target="releaseOrderHold"
                data-ft-feedback="off"
                data-order-hold-allowed
            >
                <span wire:loading.remove wire:target="releaseOrderHold" class="ft-order-hold-button-content">
                    <span class="ft-order-release-hold-icon" aria-hidden="true"></span>
                    <span>Unhold</span>
                </span>
                <span wire:loading wire:target="releaseOrderHold" class="ft-order-hold-button-content">
                    <span class="ft-order-hold-mini-spinner" aria-hidden="true"></span>
                    <span>Releasing...</span>
                </span>
            </button>
        @endif
    </div>

    <div class="ft-order-hold-state-card__details" x-cloak x-show="detailsOpen" x-transition.opacity.duration.120ms>
        <div><span>Hold from</span><b>{{ $holdFrom }}</b></div>
        <div><span>Name</span><b>{{ $sourceName }}</b></div>
        <div><span>Held by</span><b>{{ $heldBy }}</b></div>
        @if($startedAt)
            <div><span>Started at</span><b>{{ \App\Support\UserLocalTime::format($startedAt, 'M j, Y · g:i A') }}</b></div>
        @endif
        @if($reason !== '')
            <div class="ft-order-hold-state-card__full-reason">
                <span>Reason</span>
                <div><x-ui.mention-text :text="$reason" /></div>
            </div>
        @else
            <div class="ft-order-hold-state-card__no-reason"><span>Reason</span><b>Not provided</b></div>
        @endif
    </div>
</div>
