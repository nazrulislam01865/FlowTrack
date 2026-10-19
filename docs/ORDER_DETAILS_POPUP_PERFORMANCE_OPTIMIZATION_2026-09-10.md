# Order Details popup performance optimization — 2026-09-10

## Scope
This pass intentionally changes only the workflow action modal preview path. UI structure, task-pack rules, permissions, workflow transitions, invoice sending, artwork sending, validation keys, and action names remain unchanged.

## Evidence used
The archived runtime log contains Order page requests at roughly 951–1004 ms with 793–813 queries and 596–633 ms SQL time. A later Livewire update sample records 617.77 ms, 189 queries and 448.74 ms SQL time. These samples are not treated as an apples-to-apples before/after benchmark because the routes differ.

Source inspection showed four potential service/I/O calls directly in `workflow-action-modal.blade.php`: workflow email preview, invoice email preview, prepared invoice lookup, and current Artwork document fallback.

## Changes
1. Added `OrderWorkflowActionModalPreviewService` as the single backend preview-preparation boundary.
2. Added `orderWorkflowActionModalPreview` Livewire state on Order Details and Order List action hosts.
3. Initial modal open prepares metadata only (`includeHtml=false`).
4. Exact nested email body rendering is deferred with `wire:init="loadOrderWorkflowActionEmailPreview"` until the modal shell is visible.
5. Full preview regenerates only when selection-dependent fields change: `to_email`, `cc_emails`, `to_emails`, or `customer_comment`.
6. Removed workflow email preview, invoice preview, prepared invoice lookup, and DocumentService fallback calls from the modal Blade.
7. Invoice display data is supplied by the invoice preview snapshot rather than querying an Invoice model from Blade.
8. Added regression coverage for presentation-only modal rendering and deferred preview loading.

## Structural before/after benchmark
- Potential service/I/O expressions in workflow modal Blade: **4 -> 0**.
- Purchase Order / Artwork email nested message-body render on initial modal request: **1 -> 0**; exact body is loaded after modal display via `wire:init`.
- Invoice Send direct Blade preview/invoice service calls per render: **2 -> 0**.
- Unrelated modal rerenders no longer regenerate email/invoice preview data. Preview refresh is limited to email recipient/comment fields.

These are code-path measurements, not fabricated wall-clock timings.

## Validation completed in supplied environment
- `php -l` passes for all changed PHP application and test files.
- Source-level regression assertions pass for the new modal preview boundary.
- Full PHPUnit/Laravel runtime benchmark could not be executed because the uploaded archive does not contain `vendor/` and Composer is unavailable in this sandbox.

## Staging benchmark procedure
Use the same large Order for each run. Run at least 30 samples of each action:

1. Open Order Details.
2. Open Send Purchase Order popup.
3. Open Send Artwork popup.
4. Open Send Invoice popup.
5. Change a non-email field inside a modal where available.
6. Type/edit To/CC/customer-comment fields.

Capture:
- request duration
- query count
- total SQL time
- response bytes
- browser click-to-modal-visible time
- follow-up `wire:init` preview duration

Enable staging diagnostics when needed:

```env
PERFORMANCE_MONITORING_ENABLED=true
PERFORMANCE_SAMPLE_RATE=1
PERFORMANCE_LOG_ALL_REQUESTS=true
PERFORMANCE_SERVER_TIMING=true
PERFORMANCE_QUERY_FINGERPRINTS=true
```

Do not leave verbose query fingerprints enabled continuously in production.
