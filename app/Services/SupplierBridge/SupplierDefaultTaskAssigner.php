<?php

namespace App\Services\SupplierBridge;

use App\Models\FlowJob;
use App\Models\SupplierTaskAssignment;
use App\Models\Task;
use App\Models\TaskPackItem;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\TaskService;
use App\Support\OrderTaskRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Resolve Task Pack's "Supplier" assignee to the supplier on the order's product.
 * An order-level task has no product-line ID: never guess when several suppliers
 * exist. The existing one-task/one-supplier authorization contract remains intact.
 */
final class SupplierDefaultTaskAssigner
{
    public function syncForOrder(FlowJob $order, ?User $actor = null): void
    {
        if (! $this->schemaReady()) return;

        // One bounded query for configured Order tasks, not one query per stage.
        $tasks = Task::query()
            ->where('flow_job_id', $order->id)
            ->whereHas('setupTemplate', fn ($q) => $q->where('default_assignee_type', 'supplier'))
            ->with(['phase:id,sequence', 'setupTemplate:id,automation_key', 'supplierAssignment', 'job:id,created_by,owner_id,coordinator_id'])
            ->get();
        if ($tasks->isEmpty()) return;

        $supplierIds = $this->orderSupplierIds($order->id);
        $supplierId = self::uniqueSupplierId($supplierIds);
        // A configured internal backup must also be eligible to authorize the
        // supplier assignment, subject to the same normal task permissions.
        $backupIds = $tasks->filter(fn (Task $task) => $task->assignee_id
            && $task->setup_assignee_id
            && (int) $task->assignee_id === (int) $task->setup_assignee_id)
            ->pluck('assignee_id')->all();
        $delegates = $supplierId ? $this->delegates($order, $actor, $backupIds) : [];
        $access = app(AccessControlService::class);
        $changed = false;

        foreach ($tasks as $task) {
            $existing = $task->supplierAssignment;
            // A deliberate supplier override takes precedence, but never keep
            // a now-unlinked supplier authorized after the product changes.
            if ($existing && ($existing->assignment_source ?? 'manual') !== 'task_pack') {
                if (! in_array((int) $existing->supplier_id, $supplierIds, true)) {
                    $existing->delete();
                    $changed = true;
                }
                continue;
            }

            $key = SupplierWorkflowProjection::actionKey(
                OrderTaskRequirement::automationKey($task), (int) ($task->phase?->sequence ?? 0)
            );
            // The internal Task Pack backup is not a manual override: both
            // supplier and employee must remain assigned to this same task.
            $hasInternalOverride = ! $existing && (bool) $task->assignee_id
                && (! $task->setup_assignee_id
                    || (int) $task->assignee_id !== (int) $task->setup_assignee_id);
            // A creator may lack access after ownership changes. Choose the
            // first eligible order creator/owner/coordinator for THIS task,
            // without a new user query for each task or bypassing permissions.
            $delegate = null;
            if ($key !== null && ! $hasInternalOverride) {
                foreach ($delegates as $candidate) {
                    if ($access->canEditTask($candidate, $task)
                        && (! in_array($key, SupplierWorkflowProjection::DOCUMENT_KEYS, true)
                            || $access->can($candidate, 'documents', 'create'))) {
                        $delegate = $candidate;
                        break;
                    }
                }
            }
            $canDelegate = $supplierId && $delegate !== null;

            if (! $canDelegate) {
                if ($existing && ($existing->assignment_source ?? '') === 'task_pack') {
                    $existing->delete();
                    $changed = true;
                }
                continue;
            }
            if (! $existing || (int) $existing->supplier_id !== $supplierId) {
                SupplierTaskAssignment::query()->updateOrCreate(
                    ['task_id' => $task->id],
                    ['flow_job_id' => $order->id, 'supplier_id' => $supplierId,
                        'assigned_by' => $delegate->id, 'assignment_source' => 'task_pack'],
                );
                $changed = true;
            }
        }
        if ($changed) $this->queueOrder($order->id);
    }

    /** One order-level task cannot safely act on behalf of several product suppliers. */
    public static function uniqueSupplierId(array $supplierIds): ?int
    {
        $unique = array_values(array_unique(array_map('intval', $supplierIds)));
        return count($unique) === 1 && $unique[0] > 0 ? $unique[0] : null;
    }

    /** Called only when the Task Pack assignment type changes. */
    public function syncForTaskPackItem(TaskPackItem $item): void
    {
        if (! $this->schemaReady()) return;
        if (($item->default_assignee_type ?? 'user') !== 'supplier') {
            $changed = SupplierTaskAssignment::query()
                ->where('assignment_source', 'task_pack')
                ->whereIn('task_id', Task::query()->select('id')->where('task_pack_task_id', $item->id))
                ->delete();
            if ($changed) {
                Task::query()->where('task_pack_task_id', $item->id)
                    ->whereNotNull('flow_job_id')->select('flow_job_id')->distinct()
                    ->orderBy('flow_job_id')->chunk(100, function ($rows): void {
                        foreach ($rows as $row) $this->queueOrder((int) $row->flow_job_id);
                    });
            }
            return;
        }

        Task::query()->where('task_pack_task_id', $item->id)
            ->whereNotNull('flow_job_id')->select('flow_job_id')
            ->distinct()->orderBy('flow_job_id')->chunk(100, function ($rows): void {
                $orders = FlowJob::query()->whereIn('id', $rows->pluck('flow_job_id'))->get();
                foreach ($orders as $order) $this->syncForOrder($order, auth()->user());
            });
    }

