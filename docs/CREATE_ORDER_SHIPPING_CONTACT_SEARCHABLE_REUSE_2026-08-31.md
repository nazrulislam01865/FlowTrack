# Create Order shipping contacts: searchable reuse

## Behavior

The Create Order shipping-contact field now uses one reusable searchable combobox for all three contact sources.

- **End customer**: saved in `client_delivery_contacts` with `contact_type = end_customer` and the selected Order `client_id`.
- **Middle client**: loaded from and saved to `client_contacts` for the selected Order client.
- **Other contact**: saved in `client_delivery_contacts` with `contact_type = other_contact` and the selected Order `client_id`.

Clicking or focusing the Contact person field shows all saved contacts for the active source. Typing filters by contact name, job title, or phone metadata in the already-scoped list. Selecting an existing contact hydrates the country code and phone number. A name that is not already saved becomes a custom contact and is automatically marked to be saved when the Order is created.

The manual delivery-contact table is intentionally scoped by `client_id`, so contacts saved for one Order client do not leak into another client's Create Order form.
