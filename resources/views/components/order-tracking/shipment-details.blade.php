@props([
    'shipment',
    'shipments' => [],
    'subtitle' => 'Track your shipment with the courier.',
    'showButton' => true,
])
@php
    $rows = count($shipments) > 0 ? $shipments : [$shipment];
    $multiple = count($rows) > 1;
@endphp

<section class="ft-tracking-shipment-card">
    <header>
        <span class="ft-tracking-card-heading-icon"><x-order-tracking.icon name="truck" :size="24" /></span>
        <div>
            <h3>Shipment details</h3>
            <p>{{ $subtitle }}</p>
        </div>
    </header>

    @if($multiple)
        <div class="ft-tracking-shipment-list">
            @foreach($rows as $index => $row)
                <div class="ft-tracking-shipment-entry">
                    <strong>Shipment {{ $index + 1 }}</strong>
                    <dl class="ft-tracking-shipment-meta">
                        <div>
                            <dt>Courier</dt>
                            <dd>{{ $row['courier'] ?? 'To be assigned' }}</dd>
                        </div>
                        <div>
                            <dt>Tracking number</dt>
                            <dd>{{ $row['tracking_number'] ?? '—' }}</dd>
                        </div>
                    </dl>
                    @if($showButton && !empty($row['tracking_url']))
                        <x-ui.button
                            href="{{ $row['tracking_url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="ft-tracking-shipment-button"
                        >
                            <x-order-tracking.icon name="external" :size="17" />
                            Track shipment
                        </x-ui.button>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        @php($row = $rows[0] ?? $shipment)
        <dl class="ft-tracking-shipment-meta">
            <div>
                <dt>Courier</dt>
                <dd>{{ $row['courier'] ?? 'To be assigned' }}</dd>
            </div>
            <div>
                <dt>Tracking number</dt>
                <dd>{{ $row['tracking_number'] ?? '—' }}</dd>
            </div>
        </dl>

        @if($showButton && !empty($row['tracking_url']))
            <x-ui.button
                href="{{ $row['tracking_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                class="ft-tracking-shipment-button"
            >
                <x-order-tracking.icon name="external" :size="17" />
                Track shipment
            </x-ui.button>
        @endif
    @endif
</section>
