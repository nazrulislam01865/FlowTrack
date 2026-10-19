# Phase 6 implementation — Inquiries decomposition

Date: 2026-08-22
Source: Phase 5 refactored FlowTrack archive

## Scope

Phase 6 decomposes the Inquiry UI/Livewire boundary without changing Inquiry business semantics, route/deep-link behavior, database schema, task sequencing, status mapping, attention flags, document behavior, conversion/final-decision behavior, audit activity, notifications, or the existing `InquiryService` implementation.

The implementation uses the same compatibility-first strategy as Phase 5. `App\Livewire\Inquiries\Index` remains the public Livewire state/route coordinator while focused concerns own presentation orchestration and new Actions/Queries form the application boundary in front of the proven service.

## Preserved compatibility contract

The following are deliberately preserved:

- Existing Inquiry route/page behavior and query parameters `create`, `open`, `task`, and `metric`.
- All 149 pre-Phase-6 methods and all original method signatures in `App\Livewire\Inquiries\Index`.
- Existing Livewire property names and Blade `wire:*` bindings.
- Existing Inquiry-specific status, result, attention and final-decision storage.
- Existing conversion-to-Order behavior and Order workflow selection rules.
- Existing task authorization, task sequencing, submission-evidence and completion behavior.
- Existing document upload/link/delete permission rules.
- Existing `InquiryService`, `AccessControlService`, route file and Inquiry page wrapper.
- Existing database migrations; Phase 6 introduces no schema change.

## Livewire structure

`app/Livewire/Inquiries/Index.php` is reduced from 3,067 lines to 242 lines and now acts as the compatibility coordinator/state owner.

Focused responsibilities are composed from `app/Livewire/Inquiries/Concerns`:

- `ManagesInquiryList`
- `ManagesInquiryCreation`
- `ManagesInquiryCreateProducts`
- `ManagesInquiryProducts`
- `ManagesInquiryDetail`
- `ManagesInquiryTasks`
- `ManagesInquiryDocuments`
- `ManagesInquiryActivity`
- `ManagesInquiryWorkflow`
- `ManagesInquiryFinalDecision`
- `BuildsInquiryPageData`

No child Livewire component was forced where state remains tightly shared. This avoids state synchronization or modal/deep-link regressions while still establishing clear workflow ownership.

## Inquiry Actions

Thirty-four transport-independent Actions were introduced under `app/Actions/Inquiries` for the user-initiated Inquiry writes touched by the current screen. They cover:

- Inquiry create/delete and detail/status/attention updates.
- Inquiry product add/update/remove/replace operations.
- Task status, due date, assignee, completion, attention and taskflow writes.
- Document upload/link/delete operations and task links.
- Inquiry/task comments.
- Workflow save/append operations.
- Inquiry-screen quick client/contact and catalog product/category writes.
- Conversion to Order and mark-dead final decisions.

Actions do not depend on Livewire. They delegate to the unchanged `InquiryService`, so the existing authorization, transaction, audit, notification and status-sync semantics remain canonical instead of being duplicated.

## Inquiry Queries

Three read boundaries were introduced under `app/Queries/Inquiries`:

- `InquiryListQuery` — permission-scoped list, metrics and list-row reads.
- `InquiryDetailQuery` — permission-scoped Inquiry/task/detail/document/activity reads and edit capability checks.
- `InquiryWorkflowQuery` — workflow/task-pack rows, status semantics, default statuses and submission evidence reads.

The Queries intentionally reuse the proven `InquiryService` scopes during Phase 6. Broader `InquiryService` decomposition remains Phase 8 work.

## Blade decomposition

`resources/views/livewire/inquiries/index.blade.php` was split into inherited-state sections:

- `sections/list.blade.php`
- `sections/create.blade.php`
- `sections/detail.blade.php`

The parent view is now 62 lines. The Phase 6 quality gate reconstructs the parent with these sections and protects the approved reconstructed SHA-256.

`_taskflow.blade.php` was adjusted only to consume data prepared by the Query/page-data boundary instead of resolving `InquiryService` in the view. `_attachments.blade.php` and `_activity.blade.php` remain presentation primitives. Inquiry and Order persistence semantics were not merged.

## Service coupling result

Before Phase 6, Inquiry Livewire and view code directly resolved/called `InquiryService` throughout the coordinator.

After Phase 6:

- direct `InquiryService` references in Inquiry Livewire: **0**
- direct `InquiryService` references in Inquiry Blade/components: **0**
- `InquiryService.php`: unchanged and protected by hash

This is intentionally an adapter stage. Phase 8 may split the service itself after wider application-boundary coverage exists.

## Permission coverage

`tests/Feature/Phase6InquiryPermissionBoundaryTest.php` adds explicit allowed/denied contracts for:

- Inquiry task editing/assignment: creator allowed; unrelated active user denied.
- conversion capability (`jobs.create`): administrator allowed; ordinary unprivileged user denied.
- document create/link/delete capabilities: administrator allowed; ordinary unprivileged user denied.
- conversion/document Actions are verified to terminate in the existing `InquiryService` methods where record/task authorization remains enforced.

## Quality gate

Run:

```bash
npm run quality:phase6
```

This executes the complete Phase 0–5 chain and then Phase 6 checks.

Phase 6 verifies:

- `Inquiries/Index.php` remains at or below 300 lines.
- all 11 required concerns exist and are composed.
- all 149 original method signatures remain present.
- all 34 required Actions and 3 required Queries exist and remain Livewire-independent.
- Inquiry Livewire/Blade has no direct `InquiryService` reference.
- `InquiryService`, `AccessControlService`, routes and Inquiry page wrapper remain unchanged.
- the database migration tree remains unchanged.
- `create`, `open`, `task`, and `metric` deep-link contracts remain present.
- split Inquiry view markup remains protected through exact reconstruction.
- permission tests contain both allowed and denied actor cases.

## Validation performed

- `npm run quality:phase6`: PASS.
- Phase 0 architecture budget: PASS with previously documented inherited exceptions only.
- Phase 1 CSS foundation: PASS.
- Phase 2 UI component library: PASS.
- Phase 3 CSS migration: PASS.
- Phase 4 shared forms/filter/search: PASS.
- Phase 5 Orders decomposition: PASS.
- Phase 6 Inquiries decomposition: PASS.
- 759 PHP files in the supplied source tree pass `php -l`.
- all 149 original Inquiry method signatures are unchanged.
- `InquiryService`, `AccessControlService`, routes and migrations are protected unchanged by the Phase 6 gate.

The supplied archive contains neither `vendor/` nor `node_modules/`. Therefore the full PHPUnit runtime suite, Pint and production Vite build cannot be executed from this standalone archive. They remain mandatory release checks in the normal dependency-complete environment.

## CSS note

Phase 6 does not perform CSS cleanup. `resources/css/flowtrack.css` itself is 7,354 lines in this snapshot. The much larger number seen when inspecting FlowTrack CSS comes from the coexistence of the frozen canonical stylesheet, legacy compatibility modules and generated four-chunk compatibility files. See the Phase 1/3 migration documentation; deleting these during Phase 6 would mix visual migration with Inquiry structural refactoring and increase regression risk.

## Explicitly not part of Phase 6

- `InquiryService` internal decomposition (Phase 8).
- Master Data/Client/setup decomposition (Phase 7).
- mass-assignment/tenancy/security hardening (Phase 9).
- upload storage/security redesign (Phase 10).
- general query/index optimization (Phase 11).
- CSS legacy deletion or visual redesign.
