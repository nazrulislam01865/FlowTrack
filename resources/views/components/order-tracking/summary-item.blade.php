@props(['title', 'data', 'icon'])
<div class="ft-tracking-summary-item is-{{ $data['state'] }}">
    <span class="ft-tracking-summary-icon"><x-order-tracking.icon :name="$icon" :size="24" /></span>
    <span>
        <strong>{{ $title }}</strong>
        <small>{{ $data['label'] }}</small>
    </span>
</div>
