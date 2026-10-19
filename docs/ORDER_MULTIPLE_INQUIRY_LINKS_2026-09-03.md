# Multiple Inquiry Links per Order - 2026-09-03

This release expands the Order/Inquiry traceability model from one source Inquiry per Order to multiple linked Inquiries per Order without removing the legacy `source_inquiry_id` compatibility pointer.

### Invariants

1. An Order can link any number of eligible Inquiries.
2. An Inquiry can link to only one Order.
3. The first/oldest link is mirrored to `flow_jobs.source_inquiry_id` for legacy code.
4. `inquiries.converted_job_id` continues to point back to the linked Order.
5. Unlinking one Inquiry never unlinks the others.
6. If the primary link is removed, the oldest remaining link becomes primary automatically.
7. Inquiry documents are read in place and never copied to Order storage.
8. Existing permission checks remain authoritative.
