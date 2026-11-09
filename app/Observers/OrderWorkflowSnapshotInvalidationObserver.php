<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\FlowJobPhaseHistory;
use App\Models\SupplierTaskAssignment;
use App\Models\Task;
use App\Models\TaskLink;
use App\Services\Orders\OrderWorkflowSnapshotService;
use Illuminate\Database\Eloquent\Model;

/**
 * Only mutations that affect visible workflow phase/task-card data are tracked.
 * The invalidator uses raw indexed updates so this observer never recurses.
 * Bulk Task Pack publication is handled by existing summary invalidation hooks.
 */
final class OrderWorkflowSnapshotInvalidationObserver
{
    private const TASK_FIELDS = [
        'flow_job_id', 'workflow_phase_id', 'task_pack_task_id', 'task_number',
        'title', 'description', 'status', 'progress', 'priority', 'start_date',
        'due_date', 'completed_at', 'assignee_id', 'setup_assignee_id',
        'document_category_id', 'document_requirement_source', 'order_task_status_id',
        'order_task_flag_id', 'needs_attention', 'attention_reason', 'deleted_at',
    ];

    private const ORDER_FIELDS = [
        'workflow_id', 'workflow_phase_id', 'status', 'completed_at', 'cancelled_at',
        'started_from_phase_id', 'deleted_at',
    ];

    public function created(Model $model): void { $this->changed($model, true); }
    public function updated(Model $model): void { $this->changed($model, false); }
    public function deleted(Model $model): void { $this->changed($model, true); }
    public function restored(Model $model): void { $this->changed($model, true); }
    public function forceDeleted(Model $model): void { $this->changed($model, true); }

    private function changed(Model $model, bool $force): void
    {
        // With snapshot writes disabled, observers must add zero queries even
        // for TaskLink events that otherwise need to resolve the parent Order.
        if (! (bool) config('flowtrack.workflow_read_snapshot_writes_enabled', false)) return;

        $orderId = 0;
        if ($model instanceof Task) {
            if (! $force && ! $model->wasChanged(self::TASK_FIELDS)) return;
            $orderId = (int) $model->flow_job_id;
        } elseif ($model instanceof FlowJob) {
            if (! $force && ! $model->wasChanged(self::ORDER_FIELDS)) return;
            $orderId = (int) $model->id;
        } elseif ($model instanceof Document) {
            // Unrelated client-only documents are not part of Order workflows.
            if (! $force && ! $model->wasChanged(['task_id', 'name', 'category', 'is_final', 'version', 'deleted_at'])) return;
            $orderId = (int) $model->flow_job_id;
        } elseif ($model instanceof TaskLink) {
            // Existing TaskLink records carry only a task_id, not the Order ID.
            // One indexed scalar lookup is needed only on evidence mutations.
            if (! $force && ! $model->wasChanged(['url', 'task_id', 'deleted_at'])) return;
            $orderId = (int) (Task::query()->whereKey($model->task_id)->value('flow_job_id') ?: 0);
        } elseif ($model instanceof SupplierTaskAssignment || $model instanceof FlowJobPhaseHistory) {
            $orderId = (int) $model->flow_job_id;
        } elseif ($model instanceof Activity) {
            $event = strtolower((string) $model->event);
            if (! (str_contains($event, 'artwork_revision') || str_contains($event, 'artwork_cancelled')
                || str_contains($event, 'workflow_invoice_prepared'))) return;
            if (in_array((string) $model->subject_type, [FlowJob::class, 'flow_job'], true)) {
                $orderId = (int) $model->subject_id;
            }
        }

        $snapshots = app(OrderWorkflowSnapshotService::class);
        // Moving a Task or Document must invalidate BOTH Orders. A deleted
        // TaskLink has no Order FK; its previous Task identity is resolved once.
        if (! $force && $model instanceof Task && $model->wasChanged('flow_job_id')) {
            $snapshots->invalidateInTransaction((int) $model->getOriginal('flow_job_id'));
        } elseif (! $force && $model instanceof Document && $model->wasChanged('flow_job_id')) {
            $snapshots->invalidateInTransaction((int) $model->getOriginal('flow_job_id'));
        } elseif (! $force && $model instanceof TaskLink && $model->wasChanged('task_id')) {
            $oldTaskId = (int) $model->getOriginal('task_id');
            $oldOrderId = $oldTaskId > 0
                ? (int) (Task::query()->whereKey($oldTaskId)->value('flow_job_id') ?: 0)
                : 0;
            $snapshots->invalidateInTransaction($oldOrderId);
        }
        if ($orderId > 0) $snapshots->invalidateInTransaction($orderId);
    }
}
