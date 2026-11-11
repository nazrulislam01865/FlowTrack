<?php

namespace App\Services\Orders;

use App\Jobs\RebuildOrderWorkflowReadSnapshot;
use App\Models\FlowJob;
use App\Models\OrderWorkflowReadSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Services\JobService;
use App\Services\OrderWorkflowBindingService;
use App\Services\OrderWorkflowSetupService;
use App\Support\Performance\OrderBaselineRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rebuildable, server-side Order workflow display state. Never authoritative.
 * All actions still load and lock the real Task and FlowJob records.
 */
final class OrderWorkflowSnapshotService
{
    public const SCHEMA_VERSION = 1;

    private ?bool $tableAvailable = null;

    /** @var array<string, array<int, true>> Once per root DB transaction. */
    private array $invalidations = [];

    public function writerEnabled(): bool
    {
        return (bool) config('flowtrack.workflow_read_snapshot_writes_enabled', false);
    }

    public function readerEnabled(): bool
    {
        return $this->writerEnabled()
            && (bool) config('flowtrack.workflow_read_snapshot_enabled', false)
            && (bool) config('flowtrack.workflow_display_query_enabled', false);
    }

    private function tableReady(): bool
    {
        return $this->tableAvailable ??= Schema::hasTable('order_workflow_read_snapshots');
    }

    /**
     * Only administrators can reuse an unscoped display snapshot. Everybody
     * else goes through the existing permission-scoped live Task reader. This
     * includes supplier-linked/restricted internal roles and remains safe if
     * the snapshot was built by a more privileged worker.
     */
    public function isEligible(FlowJob $order, User $viewer, Collection $phases): bool
    {
        return app(AccessControlService::class)->isAdministrator($viewer)
            && ! $order->completed_at
            && ! in_array((string) $order->status, JobService::INACTIVE_STATUSES, true)
            && $phases->isNotEmpty()
            && $phases->contains('id', (int) $order->workflow_phase_id);
    }

    /**
     * Read only after VisibleOrderQuery has authorized the Order. A null means
     * the caller must use its bounded, existing permission-scoped live query.
     * @return array<string,mixed>|null
     */
    public function forAuthorizedOrder(FlowJob $order, User $viewer, Collection $phases): ?array
    {
        if (! $this->readerEnabled() || ! $this->isEligible($order, $viewer, $phases)) {
            return null;
        }

        try {
            $row = OrderWorkflowReadSnapshot::query()->where('flow_job_id', $order->id)->first();
            $revision = $this->definitionRevision($phases);
            if ($row && (int) $row->source_version === (int) $order->workflow_display_version
                && (int) $row->schema_version === self::SCHEMA_VERSION
                && hash_equals($revision, (string) $row->definition_revision)) {
                $payload = json_decode((string) $row->projection_json, true);
                if ($this->validProjection($payload, $order, $phases)) {
                    OrderBaselineRecorder::record('workflow_snapshot_read', [
                        'order_id' => (int) $order->id,
                        'result' => 'hit',
                        'bytes' => strlen((string) $row->projection_json),
                    ]);
                    return $payload;
                }
            }
            OrderBaselineRecorder::record('workflow_snapshot_read', [
                'order_id' => (int) $order->id,
                'result' => $row ? 'stale_or_invalid' : 'miss',
            ]);
        } catch (Throwable $exception) {
            // Never make a queue, storage, or JSON problem break Order Details.
            Log::warning('Order workflow snapshot read fell back to scoped live reader.', [
                'order_id' => $order->id, 'error' => $exception->getMessage(),
            ]);
        }

        $this->requestRebuild((int) $order->id);

        return null;
    }

