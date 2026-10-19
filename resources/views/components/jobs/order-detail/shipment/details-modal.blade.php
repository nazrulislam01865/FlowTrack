@props(['shipment'])

<div class="ft-ms-modal-backdrop" role="presentation" wire:key="shipment-details-modal-{{ $shipment['id'] }}">
    <section class="ft-ms-modal ft-ms-modal--details" role="dialog" aria-modal="true" aria-labelledby="shipment-details-title" x-data x-on:keydown.escape.window="$wire.closeOrderShipmentDetails()">
        <header class="ft-ms-modal__head">
            <div>
                <h2 id="shipment-details-title">Shipment {{ $shipment['sequence'] }} details</h2>
                <p>{{ $shipment['is_primary'] ? 'Primary shipment' : 'Shipment details and dispatch status.' }}</p>
            </div>
            <button type="button" class="ft-ms-modal__close" wire:click="closeOrderShipmentDetails" aria-label="Close">×</button>
        </header>
        <div class="ft-ms-modal__body">
            <dl class="ft-ms-details-grid">
                <div><dt>Recipient</dt><dd>{{ $shipment['recipient'] ?: '—' }}</dd></div>
                <div><dt>Phone</dt><dd>{{ $shipment['phone'] ?: '—' }}</dd></div>
                <div class="is-wide"><dt>Address</dt><dd>{{ $shipment['address'] ?: '—' }}</dd></div>
                <div><dt>City</dt><dd>{{ $shipment['city'] ?: '—' }}</dd></div>
                <div><dt>State</dt><dd>{{ $shipment['state'] ?: '—' }}</dd></div>
                <div><dt>Postal Code</dt><dd>{{ $shipment['postal_code'] ?: '—' }}</dd></div>
                <div><dt>Country</dt><dd>{{ $shipment['country'] ?: '—' }}</dd></div>
                <div><dt>Shipping Method</dt><dd>{{ data_get($shipment, 'method_card.title', '—') }}</dd></div>
                <div><dt>Courier</dt><dd>{{ $shipment['courier_name'] ?: '—' }}</dd></div>
                <div><dt>Tracking Number</dt><dd>{{ $shipment['tracking_number'] ?: '—' }}</dd></div>
                <div><dt>Status</dt><dd>{{ $shipment['dispatched'] ? 'Dispatched' : 'Pending' }}</dd></div>
                <div><dt>Dispatched On</dt><dd>{{ $shipment['dispatched_on'] }}</dd></div>
                <div><dt>Quantity</dt><dd>{{ $shipment['quantity'] ?? '—' }}</dd></div>
                <div><dt>Package / Reference</dt><dd>{{ $shipment['package_reference'] ?: '—' }}</dd></div>
            </dl>
        </div>
        <footer class="ft-ms-modal__footer">
            <button type="button" class="ft-ms-primary-btn" wire:click="closeOrderShipmentDetails">Close</button>
        </footer>
    </section>
</div>
