# Create Order end-customer save-contact visibility fix — 2026-08-31

## Problem
The save-contact control could disappear or retain stale DOM state when switching between delivery contact sources, even though the Blade condition included both `end_customer` and `other_contact`.

## Fix
- Render one save-contact row for all three delivery-contact sources.
- Key that row by the active contact type so Livewire replaces the correct DOM when switching tabs.
- Give each checkbox a contact-type-specific id/label pair.
- Keep new End customer / Other contact entries visibly checked and non-editable because new manually entered contacts are automatically persisted by the existing backend workflow.
- Preserve the normal opt-in checkbox for already-saved manual contacts and the existing middle-client behavior.

No database or compiled-asset change is required.
