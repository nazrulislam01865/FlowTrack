<?php

namespace App\Queries\Orders;

use App\Models\FlowJob;
use App\Models\Task;
use App\Models\MasterRecord;
use App\Models\TaskPackTask;
use App\Models\SupplierTaskAssignment;
use App\Services\Orders\OrderWorkflowSnapshotService;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Services\JobService;
use App\Services\OrderWorkflowBindingService;
use App\Services\OrderWorkflowSetupService;
use Illuminate\Support\Collection;
use WeakMap;

/**
 * Optional Order Details task-card reader. Never generates, mutates, authorizes
 * actions against, or caches authoritative workflow state between requests.
 *
 * The active Order Details caller passes an already authorized FlowJob and the
 * exact published phase graph. Direct callers must use taskCards(), which
 * reauthorizes the Order before fetching any task information.
 */
final class OrderWorkflowDisplayQuery
{
    /** @var WeakMap<FlowJob, array{source: Collection, count: int, projection: WorkflowDisplayProjection, phaseTasks: array<int, Collection>, documentSource: ?Collection, documentCount: int, documentsByTask: Collection}> */
    private WeakMap $loaded;

    /** @var WeakMap<FlowJob, array{phase: int, fullDocuments: bool, modalTaskId: int, resourceTaskId: int}> */
    private WeakMap $displayPreferences;

    /** @var WeakMap<FlowJob, bool> */
    private WeakMap $resourceDeferral;

    /** @var WeakMap<FlowJob, int> The single full-task phase, if scoped. */
    private WeakMap $phaseScope;

    public function __construct(private readonly AccessControlService $access)
    {
        $this->loaded = new WeakMap();
        $this->displayPreferences = new WeakMap();
        $this->resourceDeferral = new WeakMap();
        $this->phaseScope = new WeakMap();
    }

    /**
     * A Livewire request renders just one selected phase's task rows. A modal
     * may refer to documents from other phases, so retain the full document
     * collection while any workflow/document action modal is open.
     */
    public function preferDisplayPhase(FlowJob $order, int $phaseId, bool $fullDocuments = false, int $modalTaskId = 0, int $resourceTaskId = 0): void
    {
        $this->displayPreferences[$order] = [
            'phase' => $phaseId,
            'fullDocuments' => $fullDocuments,
            'modalTaskId' => max(0, $modalTaskId),
            'resourceTaskId' => max(0, $resourceTaskId),
        ];
    }

    /** @return array{phase: int, fullDocuments: bool, modalTaskId: int, resourceTaskId: int} */
    public function displayPreference(FlowJob $order): array
    {
        return $this->displayPreferences[$order] ?? [
            'phase' => (int) $order->workflow_phase_id,
            'fullDocuments' => true,
            'modalTaskId' => 0,
            'resourceTaskId' => 0,
        ];
    }

    public function setResourceDetailsDeferred(FlowJob $order, bool $deferred): void
    {
        $this->resourceDeferral[$order] = $deferred;
    }

    public function resourceDetailsDeferred(FlowJob $order): bool
    {
        return $this->resourceDeferral[$order] ?? false;
    }

    public function selectedTaskPhaseId(FlowJob $order): ?int
    {
        return $this->phaseScope[$order] ?? null;
    }

    /**
     * Document rows are attached after phase/task hydration, then indexed once.
     * An omitted or unauthorized task ID never gains document access.
     */
    public function indexLoadedDocuments(FlowJob $order): void
    {
        $entry = $this->validEntry($order);
        if ($entry === null || ! $order->relationLoaded('documents')) {
            return;
        }
        $documents = $order->getRelation('documents');
        $entry['documentSource'] = $documents;
        $entry['documentCount'] = $documents->count();
        $entry['documentsByTask'] = $documents->groupBy('task_id');
        $this->loaded[$order] = $entry;
    }

    /**
     * Secure standalone entry point. A supplied phase ID never overrides the
     * workflow's own phase scope or the user's task visibility rules.
     *
     * @param array<int, int> $phaseIds
     */
    public function taskCards(User $viewer, int $orderId, array $phaseIds): WorkflowDisplayProjection
    {
        $order = app(VisibleOrderQuery::class)->base($viewer, $orderId);
        $published = ! $order->completed_at
            && ! in_array((string) $order->status, JobService::INACTIVE_STATUSES, true)
            && app(OrderWorkflowSetupService::class)->isActiveOrderWorkflow((int) $order->workflow_id);
        if ($published) {
            $phases = app(OrderWorkflowBindingService::class)
                ->publishedPhasesForRead((int) $order->workflow_id);
        } else {
            $order->load('workflow.phases');
            $phases = $order->workflow?->phases ?? collect();
        }
        $allowed = $this->phaseIds($phases);
        $requested = array_map('intval', $phaseIds);
        $phases = $phases->filter(fn (WorkflowPhase $phase): bool => in_array((int) $phase->id, $requested, true));

        return $this->loadForAuthorizedOrder($order, $viewer, $phases, $allowed);
    }