    /** Same existing Assignee editor, now with an explicit Supplier option. */
    public function assignExplicitly(Task $task, User $actor): void
    {
        abort_unless($task->job, 404);
        $job = $task->job;
        abort_unless(app(AccessControlService::class)->canAssignTask($actor, $task)
            && app(AccessControlService::class)->canEditTask($actor, $task), 403);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld((int) $job->id);
        $key = SupplierWorkflowProjection::actionKey(
            OrderTaskRequirement::automationKey($task), (int) ($task->phase?->sequence ?? 0));
        abort_unless($key !== null, 422, 'This task cannot be delegated to a supplier.');
        if (in_array($key, SupplierWorkflowProjection::DOCUMENT_KEYS, true)) {
            abort_unless(app(AccessControlService::class)->can($actor, 'documents', 'create'), 403);
        }
        $suppliers = $this->orderSupplierIds($job->id);
        if (count($suppliers) !== 1) {
            throw ValidationException::withMessages([
                'assignee' => count($suppliers) === 0
                    ? 'Choose a supplier for the order product first.'
                    : 'This order has multiple product suppliers. A single order-level task cannot be assigned automatically.',
            ]);
        }
        // Reuse the existing user-assignee mutation so audit/permissions remain intact.
        app(TaskService::class)->updateAssignee($task, null, $actor);
        SupplierTaskAssignment::query()->updateOrCreate(['task_id' => $task->id], [
            'flow_job_id' => $job->id, 'supplier_id' => $suppliers[0],
            'assigned_by' => $actor->id, 'assignment_source' => 'manual',
        ]);
        $this->queueOrder($job->id);
    }

    public function clearForTask(Task $task): void
    {
        if (SupplierTaskAssignment::query()->where('task_id', $task->id)->delete()) {
            $this->queueOrder((int) $task->flow_job_id);
        }
    }

    /** @return list<int> */
    private function orderSupplierIds(int $orderId): array
    {
        $rows = DB::table('flow_job_items as i')
            ->join('master_records as s', 's.id', '=', 'i.supplier_id')
            ->join('master_records as p', 'p.id', '=', 'i.catalog_product_id')
            ->leftJoin('product_supplier_links as l', function ($join): void {
                $join->on('l.product_id', '=', 'p.id')
                    ->on('l.supplier_id', '=', 's.id')
                    ->on('l.workspace_id', '=', 'p.workspace_id');
            })
            ->where('i.flow_job_id', $orderId)
            ->where('i.is_removed', false)->where('i.quantity', '>', 0)
            ->where('s.type', 'supplier')->where('s.status', 'active')
            ->whereNull('s.deleted_at')
            ->where('p.type', 'product')->whereNull('p.deleted_at')
            ->whereColumn('p.workspace_id', 's.workspace_id')
            ->select('i.supplier_id', 'l.id as linked_id', 'p.metadata')
            ->get();

        $eligible = [];
        foreach ($rows as $row) {
            if (! $row->linked_id) {
                $product = new \App\Models\MasterRecord();
                $product->setRawAttributes(['type' => 'product', 'metadata' => $row->metadata]);
                if (! $product->hasProductSupplier((int) $row->supplier_id)) continue;
            }
            $eligible[(int) $row->supplier_id] = true;
            if (count($eligible) > 1) break;
        }
        return array_keys($eligible);
    }

    /** @return list<User> Only eligible order-associated actors, loaded once. */
    private function delegates(FlowJob $order, ?User $actor, array $backupIds = []): array
    {
        $candidateIds = array_values(array_unique(array_filter(array_merge([
            (int) ($actor?->id ?? 0), (int) $order->created_by,
            (int) $order->owner_id, (int) $order->coordinator_id,
        ], $backupIds))));
        if ($candidateIds === []) return [];
        $users = User::query()->whereIn('id', $candidateIds)
            ->where('is_active', true)->get()->keyBy('id');
        $result = [];
        foreach ($candidateIds as $id) {
            if ($users->has($id)) $result[] = $users->get($id);
        }
        return $result;
    }

    private function schemaReady(): bool
    {
        static $ready = null;
        return $ready ??= Schema::hasTable('supplier_task_assignments')
            && Schema::hasColumn('task_pack_items', 'default_assignee_type')
            && Schema::hasColumn('supplier_task_assignments', 'assignment_source');
    }

    private function queueOrder(int $orderId): void
    {
        app(QueueChangedOrder::class)->mark($orderId);
    }
}
