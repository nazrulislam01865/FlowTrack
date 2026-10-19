# Cancelled Orders Prototype Redesign — 2026-08-25

## Goal
Implement the supplied Cancelled Orders prototype as the dedicated historical Order cancellation page without changing the existing order-cancellation workflow.

## Route and navigation
- `GET /orders/cancelled` → `orders.cancelled`
- `GET /orders/cancelled/export` → `orders.cancelled.export`
- Added `Order > Cancelled Orders` to the sidebar with a permission-scoped live count.
- The global Orders topbar label and `Export Summary` remain visible on the Cancelled Orders page, matching the prototype.

## New implementation files
- `app/Services/CancelledOrderService.php`
- `app/Livewire/Orders/CancelledOrders.php`
- `app/Http/Controllers/CancelledOrdersController.php`
- `app/Http/Controllers/CancelledOrdersExportController.php`
- `resources/views/pages/cancelled-orders.blade.php`
- `resources/views/livewire/orders/cancelled-orders.blade.php`
- `tests/Feature/CancelledOrdersPrototypeImplementationTest.php`

## Existing files updated
- `routes/web.php`
- `resources/views/layouts/partials/sidebar.blade.php`
- `resources/views/layouts/partials/topbar.blade.php`
- `resources/views/components/ui/nav-link.blade.php`
- `resources/theme/flowtrack/theme.css`
- current compiled theme CSS in `public/build/assets/theme-k2GZdzSB.css`

## Data source
The page is permission-scoped through `JobService::visibleQuery($user)` and only includes records whose Order status is `Cancelled`.

Dynamic row fields:
- Order: `flow_jobs.job_number` through `displayOrderNumber()`
- Reference: `flow_jobs.order_number`, then linked Inquiry reference as fallback
- Created date: `flow_jobs.created_at`
- Client: `flow_jobs.client_id -> clients`
- Product / quantity: active `flow_job_items`, with Order fields as fallback
- Last stage: `flow_jobs.workflow_phase_id -> workflow_phases`
- Cancellation reason: `flow_jobs.cancellation_reason`
- Cancelled by: `flow_jobs.cancelled_by -> users`
- Cancelled date: `flow_jobs.cancelled_at`, `updated_at` only for legacy fallback
- Owner: `flow_jobs.owner_id -> users`

## Prototype filters
- Search order/reference/client/product
- Client
- Last stage
- Reason
- Cancelled by
- From date
- To date
- Clear

## Prototype summary cards
- Total cancelled
- Cancelled this month
- Most common cancellation reason
- Restorable historical orders

`Restorable` is read-only reporting metadata. It counts cancelled Orders that have not been completed, were cancelled at or before QC, and do not have a non-draft/non-cancelled invoice. No restore action was added, so the existing Order workflow behavior is unchanged.

## Pagination
Exactly 6 cancelled Orders per page, matching the supplied prototype.

## Export
`Export cancelled orders` generates a single XLSX sheet from the complete filtered result. It uses chunked database iteration to avoid loading the full result set into memory.

## No workflow change
The existing cancellation command and its rules are untouched. This change is a historical list/reporting UI only.
