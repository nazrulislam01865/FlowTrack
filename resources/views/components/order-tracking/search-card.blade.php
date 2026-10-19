@props([
    'lookupType' => 'order',
    'identifier' => 'FO-337118',
    'email' => '',
    'lookupError' => null,
])

<section class="ft-tracking-search-card" aria-labelledby="tracking-search-title">
    <div class="ft-tracking-search-header">
        <h2 id="tracking-search-title">Track your order</h2>
        <p>Check the latest progress of your order.</p>
    </div>

    <form method="POST" action="{{ route('order.track.lookup') }}" class="ft-tracking-form" data-order-tracking-form>
        @csrf
        <input type="hidden" name="lookup_type" value="{{ $lookupType === 'reference' ? 'reference' : 'order' }}" data-tracking-lookup-type>

        <div class="ft-tracking-tabs" role="tablist" aria-label="Tracking lookup type">
            <button type="button" role="tab" data-tracking-tab="order" aria-selected="{{ $lookupType !== 'reference' ? 'true' : 'false' }}" class="{{ $lookupType !== 'reference' ? 'is-active' : '' }}">Order number</button>
            <button type="button" role="tab" data-tracking-tab="reference" aria-selected="{{ $lookupType === 'reference' ? 'true' : 'false' }}" class="{{ $lookupType === 'reference' ? 'is-active' : '' }}">Reference number</button>
        </div>

        <div class="ft-tracking-field">
            <label for="trackingIdentifier" data-tracking-identifier-label>{{ $lookupType === 'reference' ? 'Reference number' : 'Order number' }}</label>
            <input
                id="trackingIdentifier"
                name="identifier"
                type="text"
                value="{{ old('identifier', $identifier) }}"
                placeholder="{{ $lookupType === 'reference' ? 'Reference number' : 'Order number' }}"
                maxlength="120"
                autocomplete="off"
                required
                data-tracking-identifier
            >
            @error('identifier')<div class="ft-tracking-error" role="alert">{{ $message }}</div>@enderror
        </div>

        <div class="ft-tracking-field">
            <label for="trackingEmail">Email address</label>
            <input
                id="trackingEmail"
                name="email"
                type="email"
                value="{{ old('email', $email) }}"
                placeholder="Email used for this order"
                maxlength="255"
                autocomplete="email"
                required
            >
            @error('email')<div class="ft-tracking-error" role="alert">{{ $message }}</div>@enderror
        </div>

        @if($lookupError)
            <div class="ft-tracking-error ft-tracking-error--lookup" role="alert">{{ $lookupError }}</div>
        @endif

        <x-ui.button type="submit" class="ft-tracking-submit">Track order</x-ui.button>

        <p class="ft-tracking-helper">
            <x-order-tracking.icon name="info" :size="18" />
            <span>Enter the email address linked to your order.</span>
        </p>
    </form>

    <div class="ft-tracking-qr-card">
        <div class="ft-tracking-qr-copy">
            <h3>Have a QR code?</h3>
            <p>Scan the QR code on your order confirmation<br>to open tracking directly.</p>
        </div>
        <div class="ft-tracking-qr-illustration" aria-hidden="true">
            <div class="ft-tracking-document-shape">
                <span></span><span></span><span></span>
            </div>
            <div class="ft-tracking-qr-shape">
                <i></i><i></i><i></i><i></i><i></i>
            </div>
        </div>
    </div>
</section>
