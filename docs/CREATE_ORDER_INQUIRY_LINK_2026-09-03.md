# Create Order Inquiry Link - 2026-09-03

## Scope
Adds the optional **Inquiry link** field to Order basics on the Create Order page, matching the supplied prototype while supporting multiple Inquiry links per Order.

## UX
- Empty state uses the shared remote `x-ui.search-select` and the prototype text `Search by inquiry number or title`.
- Selected state keeps the link icon, `INQ-number — subject`, linked status, and **Change** action.
- **Change** now opens the picker and replaces that specific selected Inquiry.
- **+ Add another inquiry** opens the same picker in append mode.
- Selected Inquiries are shown as matching stacked cards and can be removed before Order creation.
- The first selected Inquiry is shown as the primary linked Inquiry for legacy compatibility.
- The reusable view component is `resources/views/components/jobs/create/inquiry-link.blade.php`.

## Performance
- No Inquiry list is hydrated with the Create Order page.
- Selected labels are cached in Livewire state, avoiding repeat Inquiry lookups during normal form rerenders.
- Search is server-side, permission-scoped, bounded/paginated, and returns compact fields only.
- Already-selected Inquiry ids are omitted from later searches.
- Final multi-selection validation resolves all selected Inquiries in one bounded query.
- Same-client Inquiries are ranked first but cross-client linking behavior remains consistent with Order Details.

## Integrity and permissions
- Requires Order create plus Order link and Inquiry view access.
- Closed (`dead`) or already-linked Inquiries are excluded.
- All selected Inquiry availability is rechecked immediately before persistence.
- Final linking runs inside the Order creation database transaction through the same shared attach helper used by Order Details.
- Inquiry rows are locked deterministically before attachment; a race/conflict rolls back the new Order and every Inquiry link together.
- The first Inquiry remains the legacy `source_inquiry_id`/primary link and all selected Inquiries are stored in `flow_job_inquiries`, preserving the multiple-Inquiry model.

## Database
No new migration is required beyond the existing `2026_09_03_170000_create_flow_job_inquiries_table.php` migration introduced for multiple Inquiry links.
