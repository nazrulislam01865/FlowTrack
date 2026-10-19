# Create Order Shipping phone validation and responsive fix — 2026-09-04

## Scope
Create Order > Shipping setup only.

## Changes
- Moved the phone validation message inside the phone-number control so it renders directly below the phone input instead of below the country-code selector.
- Kept country-code validation scoped to the country-code control.
- Added component container queries so shipment cards adapt to their actual available width, including layouts narrowed by the application sidebar.
- At narrower card widths, the service row reduces to two columns and then one column, with Shipping method spanning safely without horizontal overflow.
- Country/State/City/Postal layout reduces from four columns to two and then one column.
- Shipping method trigger and menu now respect the card width and allow long method names to wrap safely.
- On very narrow cards, saved-address and add-shipment controls expand for easier touch use.
- Existing shipment modes, Livewire bindings, persistence, saved addresses, and shipping-method selection behavior were not changed.

## Files
- `resources/views/components/jobs/create/shipping-row.blade.php`
- `resources/css/modules/orders/create-shipping-setup.css`
- `public/build/assets/after-dashboard-af3dca62.css`
- `tests/Feature/CreateOrderShippingSetupUxTest.php`