    /** @param mixed $payload */
    public function validProjection(mixed $payload, FlowJob $order, Collection $phases): bool
    {
        if (! is_array($payload) || (int) ($payload['schema_version'] ?? -1) !== self::SCHEMA_VERSION
            || (int) ($payload['source_version'] ?? -1) !== (int) $order->workflow_display_version
            || ! isset($payload['phases'], $payload['tasks'])
            || ! is_array($payload['phases']) || ! is_array($payload['tasks'])) {
            return false;
        }
        $ids = $phases->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $stored = array_map(static fn ($phase): int => is_array($phase) ? (int) ($phase['id'] ?? 0) : 0, $payload['phases']);
        sort($stored);
        if ($ids !== $stored || count($payload['tasks']) > 10000) return false;
        $seen = [];
        foreach ($payload['tasks'] as $task) {
            if (! is_array($task) || ! is_array($task['attributes'] ?? null)
                || ! array_key_exists('assignee', $task)
                || ! array_key_exists('document_category', $task)
                || ! array_key_exists('template', $task)
                || ! is_bool($task['supplier_assignment_exists'] ?? null)) return false;
            $attrs = $task['attributes'];
            $id = (int) ($attrs['id'] ?? 0);
            $phaseId = (int) ($attrs['workflow_phase_id'] ?? 0);
            if ($id <= 0 || isset($seen[$id]) || ! in_array($phaseId, $ids, true)
                || (int) ($attrs['flow_job_id'] ?? 0) !== (int) $order->id
                || ! is_string($attrs['title'] ?? null)
                || ! is_string($attrs['status'] ?? null)
                || ! array_key_exists('progress', $attrs)
                || ! array_key_exists('documents_exists', $attrs)
                || ! array_key_exists('links_exists', $attrs)
                || ! is_bool($attrs['documents_exists'])
                || ! is_bool($attrs['links_exists'])
                || (isset($task['assignee']) && ! is_array($task['assignee']))
                || (isset($task['document_category']) && ! is_array($task['document_category']))
                || (isset($task['template']) && ! is_array($task['template']))) {
                return false;
            }
            $seen[$id] = true;
        }
        return true;
    }

