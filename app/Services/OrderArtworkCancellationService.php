<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\Task;
use App\Models\User;
use App\Services\Orders\OrderItemSummaryService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Cancels individual current Artwork files without cancelling the whole Order.
 *
 * Artwork files are retained in document history for auditability. Selected
 * order products are soft-removed in the same transaction owned by the caller.
 */
final class OrderArtworkCancellationService
{
    public function __construct(
        private readonly OrderWorkflowActionService $workflowActions,
        private readonly DocumentService $documents,
        private readonly OrderItemSummaryService $itemSummary,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function cancel(FlowJob $order, Task $reviewTask, User $actor, array $payload, string $reason): Activity
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($order);
        abort_unless(
            $this->workflowActions->automationKey($reviewTask) === 'ART_INTERNAL_REVIEW',
            422,
            'Artwork cancellation is only available during Internal Artwork Review.',
        );

        $uploadTask = $this->artworkUploadTask($order, $reviewTask);
        abort_unless($uploadTask, 422, 'Artwork upload task is not configured.');

        $currentArtwork = $this->documents->currentArtworkDocuments($uploadTask);
        if ($currentArtwork->isEmpty()) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cancel_artwork_document_ids' => 'No current artwork files are available to cancel.',
            ]);
        }

        $selectedDocumentIds = $this->normalizedIds($payload['cancel_artwork_document_ids'] ?? []);
        if ($selectedDocumentIds->isEmpty()) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cancel_artwork_document_ids' => 'Select at least one artwork file to cancel.',
            ]);
        }

        $currentIds = $currentArtwork->pluck('id')->map(fn ($id) => (int) $id)->values();
        if ($selectedDocumentIds->contains(fn (int $id): bool => ! $currentIds->contains($id))) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cancel_artwork_document_ids' => 'One of the selected artwork files is no longer current. Reopen the review and try again.',
            ]);
        }

        // This feature is intentionally for partial artwork cancellation. If the
        // whole current set is no longer needed, the Order cancellation workflow
        // should be used so downstream tasks are not left without artwork.
        if ($selectedDocumentIds->count() >= $currentIds->count()) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cancel_artwork_document_ids' => 'At least one current artwork must remain. Use Order cancellation when the entire artwork set is no longer required.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'orderWorkflowActionComment' => 'Enter a cancellation reason.',
            ]);
        }

        $selectedDocuments = $currentArtwork
            ->filter(fn ($document): bool => $selectedDocumentIds->contains((int) $document->id))
            ->values();

        $selectedProductIds = $this->normalizedIds($payload['cancel_product_item_ids'] ?? []);
        $selectedProducts = collect();
        if ($selectedProductIds->isNotEmpty()) {
            // Lock the complete active product set once. Selection validation and
            // the at-least-one-remains guard then operate on one consistent view.
            $activeProducts = $this->lockedActiveProducts($order);
            $selectedProducts = $activeProducts
                ->filter(fn (FlowJobItem $item): bool => $selectedProductIds->contains((int) $item->id))
                ->values();

            if ($selectedProducts->count() !== $selectedProductIds->count()) {
                throw ValidationException::withMessages([
                    'orderWorkflowActionPayload.cancel_product_item_ids' => 'One of the selected products is no longer active on this Order. Reopen the cancellation form and try again.',
                ]);
            }

            if ($selectedProducts->count() >= $activeProducts->count()) {
                throw ValidationException::withMessages([
                    'orderWorkflowActionPayload.cancel_product_item_ids' => 'At least one active Order product must remain. Use Order cancellation when every product is being removed.',
                ]);
            }
        }

        $documentNames = $selectedDocuments->pluck('name')->map(fn ($name) => (string) $name)->values();
        $productNames = $selectedProducts->pluck('product_name')->map(fn ($name) => (string) $name)->filter()->values();
        $removalReason = 'Cancelled with artwork '.($documentNames->implode(', ') ?: 'selection').': '.$reason;

        foreach ($selectedProducts as $item) {
            $item->update([
                'is_removed' => true,
                'removed_at' => now(),
                'removed_by' => $actor->id,
                'removal_reason' => $removalReason,
                'updated_by' => $actor->id,
            ]);
        }

        if ($selectedProducts->isNotEmpty()) {
            $this->itemSummary->sync($order);
        }

        $description = 'Artwork cancelled: '.$documentNames->implode(', ').'.';
        if ($productNames->isNotEmpty()) {
            $description .= ' Removed product'.($productNames->count() === 1 ? '' : 's').': '.$productNames->implode(', ').'.';
        }

        $activity = $order->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.artwork_cancelled',
            'description' => $description,
            'meta' => [
                'reason' => $reason,
                'source_task_id' => (int) $reviewTask->id,
                'target_task_id' => (int) $uploadTask->id,
                'workflow_phase_id' => (int) $reviewTask->workflow_phase_id,
                'document_ids' => $selectedDocumentIds->all(),
                'document_names' => $documentNames->all(),
                'product_item_ids' => $selectedProductIds->all(),
                'product_names' => $productNames->all(),
            ],
        ]);

        $this->notifications->notifyJobParticipants(
            $order->refresh(),
            'Artwork cancelled from Order',
            $order->displayOrderNumber().' · '.$documentNames->count().' artwork file'.($documentNames->count() === 1 ? '' : 's').' cancelled'.($productNames->isNotEmpty() ? ' · '.$productNames->count().' product'.($productNames->count() === 1 ? '' : 's').' removed' : ''),
            'update',
            $actor,
        );

        return $activity;
    }

    private function artworkUploadTask(FlowJob $order, Task $reviewTask): ?Task
    {
        $base = Task::query()
            ->where('flow_job_id', $order->id)
            ->where('workflow_phase_id', $reviewTask->workflow_phase_id)
            ->whereNotNull('task_pack_task_id');

        $task = (clone $base)
            ->whereHas('setupTemplate', fn ($query) => $query->where('automation_key', 'ART_PREPARE_UPLOAD'))
            ->with('setupTemplate')
            ->first();

        if ($task) {
            return $task;
        }

        // Compatibility for older generated tasks created before automation_key
        // was populated. These are the same title aliases used by the workflow.
        return (clone $base)
            ->whereIn('title', ['Prepare & Upload Artwork', 'Prepare and Upload Artwork'])
            ->with('setupTemplate')
            ->first();
    }

    /** @return Collection<int,int> */
    private function normalizedIds(mixed $value): Collection
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /** @return Collection<int,FlowJobItem> */
    private function lockedActiveProducts(FlowJob $order): Collection
    {
        return FlowJobItem::query()
            ->where('flow_job_id', $order->id)
            ->where('is_removed', false)
            ->whereNotNull('product_name')
            ->where('product_name', '!=', '')
            ->lockForUpdate()
            ->orderBy('sort_order')
            ->get();
    }
}
