# Create Order: persist every completed shipping-contact draft

Date: 2026-08-31

## Behavior

The Create Order shipping-contact tabs keep independent drafts for End customer,
Middle client, and Other contact. Order creation now persists every completed
contact draft, not only the tab that is active at submit time.

- End customer and Other contact are stored in `client_delivery_contacts` and
  remain separated by `contact_type`.
- New Middle client contacts are stored in `client_contacts` for the selected
  Order client.
- Selecting an existing contact never creates a duplicate; the existing save
  actions update/reuse records by client/name.
- A tab is persisted only when both contact name and phone are present. Visiting
  a tab and leaving it empty or partially filled does not create a database row.
- The currently active tab is copied into `shippingContactDrafts` immediately
  before persistence so the latest unswitched values are included.

The Order itself still has one selected delivery contact (`shipping_contact_type`
and `shipping_contact_name`). Persisting the other completed tab drafts only
makes those people reusable in future Orders.
