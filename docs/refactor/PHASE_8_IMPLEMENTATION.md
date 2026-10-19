# Phase 8 implementation — Application Actions, Queries, DTOs and service decomposition

Date: 2026-08-22

## Objective

Move transport code away from the three oversized application services without rewriting proven business semantics. Phase 8 keeps every existing public `JobService`, `InquiryService` and `DashboardService` call compatible while establishing focused domain ownership for new and already-migrated Order, Inquiry and Dashboard workflows.

## Compatibility-first service split

The former implementations are frozen as temporary compatibility implementations:

- `App\Services\LegacyJobService`
- `App\Services\LegacyInquiryService`
- `App\Services\LegacyDashboardService`

The original public FQCNs remain available as thin facades:

- `App\Services\JobService`
- `App\Services\InquiryService`
- `App\Services\DashboardService`

The legacy files are exact copies of the Phase 7 implementations except for the class-name rename. This keeps old service-to-service callers working while migrated transports use the focused boundaries below. Their hashes are frozen by `quality/phase8-application.json`.

## Focused domain services

### Orders

- `OrderReadService`
- `OrderLifecycleService`
- `OrderItemService`
- `OrderWorkflowService`

### Inquiries

- `InquiryReadService`
- `InquiryLifecycleService`
- `InquiryItemService`
- `InquiryTaskService`
- `InquiryWorkflowService`
- `InquiryDocumentService`
- `InquiryActivityService`

### Dashboard

- `DashboardOverviewService`
- `DashboardMentionService`
- `DashboardAttentionService`
- `DashboardReportingService`

These services are transport-independent and intentionally delegate to the frozen implementations while capabilities are migrated incrementally. No repository layer was introduced.

## Actions and Queries

Order and Inquiry Actions/Queries created in Phases 5–7 now inject focused services instead of the giant facades. Remaining direct Order writes touched by Phase 8 were placed behind focused Actions, including urgency updates, item mutations, bulk changes, phase movement/completion and auto-advance.

Dashboard presentation now reads through:

- `DashboardPrimaryQuery`
- `DashboardSecondaryQuery`
- `DashboardMentionsQuery`

and writes mention-read state through `MarkDashboardMentionsRead`.

Dashboard Queries authorize `dashboard:view` before returning read data.

## DTO convention

DTOs are used only where an input shape is non-trivial or shared across transport boundaries:

- `DTOs/Orders/OrderCreateData`
- `DTOs/Inquiries/InquiryCreateData`
- `DTOs/Dashboard/DashboardFilterData`

DTO rules:

1. `final readonly` by default.
2. No Eloquent queries, authorization or rendering behavior.
3. Convert transport-shaped input into an application-shaped payload.
4. Do not create one-field DTOs or repository-style wrappers purely for architecture appearance.

## Transaction, audit and notification ownership

Phase 8 does not duplicate transactions, audit activity or notification dispatch. Existing proven domain service implementations remain the authoritative write boundary while the new Actions provide transport-independent entry points. As individual capabilities move out of the frozen implementations later, the transaction/audit/notification unit must move together.

## Retry/idempotency rule

No existing synchronous write was made retryable or queued during this phase. Therefore no new idempotency store/key was introduced. Existing naturally idempotent operations remain so, and any future Action enabled for automatic retry must define its idempotency key/duplicate-side-effect rule before the retry is enabled.

## Regression protection

Run:

```bash
npm run quality:phase8
```

The gate verifies:

- frozen compatibility implementation hashes;
- thin public facade budgets;
- focused domain-service/DTO/Query presence;
- no Livewire dependency inside Actions/Queries/focused services;
- no direct giant-facade invocation from transport code;
- no new repository layer.

## Rollback

A migrated capability can be rolled back to the original facade because the original implementation remains frozen behind the compatibility class. Do not edit both the focused service and the frozen implementation to implement the same rule.
