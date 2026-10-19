# Create Order — Multiple Inquiry Links (2026-09-03)

## Scope

The optional **Inquiry link** field on Create Order now supports selecting multiple eligible Inquiries before the Order is created.

## UX

- The first selection keeps the existing linked-Inquiry card design.
- Additional selections are shown as matching stacked cards.
- **Change** now reliably opens the remote Inquiry picker.
- **+ Add another inquiry** opens the same picker without removing current selections.
- Every selected Inquiry can be removed before saving.
- The first selected Inquiry is identified as the primary linked Inquiry for legacy compatibility.
- Already-selected Inquiry ids are omitted from subsequent remote search results.

## Data and integrity

- Livewire stores an ordered list of selected Inquiry ids plus small cached display rows.
- The remote selector remains bounded; the Inquiry catalogue is not loaded with the Create Order page.
- Up to 100 Inquiry links can be selected in one Create Order payload.
- Immediately before creation, all selected Inquiries are revalidated against the normal link eligibility scope.
- Order creation and all Inquiry attachments run in one database transaction.
- Inquiry rows are locked in deterministic id order to reduce deadlock risk, then attached in user-selection order.
- If any Inquiry became unavailable, the entire Order creation rolls back; partial linking is not committed.
- The first selected Inquiry remains `source_inquiry_id`; the complete set is stored through `flow_job_inquiries`.
- No schema change is required beyond the existing multiple-Inquiry-link migration.

## Performance

- Selected display text is cached in Livewire state, avoiding repeat Inquiry queries during ordinary Create Order rerenders.
- Final multi-selection validation resolves the selected Inquiry set in one bounded query instead of one query per Inquiry.
- Remote search excludes already-selected ids server-side and remains paginated.
