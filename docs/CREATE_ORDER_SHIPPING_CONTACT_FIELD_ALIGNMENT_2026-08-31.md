# Create Order shipping contact field alignment — 2026-08-31

The delivery-contact row must keep Contact person, Country code, and Phone number on one shared baseline.

## Implementation

- `resources/views/components/jobs/create/shipping-contact.blade.php` renders all three visible field labels as direct children of `.ft-create-field`.
- The shared `x-ui.search-select` used for the country code keeps its semantic label for accessibility but receives `:hide-label="true"`, preventing a second nested label from creating a different vertical rhythm.
- The existing central form tokens and shared search-select component remain unchanged.

This avoids page-specific pixel offsets and keeps the country-code control aligned with the native contact and phone inputs at desktop and mobile breakpoints.