    /**
     * Stable definition fingerprint. Includes source Task Pack fields used by
     * task order, eligibility, badges, automation and card rendering. Changes
     * are detected even when a bulk definition change bypasses Eloquent events.
     */
    public function definitionRevision(Collection $phases): string
    {
        return hash('sha256', json_encode($phases->sortBy('id')->map(static function (WorkflowPhase $phase): array {
            $pack = $phase->relationLoaded('taskPack') ? $phase->getRelation('taskPack') : null;
            $items = $pack && $pack->relationLoaded('items') ? $pack->getRelation('items') : collect();
            return [
                'phase' => $phase->getAttributes(),
                'items' => $items->sortBy('id')->map(static fn ($item): array => $item->getAttributes())->values()->all(),
            ];
        })->values()->all(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * A short, guarded publication transaction. Building the projection does
     * not hold the Order lock; an obsolete generation can never overwrite V+1.
     * Returns false only for an obsolete build (the job can retry latest V).
     */
    public function publishIfCurrent(int $orderId, ?int $expectedVersion = null): bool
    {
        if (! $this->writerEnabled() || ! $this->tableReady()) return true;

        $order = FlowJob::query()->find($orderId);
        if (! $order || $order->completed_at
            || in_array((string) $order->status, JobService::INACTIVE_STATUSES, true)) {
            return true;
        }

        $version = (int) $order->workflow_display_version;
        if ($expectedVersion !== null && $version !== $expectedVersion) return false;

        $isPublishedOrder = app(OrderWorkflowSetupService::class)
            ->isActiveOrderWorkflow((int) $order->workflow_id);
        if ($isPublishedOrder) {
            $phases = app(OrderWorkflowBindingService::class)
                ->publishedPhasesForRead((int) $order->workflow_id);
        } else {
            // For an opt-in Order definition snapshot, the copy is canonical
            // for phase/task display; never read the now-editable source pack.
            // With the flag off, retain exactly the original writer behavior.
            if (! config('flowtrack.order_template_version_isolation_enabled', false)) {
                return true;
            }
            $workflow = $order->workflow;
            if (! $workflow || ! $workflow->is_snapshot
                || (int) $workflow->source_workflow_id !== (int) ($order->source_workflow_id ?: 0)
                || ! app(OrderWorkflowSetupService::class)
                    ->isOrderWorkflow((int) $order->source_workflow_id)) {
                return true;
            }
            $phases = $workflow->phases()->with([
                'taskPack.items.defaultAssignee',
                'taskPack.items.defaultDepartment',
                'taskPack.items.priority',
                'taskPack.items.documentCategory',
            ])->get()->values();
        }
        if ($phases->isEmpty() || ! $phases->contains('id', (int) $order->workflow_phase_id)) return true;
        $phaseIds = $phases->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $itemsByPhase = $phases->mapWithKeys(static function ($phase): array {
            $pack = $phase->relationLoaded('taskPack') ? $phase->getRelation('taskPack') : null;
            return [(int) $phase->id => $pack && $pack->relationLoaded('items')
                ? $pack->getRelation('items')->keyBy('id') : collect()];
        });

        $tasks = Task::query()->where('flow_job_id', $orderId)
            ->whereIn('workflow_phase_id', $phaseIds)
            ->with([
                'assignee:id,name,profile_image_path',
                'documentCategory:id,name',
                'template:id,sequence,color',
            ])
            ->withExists(['documents', 'links', 'supplierAssignment'])
            ->orderBy('id')->get();

        $taskRows = [];
        foreach ($tasks as $task) {
            $templateId = (int) ($task->task_pack_task_id ?: 0);
            // A legacy task whose Task Pack item no longer belongs to the
            // published graph is served by the live compatibility reader.
            if ($templateId && ! $itemsByPhase->get((int) $task->workflow_phase_id)?->has($templateId)) return true;

            $fields = [
                'id', 'flow_job_id', 'workflow_phase_id', 'task_pack_task_id',
                'task_number', 'title', 'description', 'status', 'progress',
                'priority', 'start_date', 'due_date', 'completed_at',
                'assignee_id', 'setup_assignee_id', 'document_category_id',
                'document_requirement_source', 'order_task_status_id',
                'order_task_flag_id', 'needs_attention', 'attention_reason',
            ];
            $attributes = array_intersect_key($task->getAttributes(), array_flip($fields));
            $attributes['documents_exists'] = (bool) $task->getAttribute('documents_exists');
            $attributes['links_exists'] = (bool) $task->getAttribute('links_exists');
            $taskRows[] = [
                'attributes' => $attributes,
                'assignee' => $task->assignee ? [
                    'id' => (int) $task->assignee->id,
                    'name' => (string) $task->assignee->name,
                    'profile_image_path' => $task->assignee->profile_image_path,
                ] : null,
                'document_category' => $task->documentCategory ? [
                    'id' => (int) $task->documentCategory->id,
                    'name' => (string) $task->documentCategory->name,
                ] : null,
                'template' => $task->template ? [
                    'id' => (int) $task->template->id,
                    'sequence' => (int) $task->template->sequence,
                    'color' => $task->template->color,
                ] : null,
                'supplier_assignment_exists' => (bool) $task->getAttribute('supplier_assignment_exists'),
            ];
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'source_version' => $version,
            'phases' => $phases->sortBy('sequence')->map(static fn ($phase): array => [
                'id' => (int) $phase->id,
                'sequence' => (int) $phase->sequence,
                'name' => (string) $phase->name,
                'short_name' => (string) $phase->short_name,
                'color' => (string) $phase->color,
            ])->values()->all(),
            'tasks' => $taskRows,
        ];
        $definitionRevision = $this->definitionRevision($phases);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $published = DB::transaction(static function () use ($orderId, $version, $definitionRevision, $json): bool {
            $locked = FlowJob::query()->whereKey($orderId)->lockForUpdate()->first();
            if (! $locked || (int) $locked->workflow_display_version !== $version) return false;
            $now = now();
            OrderWorkflowReadSnapshot::query()->upsert([[
                'flow_job_id' => $orderId,
                'source_version' => $version,
                'definition_revision' => $definitionRevision,
                'schema_version' => self::SCHEMA_VERSION,
                'projection_json' => $json,
                'generated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['flow_job_id'], [
                'source_version', 'definition_revision', 'schema_version',
                'projection_json', 'generated_at', 'updated_at',
            ]);
            return true;
        }, 3);
        OrderBaselineRecorder::record('workflow_snapshot_build', [
            'order_id' => $orderId,
            'result' => $published ? 'published' : 'obsolete',
            'bytes' => $published ? strlen($json) : 0,
            'task_count' => count($taskRows),
        ]);
        return $published;
    }

    /**
     * Durable version invalidation happens INSIDE the caller transaction; one
     * increment per Order per root transaction, including nested mutations.
     * A rollback also rolls the version back and queues nothing.
     */
    public function invalidateInTransaction(int $orderId): void
    {
        if (! $this->writerEnabled() || $orderId <= 0) return;
        $level = DB::transactionLevel();
        $key = null;
        if ($level > 0) {
            $manager = app('db.transactions');
            $transactions = $manager->getPendingTransactions()->filter(
                fn ($transaction): bool => $transaction->connection === DB::connection()->getName()
            );
            $root = $transactions->sortBy('level')->first();
            $key = $root ? 'transaction:'.spl_object_id($root) : 'transaction:level-'.$level;
            if (isset($this->invalidations[$key][$orderId])) return;
        }

        DB::table('flow_jobs')->where('id', $orderId)->increment('workflow_display_version');
        if ($key === null) {
            $this->requestRebuild($orderId);
            return;
        }

        $this->invalidations[$key][$orderId] = true;
        if (count($this->invalidations[$key]) !== 1) return;

        $manager->addCallbackForRollback(function () use ($key): void {
            unset($this->invalidations[$key]);
        });
        DB::afterCommit(function () use ($key): void {
            $ids = array_keys($this->invalidations[$key] ?? []);
            unset($this->invalidations[$key]);
            foreach ($ids as $id) $this->requestRebuild((int) $id);
        });
    }

    /**
     * Definition changes can touch many Orders; one SQL UPDATE invalidates
     * them all. Rebuilds are rate-limited by the backfill command or lazy
     * Workflow reads rather than dispatching thousands of jobs on a web POST.
     */
    public function invalidateForWorkflow(int $workflowId): void
    {
        if (! $this->writerEnabled() || $workflowId <= 0) return;
        FlowJob::query()->where('workflow_id', $workflowId)->increment('workflow_display_version');
    }

    public function invalidateForTaskPackItem(int $itemId): void
    {
        if (! $this->writerEnabled() || $itemId <= 0) return;
        FlowJob::query()->whereIn('id', Task::query()
            ->select('flow_job_id')->where('task_pack_task_id', $itemId)
            ->whereNotNull('flow_job_id'))
            ->increment('workflow_display_version');
    }

    /**
     * Existing Master Data and account-maintenance code contains a few
     * set-based Task updates, which never fire Task model observers. Bump all
     * affected Orders in one indexed UPDATE; lazily rebuild in controlled
     * batches, not once per affected Task inside the admin request.
     */
    public function invalidateForTaskColumn(string $column, int $value): void
    {
        if (! $this->writerEnabled() || $value <= 0
            || ! in_array($column, ['task_flag_id', 'order_task_status_id', 'setup_assignee_id'], true)) {
            return;
        }
        FlowJob::query()->whereIn('id', Task::query()
            ->select('flow_job_id')->where($column, $value)
            ->whereNotNull('flow_job_id'))
            ->increment('workflow_display_version');
    }

    /** Best-effort dispatch; an unavailable queue must not reject a valid task action. */
    public function requestRebuild(int $orderId): void
    {
        if (! $this->writerEnabled() || $orderId <= 0) return;
        try {
            RebuildOrderWorkflowReadSnapshot::dispatch($orderId)->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('Order workflow snapshot rebuild could not be queued.', [
                'order_id' => $orderId, 'error' => $exception->getMessage(),
            ]);
        }
    }
}