    /**
     * Called only after VisibleOrderQuery authorizes the Order for this request.
     * $allAuthorizedPhaseIds is optional because the normal view includes every
     * phase; when supplied by taskCards(), it also prevents foreign phase IDs.
     *
     * @param Collection<int, WorkflowPhase> $phases
     * @param array<int, int>|null $allAuthorizedPhaseIds
     */
    public function loadForAuthorizedOrder(
        FlowJob $order,
        User $viewer,
        Collection $phases,
        ?array $allAuthorizedPhaseIds = null,
        ?array $taskPhaseIds = null,
    ): WorkflowDisplayProjection {
        $phaseIds = $this->phaseIds($phases);
        if ($allAuthorizedPhaseIds !== null) {
            $phaseIds = array_values(array_intersect($phaseIds, $allAuthorizedPhaseIds));
        }

        // In three-level mode only one phase receives full task-card relations.
        // Other phases are represented by compact counts from OrderPhaseSummaryQuery.
        // Modal/historical callers pass null and retain their old complete scope.
        if ($taskPhaseIds !== null) {
            $phaseIds = array_values(array_intersect($phaseIds, array_map('intval', $taskPhaseIds)));
        }
        if ($taskPhaseIds !== null && count($phaseIds) === 1) {
            $this->phaseScope[$order] = (int) $phaseIds[0];
        } else {
            unset($this->phaseScope[$order]);
        }

        // Explicit empty phase scope must stay empty, never widen to all tasks.
        $query = Task::query()
            ->where('flow_job_id', (int) $order->id)
            ->whereIn('workflow_phase_id', $phaseIds);
        $query = $this->access->applyTaskScope($query, $viewer);
        // Phase rows and Task Pack item/document-category relations have
        // already been loaded from the published definition in this request.
        // Reuse those exact objects instead of issuing two further eager-load
        // SELECTs for every Workflow render. Unknown legacy item references
        // retain the original eager-loader as a correctness fallback.
        $phasesById = $phases->keyBy(fn (WorkflowPhase $phase): int => (int) $phase->id);
        // Avoid lazy-loading one Task Pack for each historical phase. The
        // published workflow graph already has its pack/items ready; legacy
        // callers without that graph use one batched fallback after the loop.
        $itemsByPhase = $phases->mapWithKeys(static function (WorkflowPhase $phase): array {
            $pack = $phase->relationLoaded('taskPack') ? $phase->getRelation('taskPack') : null;
            $items = $pack && $pack->relationLoaded('items')
                ? $pack->getRelation('items')
                : collect();

            return [(int) $phase->id => $items->keyBy('id')];
        });

        // A compact server-side snapshot replaces the all-phase Task SELECT
        // only for a fully authorized administrator, with no action modal open.
        // Restricted/supplier roles and any missing/stale snapshot retain the
        // exact existing, permission-scoped live reader below.
        $preference = $this->displayPreference($order);
        $snapshot = $taskPhaseIds === null && ! $preference['fullDocuments'] && $preference['modalTaskId'] === 0
            ? app(OrderWorkflowSnapshotService::class)->forAuthorizedOrder($order, $viewer, $phases)
            : null;
        $tasks = $snapshot !== null
            ? $this->tasksFromSnapshot($snapshot, $phasesById, $itemsByPhase)
            : null;
        if ($tasks === null) {
            $tasks = $query->with([
                    'assignee:id,name,profile_image_path',
                    'template',
                    'documentCategory',
                    'supplierAssignment',
                ])
                ->withExists(['documents', 'links'])
                ->get();
        }

        $fallback = new \Illuminate\Database\Eloquent\Collection();
        foreach ($tasks as $task) {
            $phase = $phasesById->get((int) $task->workflow_phase_id);
            if ($phase) {
                $task->setRelation('phase', $phase);
            } else {
                $fallback->push($task);
            }

            $templateId = (int) ($task->task_pack_task_id ?: 0);
            if ($templateId <= 0) {
                $task->setRelation('setupTemplate', null);
            } elseif ($phase && ($item = $itemsByPhase->get((int) $phase->id)?->get($templateId))
                && $item->relationLoaded('documentCategory')) {
                $task->setRelation('setupTemplate', $item);
            } else {
                // Unknown/mismatched legacy templates and incomplete published
                // graph data must retain the original relation/category fields.
                $fallback->push($task);
            }
        }
        if ($fallback->isNotEmpty()) {
            $fallback->unique('id')->loadMissing(['phase', 'setupTemplate.documentCategory']);
        }

        $projection = new WorkflowDisplayProjection(
            $phases->values(),
            $tasks->keyBy(fn (Task $task): int => (int) $task->id),
            $tasks->groupBy(fn (Task $task): int => (int) $task->workflow_phase_id),
            $tasks,
        );
        $order->setRelation('tasks', $tasks);
        $documents = $order->relationLoaded('documents') ? $order->getRelation('documents') : null;
        $this->loaded[$order] = [
            'source' => $tasks,
            'count' => $tasks->count(),
            'projection' => $projection,
            'phaseTasks' => [],
            'documentSource' => $documents,
            'documentCount' => $documents?->count() ?? 0,
            'documentsByTask' => $documents?->groupBy('task_id') ?? collect(),
        ];

        return $projection;
    }

