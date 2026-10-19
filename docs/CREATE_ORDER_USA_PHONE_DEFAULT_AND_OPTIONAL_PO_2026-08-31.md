# Create Order: USA phone default and optional Purchase Order

Date: 2026-08-31

## Changes

- Create Order delivery phone country code now defaults to `+1` (United States / Canada).
- The default is defined once on `App\Livewire\Jobs\Index::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE` and reused when the shipping contact form is initialized or reset.
- Saved/client contact country codes still take precedence when an existing contact has an explicit international code.
- A stored contact without an explicit recognized code falls back to `+1` while preserving its local phone number.
- Purchase Order remains accepted and linked to the normal `NEW_UPLOAD_PO` workflow task when uploaded, but is optional during Create Order.
- The Documents UI now labels Purchase Order as `Optional` and the create validation continues to use nullable attachment rules.

## Database

No migration is required for this change.
