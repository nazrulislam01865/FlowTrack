# Create Order shipping contact alignment and saved contacts — 2026-08-31

## Problem

The redesigned Shipping Address contact row had an inconsistent Postal Code alignment, and the delivery-contact source semantics were not correct: End customer reused the selected client's legacy contact instead of remaining user-entered. End customer and Other contact also had no reusable save-contact path.

## Change

- Kept the three-source prototype and reusable `jobs.create.shipping-contact` component.
- Aligned Contact person, Country code and Phone number on one grid and moved Postal Code to the same label-above-control pattern used by the rest of Create Order.
- End customer and Other contact are user-entered sources.
- Middle client is the selected Order client and loads its structured `client_contacts` records.
- Added `client_delivery_contacts` for saved End customer / Other contact names and phone numbers, scoped to the selected client.
- Previously saved manual contacts are offered again through the Contact person field without replacing the prototype layout.
- Kept the Middle client option to save an edited number back to the selected client contact profile.
- Persisted `shipping_contact_type` and `shipping_contact_name` on the Order so the selected delivery contact is not lost after creation.
- Used the existing central Create Order typography/control tokens and updated the production CSS manifest hash.

## Database

Run `php artisan migrate --force` during deployment. The migration is additive and preserves existing Orders and client contacts.
