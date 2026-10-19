# Create Order shipping contact prototype - 2026-08-31

## Scope
The Create Order Shipping address section keeps the existing address textarea and saved-address picker, but replaces the old two-column Phone Number / Postal Code row with the approved delivery-contact prototype.

## Reusable UI
`resources/views/components/jobs/create/shipping-contact.blade.php` owns the delivery-contact UI. The parent Create Order view only passes selected-client and Livewire state into this component.

The component supports three contact sources:
- **End customer** - uses the selected Client's primary profile contact and phone when available.
- **Middle client** - uses the selected Client's saved `client_contacts` records and lets the user switch contacts.
- **Other contact** - accepts an independent contact name and delivery phone for this Order.

The middle-client option can save an edited phone number back to the selected saved contact when the Order is successfully created.

## Persistence
No database migration is required. The final delivery phone continues to persist through the existing `flow_jobs.shipping_phone_country_code` and `flow_jobs.shipping_phone` columns. Postal code continues to use `shipping_postal_code`.

## Typography and sizing
All new typography and control sizes reference the shared Create Form tokens (`--ft-form-*`). This keeps the prototype centrally controlled with the rest of Create Order rather than introducing a separate font scale.

## Data loading
The Create Order page eager-loads only the selected Client's contacts, avoiding an N+1 query and keeping the existing progressive-loading strategy intact.
