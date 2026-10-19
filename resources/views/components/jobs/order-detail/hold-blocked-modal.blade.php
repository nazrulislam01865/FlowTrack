@props([
    'hold' => null,
    'canReleaseHold' => false,
    'orderId' => null,
    'directRelease' => true,
])
@php
    $hold = is_array($hold) ? $hold : [];
    $holdFrom = trim((string) ($hold['holdFromLabel'] ?? '')) ?: '—';
    $heldBy = trim((string) ($hold['heldBy'] ?? '')) ?: 'Unknown user';
    $reason = trim((string) ($hold['reason'] ?? ''));
    $startedAt = $hold['startedAt'] ?? null;
@endphp
<div
    class="ft-order-hold-blocked-backdrop"
    x-cloak
    x-show="holdBlockedOpen"
    x-transition.opacity.duration.140ms
    x-on:keydown.escape.window="closeHoldBlocked()"
    x-on:click.self="closeHoldBlocked()"
    role="presentation"
    data-order-hold-allowed
>
    <section
        class="ft-order-hold-blocked-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="order-hold-blocked-title"
        x-show="holdBlockedOpen"
        x-transition.scale.origin.center.duration.140ms
    >
        <button
            x-ref="orderHoldBlockedClose"
            type="button"
            class="ft-order-hold-blocked-close"
            x-on:click="closeHoldBlocked()"
            aria-label="Close"
            data-order-hold-allowed
        >×</button>

        <div class="ft-order-hold-blocked-icon" aria-hidden="true">
            <span class="ft-order-hold-pause-icon"><i></i><i></i></span>
        </div>

        <div class="ft-order-hold-blocked-copy">
            <span class="ft-order-hold-blocked-eyebrow">ORDER ON HOLD</span>
            <h2 id="order-hold-blocked-title">This activity is currently locked</h2>
            <p>
                To perform any activity on this order, unhold the order first.
                <span x-show="blockedAction" x-cloak> You tried to <b x-text="blockedAction"></b>.</span>
            </p>
        </div>

        <div class="ft-order-hold-blocked-summary">
            <div><span>Hold from</span><strong>{{ $holdFrom }}</strong></div>
            <div><span>Held by</span><strong>{{ $heldBy }}</strong></div>
            @if($startedAt)
                <div><span>Since</span><strong>{{ \App\Support\UserLocalTime::format($startedAt, 'M j, Y · g:i A') }}</strong></div>
            @endif
        </div>

        @if($reason !== '')
            <div class="ft-order-hold-blocked-reason">
                <span>Reason</span>
                <div><x-ui.mention-text :text="$reason" /></div>
            </div>
        @endif

        <div class="ft-order-hold-blocked-actions">
            <button type="button" class="secondary" x-on:click="closeHoldBlocked()" data-order-hold-allowed>Close</button>
            @if($canReleaseHold && $directRelease)
                <button
                    type="button"
                    class="ft-order-hold-blocked-release"
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
            @elseif($orderId)
                <button
                    type="button"
                    class="ft-order-hold-blocked-release"
                    wire:click="openJob({{ (int) $orderId }})"
                    data-order-hold-allowed
                >
                    <span>Open order to release</span>
                </button>
            @else
                <div class="ft-order-hold-blocked-permission">Ask an authorized user to release this hold.</div>
            @endif
        </div>
    </section>
</div>
