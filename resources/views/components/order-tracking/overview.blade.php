@props(['status', 'summary'])

<div class="ft-tracking-overview is-{{ $status['tone'] ?? 'success' }}">
    <div class="ft-tracking-banner">
        <span class="ft-tracking-banner-icon">
            <x-order-tracking.icon :name="$status['icon']" :size="28" />
        </span>
        <div class="ft-tracking-banner-copy">
            <strong>{{ $status['title'] }}</strong>
            <p>{{ $status['message'] }}</p>
        </div>
    </div>

    <div class="ft-tracking-summary">
        <x-order-tracking.summary-item title="Delivery" :data="$summary['delivery']" icon="truck" />
        <x-order-tracking.summary-item title="Billing" :data="$summary['billing']" icon="billing" />
        <x-order-tracking.summary-item title="Payment" :data="$summary['payment']" icon="payment" />
    </div>
</div>
