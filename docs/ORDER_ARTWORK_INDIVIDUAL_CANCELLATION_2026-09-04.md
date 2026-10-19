# Order Artwork Individual Cancellation — 2026-09-04

## Goal

Allow an authorized user working on **Internal Artwork Review** to cancel one or more current artwork files without cancelling the whole Order. After artwork is selected, the user may optionally select active Order product lines to remove with that artwork.

Existing artwork revision, approval, handoff, and workflow-stage progression remain unchanged.

## User flow

1. Open **Review Artwork** in the Artwork stage.
2. Choose **Cancel Artwork**.
3. Select one or more current artwork files.
4. Optionally select the Order product line(s) that should be removed with the artwork.
5. Enter a required cancellation reason.
6. Choose **Cancel Selected Artwork**.

The Internal Artwork Review task remains active after this action so the remaining artwork can continue through the existing review/approval flow.

## Data behavior

Artwork files are **not physically deleted**. Cancellation is represented by the existing `activities` audit stream with event:

- `job.artwork_cancelled`

The activity metadata stores the review task, artwork upload task, selected document IDs/names, selected product item IDs/names, and cancellation reason.

`DocumentService::currentArtworkDocuments()` excludes cancelled document IDs from the active artwork set. Because the underlying `documents` rows remain intact, the cancelled files can still be opened/downloaded from artwork history.

Selected products use the existing soft-removal fields on `flow_job_items`:

- `is_removed`
- `removed_at`
- `removed_by`
- `removal_reason`
- `updated_by`

The legacy order-level product/category/quantity summary is synchronized through the reusable `OrderItemSummaryService` after product removal.

## Guardrails

- Cancellation is accepted only from the current `ART_INTERNAL_REVIEW` workflow task.
- Normal task access/claim rules are enforced by the existing workflow action engine before cancellation executes.
- Submitted artwork IDs must still belong to the current artwork set at execution time.
- Submitted product IDs must still be active on the same Order.
- A cancellation reason is required.
- Partial artwork cancellation must leave at least one current artwork file.
- Product removal through this action must leave at least one active Order product.
- Cancelling the complete artwork/product set should use the existing whole-Order cancellation workflow instead.
- The operation runs inside the workflow action transaction and selected product rows are locked before mutation.

## Presentation

Cancelled artwork is shown in **Archived Artwork** with a `Cancelled` status, cancellation reason, and any products removed with it. Existing revision archive behavior is preserved.

The Artwork Review modal's existing **Previous Versions** list also remains inclusive of all historical artwork (including normal full re-uploads); cancellation only changes the relevant file's status label.

## Main implementation points

- `app/Services/OrderArtworkCancellationService.php`
- `app/Services/Orders/OrderItemSummaryService.php`
- `app/Services/OrderWorkflowActionService.php`
- `app/Services/DocumentService.php`
- `app/Services/LegacyJobService.php`
- `app/Support/OrderDetailPresenter.php`
- `app/Livewire/Jobs/Concerns/ManagesOrderWorkflow.php`
- `resources/views/components/jobs/order-detail/workflow-action-modal.blade.php`
- `resources/views/components/jobs/order-detail/archived-artwork.blade.php`
- `resources/css/modules/orders/detail/artwork-cancellation.css`
- `resources/css/modules/orders/detail-prototype.css`
- `resources/css/modules/orders/detail/archived-artwork.css`
- `tests/Feature/OrderArtworkCancellationTest.php`

## Verification

A feature contract test covers the workflow wiring, artwork/product selectors, event-based current-artwork filtering, and archived cancellation presentation. The project archive supplied for this change does not include Composer `vendor/`, so the Laravel PHPUnit suite requires dependency installation in the deployment/development environment before it can execute.
