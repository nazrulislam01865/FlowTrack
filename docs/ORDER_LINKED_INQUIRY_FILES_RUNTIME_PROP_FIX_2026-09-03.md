# Order linked inquiry files runtime prop fix - 2026-09-03

## Symptom
Opening the Order Details -> Inquiry tab could return HTTP 500 with:

`Undefined variable $canViewLinkedInquiryDocuments`

The linked-inquiry file permissions were computed in `BuildsOrderPageData`, but they were not forwarded by the Livewire index view into the `jobs.detail` component. The detail component also did not declare safe defaults for those two props.

## Fix
- Forward `canViewLinkedInquiryDocuments` and `canExportLinkedInquiryDocuments` from `resources/views/livewire/jobs/index.blade.php`.
- Use `?? false` at the Livewire view boundary so non-inquiry/partial render states remain safe.
- Declare both values with `false` defaults in `resources/views/components/jobs/detail.blade.php`.
- Continue forwarding the resolved values to `jobs.detail-inquiry`.
- Extend the implementation regression test so this prop chain is checked.

No database, permission model, inquiry-linking logic, or document-storage behavior was changed.