    /**
     * Reconstruct only presentation-safe Eloquent models; none is saved and
     * none is used to authorize actions. Relations are explicitly marked as
     * loaded so a view does not trigger hidden N+1 SQL requests.
     *
     * @param array<string,mixed> $snapshot
     * @return \Illuminate\Database\Eloquent\Collection<int,Task>|null
     */
    private function tasksFromSnapshot(array $snapshot, Collection $phasesById, Collection $itemsByPhase): ?\Illuminate\Database\Eloquent\Collection
    {
        $tasks = new \Illuminate\Database\Eloquent\Collection();
        foreach ($snapshot['tasks'] as $row) {
            $attributes = $row['attributes'];
            $phase = $phasesById->get((int) $attributes['workflow_phase_id']);
            if (! $phase) return null;
            $task = (new Task())->newFromBuilder($attributes);
            $task->setRelation('phase', $phase);
            $itemId = (int) ($task->task_pack_task_id ?: 0);
            $setupItem = $itemId > 0 ? $itemsByPhase->get((int) $phase->id)?->get($itemId) : null;
            if ($itemId > 0 && (! $setupItem || ! $setupItem->relationLoaded('documentCategory'))) {
                // Legacy Task Packs cannot safely be represented by this
                // compact schema; use the original scoped query unchanged.
                return null;
            }
            $task->setRelation('setupTemplate', $setupItem);
            $task->setRelation('template', is_array($row['template'] ?? null)
                ? (new TaskPackTask())->newFromBuilder($row['template']) : null);
            $task->setRelation('assignee', is_array($row['assignee'] ?? null)
                ? (new User())->newFromBuilder($row['assignee']) : null);
            $task->setRelation('documentCategory', is_array($row['document_category'] ?? null)
                ? (new MasterRecord())->newFromBuilder($row['document_category']) : null);
            $task->setRelation('supplierAssignment', ! empty($row['supplier_assignment_exists'])
                ? new SupplierTaskAssignment(['task_id' => (int) $task->id]) : null);
            $tasks->push($task);
        }
        return $tasks;
    }

    /** @return Collection<int, Task>|null Null means use the legacy reader. */
    public function tasksForPhase(FlowJob $order, int $phaseId): ?Collection
    {
        $entry = $this->validEntry($order);

        return $entry ? $entry['projection']->tasksByPhase->get($phaseId, collect()) : null;
    }

    /**
     * Evidence for a displayed task in O(1) average lookup time. A null
     * result tells legacy callers to use their original loaded collection.
     * This never authorizes a download; document routes check access again.
     */
    public function documentsForTask(FlowJob $order, int $taskId): ?Collection
    {
        $entry = $this->validEntry($order);
        if (! $entry) {
            return null;
        }
        // A document lookup must never widen a restricted user's authorized
        // task map, even if the Order document collection contains other tasks.
        if (! $entry['projection']->tasksById->has($taskId)) {
            return collect();
        }
        if (! $entry['documentSource'] || ! $order->relationLoaded('documents')) {
            return null;
        }
        $current = $order->getRelation('documents');
        if ($current !== $entry['documentSource'] || $current->count() !== $entry['documentCount']) {
            return null;
        }

        return $entry['documentsByTask']->get($taskId, collect());
    }

    public function taskById(FlowJob $order, int $taskId): ?Task
    {
        $entry = $this->validEntry($order);

        return $entry ? $entry['projection']->tasksById->get($taskId) : null;
    }

    /**
     * Repeated stage cards and task modes must not filter/sort the same phase
     * again. The cache is attached only to the loaded Eloquent instance and is
     * invalidated when its task relation is replaced or its size changes.
     */
    public function rememberedPhaseTasks(FlowJob $order, int $phaseId, callable $build): Collection
    {
        $entry = $this->validEntry($order);
        if (! $entry) {
            return $build();
        }
        if (isset($entry['phaseTasks'][$phaseId])) {
            return $entry['phaseTasks'][$phaseId];
        }

        $tasks = $build();
        $entry['phaseTasks'][$phaseId] = $tasks;
        $this->loaded[$order] = $entry;

        return $tasks;
    }

    /** @return array{source: Collection, count: int, projection: WorkflowDisplayProjection, phaseTasks: array<int, Collection>, documentSource: ?Collection, documentCount: int, documentsByTask: Collection}|null */
    private function validEntry(FlowJob $order): ?array
    {
        if (! isset($this->loaded[$order]) || ! $order->relationLoaded('tasks')) {
            return null;
        }
        $entry = $this->loaded[$order];
        $current = $order->getRelation('tasks');
        if ($entry['source'] !== $current || $entry['count'] !== $current->count()) {
            unset($this->loaded[$order]);
            return null;
        }

        return $entry;
    }

    /** @param Collection<int, WorkflowPhase> $phases
     *  @return array<int, int>
     */
    private function phaseIds(Collection $phases): array
    {
        return $phases->pluck('id')->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)->unique()->values()->all();
    }
}
