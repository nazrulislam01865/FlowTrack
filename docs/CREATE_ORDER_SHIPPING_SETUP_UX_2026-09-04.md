# Create Order Shipping Setup UX - 2026-09-04

## Scope

Improves only the Create Order > Shipping setup section while preserving the existing shipment state model, persistence flow, shipping-method picker, master-data-backed Country/State selectors, and saved-address modal.

## UX changes

- Shipping-mode choices now include one-line explanations so users understand the difference before selecting a mode.
- Same-address mode keeps Shipment 1 as the single editable delivery address. Shipment 2+ show a compact "Same delivery address as Shipment 1" summary instead of repeating disabled address/contact controls.
- Editable address fields follow Country -> State -> City -> Postal code -> Street address.
- Saved addresses are directly accessible from the delivery-address area rather than being hidden in the row action menu.
- Package/reference is a normal labeled optional input instead of relying on placeholder text as the label.
- Empty/no-op action menus are removed; the row action menu is only shown when a shipment can actually be removed.
- The shipping summary no longer counts the default Country alone as a completed delivery address.
- Add-shipment helper text explains what the selected address mode will do with the newly created shipment.

## Architecture

The existing reusable Create Order shipment components remain the boundary:

- `resources/views/components/jobs/create/shipping-setup.blade.php`
- `resources/views/components/jobs/create/shipping-row.blade.php`
- `resources/views/components/jobs/create/shipping-method-picker.blade.php`
- `resources/css/modules/orders/create-shipping-setup.css`

No order persistence schema or shipment service behavior was changed.
