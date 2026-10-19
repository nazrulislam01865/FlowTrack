# Create Order shipping contact tab draft preservation — 2026-08-31

## Problem
Switching between End customer, Middle client, and Other contact reset the active contact fields. This caused user-entered End customer and Other contact names, country codes, and phone numbers to disappear before the order was submitted.

## Fix
Create Order now keeps an isolated in-memory draft for each delivery-contact source while the form is open. Before a source switch, the current contact values are captured. When the user returns to that source, its previous name, selected saved-contact reference, country code, phone number, and save-contact preference are restored.

The drafts are cleared when the Create Order form is reset or the selected client changes, so contact data cannot leak between clients or between separate order drafts.

## Persistence
This change does not alter database persistence. Only the contact source selected when the order is submitted is persisted using the existing contact save workflow.
