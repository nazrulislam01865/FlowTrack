# Create Order green theme parity — 2026-08-22

## Scope

The Create Order page now uses the same teal/green primary visual language as Order Details.

Primary action color is aligned to the Order Details `--teal` token (`#087f73`), with `#086e64` for hover and `#e7f4f2` for soft selected/focus surfaces.

## Updated UI

- Create Order numbered section markers
- Create Order primary action button
- Native input/select/textarea focus states
- Repeated-order and urgency controls
- Searchable Client/Owner/phone/supplier selectors
- Shipping saved-address action and selection states
- Product selector focus, selected results, supplier icon and product-step badges
- Create Product modal primary actions
- Create Order workflow selector and selected workflow state
- Workflow phase fallback color when no phase color is configured

## Safety

All CSS changes are scoped to `.ft-create-job-page`, so Inquiry, Master Data, Orders list and other screens retain their existing themes.

No Order creation, supplier-skip, workflow, task, validation, database or phase-progression logic was changed.

The matching CSS override is also appended to the existing prebuilt `shell-b` asset so the supplied archive renders correctly without requiring an immediate local Vite rebuild. Future Vite builds will use the source compatibility stylesheet.
