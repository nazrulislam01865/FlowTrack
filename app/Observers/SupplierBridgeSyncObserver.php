<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Services\SupplierBridge\MarkCatalogDirty;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Queue changed supplier-visible records after the database transaction commits.
 * Never call the remote API while an artwork approval or product edit is saving.
 */
final class SupplierBridgeSyncObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $record): void { $this->changed($record, true); }
    public function updated(Model $record): void { $this->changed($record, false); }
    public function deleted(Model $record): void { $this->changed($record, true); }
    public function restored(Model $record): void { $this->changed($record, true); }

    private function changed(Model $record, bool $force): void
    {
        if (! config('supplier_bridge.enabled')) return;

        if ($record instanceof MasterRecord) {
            if ($record->type === 'supplier') {
                if ($force || $record->wasChanged(['name', 'status', 'metadata'])) {
                    app(MarkCatalogDirty::class)->supplierIds((int) $record->workspace_id, [(int) $record->id]);
                }
                return;
            }
            if ($record->type !== 'product' || (! $force && ! $record->wasChanged(['name', 'code', 'status', 'metadata', 'deleted_at']))) return;
            $ids = DB::table('product_supplier_links')->where('workspace_id', (int) $record->workspace_id)
                ->where('product_id', (int) $record->id)->pluck('supplier_id')->all();
            if ($ids !== []) app(MarkCatalogDirty::class)->supplierIds((int) $record->workspace_id, $ids);
            return;
        }

        if ($record instanceof Task) {
            if (! $force && ! $record->wasChanged(['status', 'progress', 'completed_at', 'deleted_at', 'assignee_id', 'due_date', 'title', 'workflow_phase_id'])) return;
            $this->queueOrder((int) $record->flow_job_id);
            return;
        }
        if ($record instanceof Document) {
            // Artwork uploads and cancellations become available only after the
            // projection's confirmation checks, even when a document changes first.
            if (! $force && ! $record->wasChanged(['path', 'name', 'size', 'is_final', 'version', 'task_id', 'deleted_at'])) return;
            $this->queueOrder((int) $record->flow_job_id);
            return;
        }
        if ($record instanceof FlowJobItem) {
            // Re-resolve only this Order's Task Pack supplier defaults when its
            // product/supplier changes; the existing delivery queue handles sync.
            $order = FlowJob::query()->find($record->flow_job_id);
            if ($order) {
                app(\App\Services\SupplierBridge\SupplierDefaultTaskAssigner::class)->syncForOrder($order);
                // A newly linked supplier needs its own outbox row. Updating
                // only pre-existing rows previously left its order invisible.
                app(\App\Services\SupplierBridge\CaptureCreatedOrder::class)->captureMany([$order]);
            }
            $this->queueOrder((int) $record->flow_job_id);
            return;
        }
        if ($record instanceof FlowJob && ($force || $record->wasChanged([
            'status', 'title', 'supplier_instruction', 'currency', 'deleted_at',
            'completed_at', 'workflow_phase_id', 'supplier_delivery_date', 'estimated_delivery_date',
        ]))) $this->queueOrder((int) $record->id);
    }

    private function queueOrder(int $orderId): void
    {
        app(\App\Services\SupplierBridge\QueueChangedOrder::class)->mark($orderId);
    }
}
