# Create Order phone country code searchable dropdown — 2026-09-14

- Replaced the native phone country-code `<select>` in each Create Order shipment card with the existing shared `x-ui.search-select` component.
- The selector writes directly to `createShipments.{index}.phone_country_code`, preserving the existing Livewire state, validation and persistence path.
- Phone Country Code Master Data is supplied as `{id, label, meta}` options so users can search by the international code or the configured country/label description.
- The dropdown is non-clearable because the field is required and uses a fixed/teleported menu so it is not clipped by shipment cards.
- Shipment-specific CSS only aligns the shared selector with the centralized Create Order control height, border radius, typography and focus variables.
- No shipment persistence, order workflow, shipment-stage, saved-address, or shipping-method behavior was changed.
