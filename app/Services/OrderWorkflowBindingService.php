<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\FlowJobPhaseHistory;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Support\OrderStageResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps active Orders synchronized with the Order workflow they were created
 * from. Multiple Order workflows can coexist; no workflow is allowed to steal
 * active Orders that belong to another template. Completed/cancelled Orders
 * remain untouched for historical integrity.
 */
class OrderWorkflowBindingService
{
    /**
     * Published workflow definitions already loaded during this Laravel request.
     * AppServiceProvider registers this service as scoped, so these model graphs
     * are never reused across requests and cannot hide later Workflow Setup edits.
     *
     * @var array<int,array{0:Workflow,1:Collection<int,WorkflowPhase>}>
     */
    private array $publishedWorkflowCache = [];

    /** @var array<int,Collection<int,WorkflowPhase>> */
    private array $publishedPhaseCache = [];

    /**
     * Capture an active Order's destination while legacy phase names still
     * exist. This is used during the one-time 5-stage -> 7-stage upgrade so
     * renaming phase 5 cannot turn an Invoice/Payment Order into Shipment.
     *
     * @return array<int,int> job id => target seven-stage sequence
     */
    public function captureActiveOrderTargetSequences(int $workflowId): array
    {
        $targets = [];

        FlowJob::query()
            ->whereNull('deleted_at')
            ->whereNull('completed_at')
            ->whereNotIn('status', JobService::INACTIVE_STATUSES)
            ->where(function ($query) use ($workflowId): void {
                $query->where('source_workflow_id', $workflowId)
                    ->orWhere(function ($legacy) use ($workflowId): void {
                        $legacy->whereNull('source_workflow_id')->where('workflow_id', $workflowId);
                    });
            })
            ->with('phase:id,name,short_name,sequence')
            ->orderBy('id')
            ->get(['id', 'workflow_phase_id', 'status'])
            ->each(function (FlowJob $job) use (&$targets): void {
                $targets[(int) $job->id] = $this->targetSequence($job, 7);
            });

        return $targets;
    }

    /**
     * @param array<int,int> $targetSequences Optional captured pre-upgrade map.
     */
    public function syncActiveOrders(int $workflowId, array $targetSequences = []): int
    {
        [$workflow, $phases] = $this->publishedWorkflow($workflowId);
        if ($phases->isEmpty()) return 0;

        $count = 0;
        FlowJob::query()
            ->whereNull('deleted_at')
            ->whereNull('completed_at')
            ->whereNotIn('status', JobService::INACTIVE_STATUSES)
            ->where(function ($query) use ($workflowId): void {
                $query->where('source_workflow_id', $workflowId)
                    ->orWhere(function ($legacy) use ($workflowId): void {
                        $legacy->whereNull('source_workflow_id')->where('workflow_id', $workflowId);
                    });
            })
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(50, function ($orders) use ($workflow, $phases, $targetSequences, &$count): void {
                foreach ($orders as $order) {
                    $jobId = (int) $order->id;
                    $this->syncOrder($jobId, $workflow, $phases, $targetSequences[$jobId] ?? null);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Repair one active Order while opening Order Details. This makes old
     * five-stage snapshots disappear immediately instead of waiting for a
     * second admin save in Workflow Setup.
     */
    public function syncSingleActiveOrder(int $jobId): bool
    {
        $job = FlowJob::query()
            ->whereKey($jobId)
            ->whereNull('deleted_at')
            ->whereNull('completed_at')
            ->whereNotIn('status', JobService::INACTIVE_STATUSES)
            ->first([
                'id',
                'workflow_id',
                'source_workflow_id',
                'workflow_phase_id',
                'source_workflow_phase_id',
                'status',
            ]);
        if (! $job) return false;

        $workflowId = (int) ($job->source_workflow_id ?: $job->workflow_id);
        if (! $workflowId) return false;

        $setup = app(OrderWorkflowSetupService::class);
        if (! $setup->isActiveOrderWorkflow($workflowId)) return false;

        // Validate the published definition before touching the compatibility
        // runtime mirror, matching the previous isReadyForOrderCreation() order
        // of operations. The same loaded phase graph is then reused below.
        $phases = $this->publishedPhasesForRead($workflowId);
        if ($phases->isEmpty() || ! $setup->publishedPhasesAreReady($phases)) return false;

        [$workflow, $phases] = $this->publishedWorkflow($workflowId);

        // Order Details used to run the full workflow repair transaction every
        // time an already-correct Order was viewed. That path walks every phase
        // and generated task, performs firstOrCreate/update checks, synchronizes
        // flags/statuses, and repairs history even when no setup change exists.
        //
        // Keep the repair behavior as the fallback, but first prove that the
        // current runtime already matches the published definition. The check is
        // deliberately strict: any missing/stale generated task, setup assignee,
        // document requirement, phase binding, or active phase history falls
        // through to the original syncOrder() implementation.
        if ($this->runtimeMatchesPublishedDefinition($job, $workflow, $phases)) {
            return false;
        }

        $this->syncOrder($jobId, $workflow, $phases);
        return true;
    }

    /**
     * Cheap, read-only fast path for an Order that already matches its
     * published workflow. Returning false here is intentionally conservative:
     * prepareSelectedJob() will still run the standalone artwork-evidence repair,
     * preserving the existing historical-file self-healing behavior.
     */
    private function runtimeMatchesPublishedDefinition(FlowJob $job, Workflow $workflow, Collection $phases): bool
    {
        if ((int) $job->workflow_id !== (int) $workflow->id) return false;
        if ((int) $job->source_workflow_id !== (int) $workflow->id) return false;

        $phaseIds = $phases
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($phaseIds->isEmpty()) return false;
        if (! $phaseIds->contains((int) $job->workflow_phase_id)) return false;
        if ((int) ($job->source_workflow_phase_id ?: 0) !== (int) $job->workflow_phase_id) return false;

        $templates = $phases
            ->flatMap(fn (WorkflowPhase $phase) => $phase->taskPack?->items?->map(
                fn ($item) => ['phase_id' => (int) $phase->id, 'item' => $item],
            ) ?? collect())
            ->values();

        if ($templates->isEmpty()) return false;

        $expectedPairs = $templates
            ->map(fn (array $row) => $row['phase_id'].':'.(int) $row['item']->id)
            ->sort()
            ->values();

        $runtimeTasks = Task::query()
            ->where('flow_job_id', $job->id)
            ->whereNotNull('task_pack_task_id')
            ->get([
                'id',
                'workflow_phase_id',
                'task_pack_task_id',
                'assignee_id',
                'setup_assignee_id',
                'document_category_id',
                'description',
                'start_date',
            ]);

        $actualPairs = $runtimeTasks
            ->map(fn (Task $task) => (int) $task->workflow_phase_id.':'.(int) $task->task_pack_task_id)
            ->sort()
            ->values();

        if ($actualPairs->all() !== $expectedPairs->all()) return false;

        $templatesById = $templates->keyBy(fn (array $row) => (int) $row['item']->id);

        // Task Pack department fallbacks are resolved in two bounded queries,
        // matching LegacyJobService::syncPhaseTaskPack(). This lets the fast
        // path detect a changed setup assignee without running the full repair.
        $departmentCodes = $templates
            ->map(fn (array $row) => $row['item'])
            ->filter(fn ($item) => ! $item->defaultAssignee && $item->defaultDepartment)
            ->pluck('defaultDepartment.code')
            ->filter()
            ->unique()
            ->values();

        $departmentIdsByCode = $departmentCodes->isEmpty()
            ? collect()
            : Department::query()->whereIn('code', $departmentCodes)->pluck('id', 'code');

        $departmentIds = $departmentIdsByCode->values()->filter()->unique()->values();
        $fallbackUsersByDepartment = $departmentIds->isEmpty()
            ? collect()
            : User::query()
                ->where('is_active', true)
                ->whereIn('department_id', $departmentIds)
                ->orderBy('id')
                ->get(['id', 'department_id'])
                ->unique('department_id')
                ->keyBy('department_id');

        foreach ($runtimeTasks as $task) {
            $templateRow = $templatesById->get((int) $task->task_pack_task_id);
            if (! $templateRow) return false;

            $template = $templateRow['item'];
            $expectedAssigneeId = $template->defaultAssignee?->id;
            if (! $expectedAssigneeId && $template->defaultDepartment) {
                $departmentId = (int) ($departmentIdsByCode->get($template->defaultDepartment->code) ?: 0);
                $expectedAssigneeId = $departmentId
                    ? $fallbackUsersByDepartment->get($departmentId)?->id
                    : null;
            }

            if ((int) ($task->setup_assignee_id ?: 0) !== (int) ($expectedAssigneeId ?: 0)) return false;
            if ((int) ($task->document_category_id ?: 0) !== (int) ($template->document_category_id ?: 0)) return false;

            if (blank($task->description) && filled($template->description)) return false;

            // syncPhaseTaskPack() guarantees a start date for generated tasks
            // in the active phase. A missing value means the repair still has
            // real work to do, so do not take the fast path.
            if ((int) $task->workflow_phase_id === (int) $job->workflow_phase_id && ! $task->start_date) return false;
        }

        return FlowJobPhaseHistory::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', $job->workflow_phase_id)
            ->whereNull('completed_at')
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Reuse the exact published phase graph already loaded by the binding check
     * when Order Details renders later in the same Livewire request. On later
     * Livewire requests the scoped service starts empty and this performs one
     * fresh read, so Workflow/Task Pack edits remain immediately visible.
     *
     * @return Collection<int,WorkflowPhase>
     */
    public function publishedPhasesForRead(int $workflowId): Collection
    {
        if (isset($this->publishedWorkflowCache[$workflowId])) {
            return $this->publishedWorkflowCache[$workflowId][1];
        }

        return $this->loadPublishedPhases($workflowId);
    }

    /**
     * Return the published phase graph only when an earlier operation in this
     * same Laravel request already loaded it. This lets auto-advance reuse the
     * binding validation graph without making callers that did not run binding
     * pay for the full seven-stage Task Pack graph.
     *
     * @return Collection<int,WorkflowPhase>|null
     */
    public function cachedPublishedPhasesForRead(int $workflowId): ?Collection
    {
        if (isset($this->publishedWorkflowCache[$workflowId])) {
            return $this->publishedWorkflowCache[$workflowId][1];
        }

        return $this->publishedPhaseCache[$workflowId] ?? null;
    }

    /** @return array{0:Workflow,1:Collection<int,WorkflowPhase>} */
    private function publishedWorkflow(int $workflowId): array
    {
        if (isset($this->publishedWorkflowCache[$workflowId])) {
            return $this->publishedWorkflowCache[$workflowId];
        }

        app(OrderWorkflowSetupService::class)->ensureRuntimeMirror($workflowId);
        $workflow = Workflow::query()
            ->whereKey($workflowId)
            ->where('is_snapshot', false)
            ->firstOrFail();

        $phases = $this->loadPublishedPhases($workflowId);

        return $this->publishedWorkflowCache[$workflowId] = [$workflow, $phases];
    }

    /** @return Collection<int,WorkflowPhase> */
    private function loadPublishedPhases(int $workflowId): Collection
    {
        if (isset($this->publishedPhaseCache[$workflowId])) {
            return $this->publishedPhaseCache[$workflowId];
        }

        // IMPORTANT: read dedicated setup phases through workflow_template_id,
        // not the legacy Workflow::phases relation. Old installations can have
        // historical workflow_id rows that otherwise resurrect an old 5-stage
        // design on Order Details/Create Order.
        return $this->publishedPhaseCache[$workflowId] = WorkflowPhase::query()
            ->where('workflow_template_id', $workflowId)
            ->where('is_active', true)
            ->with([
                'taskPack.items.defaultAssignee',
                'taskPack.items.defaultDepartment',
                'taskPack.items.priority',
                'taskPack.items.documentCategory',
            ])
            ->orderBy('sequence')
            ->get()
            ->values();
    }

    private function syncOrder(int $jobId, Workflow $workflow, Collection $phases, ?int $targetSequenceOverride = null): void
    {
        DB::transaction(function () use ($jobId, $workflow, $phases, $targetSequenceOverride): void {
            $job = FlowJob::query()
                ->lockForUpdate()
                ->with(['phase:id,name,short_name,sequence', 'workflow:id,is_snapshot,source_workflow_id'])
                ->findOrFail($jobId);

            $targetSequence = $targetSequenceOverride !== null
                ? max(1, min($phases->count(), $targetSequenceOverride))
                : $this->targetSequence($job, $phases->count());
            /** @var WorkflowPhase|null $targetPhase */
            $targetPhase = $phases->firstWhere('sequence', $targetSequence) ?: $phases->first();
            $firstPhase = $phases->first();
            if (! $targetPhase || ! $firstPhase) return;

            $phaseIds = $phases->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            // Archive every generated row that no longer belongs to the
            // published 7-stage definition. This runs even when workflow_id is
            // already correct; that is what removes stale old-stage tasks after
            // an interrupted/partial workflow migration.
            Task::query()
                ->where('flow_job_id', $job->id)
                ->whereNotNull('task_pack_task_id')
                ->whereNotIn('workflow_phase_id', $phaseIds)
                ->delete();

            foreach ($phases as $phase) {
                $allowed = $phase->taskPack?->items?->pluck('id')->map(fn ($id) => (int) $id)->values()->all() ?? [];
                $query = Task::query()
                    ->where('flow_job_id', $job->id)
                    ->where('workflow_phase_id', $phase->id)
                    ->whereNotNull('task_pack_task_id');
                if ($allowed) $query->whereNotIn('task_pack_task_id', $allowed);
                else $query->whereNotNull('task_pack_task_id');
                $query->delete();
            }

            $changedWorkflow = (int) $job->workflow_id !== (int) $workflow->id;
            $changedPhase = (int) $job->workflow_phase_id !== (int) $targetPhase->id;

            $job->update([
                'workflow_id' => (int) $workflow->id,
                'source_workflow_id' => (int) $workflow->id,
                'workflow_phase_id' => (int) $targetPhase->id,
                'source_workflow_phase_id' => (int) $targetPhase->id,
                'started_from_phase_id' => (int) $firstPhase->id,
            ]);

            // Close any old active history row and ensure the current published
            // phase has exactly one active runtime history row.
            FlowJobPhaseHistory::query()
                ->where('flow_job_id', $job->id)
                ->whereNotIn('workflow_phase_id', $phaseIds)
                ->whereNull('completed_at')
                ->update(['status' => 'replaced', 'completed_at' => now()]);

            FlowJobPhaseHistory::query()->updateOrCreate(
                ['flow_job_id' => $job->id, 'workflow_phase_id' => $targetPhase->id],
                [
                    'changed_by' => auth()->id() ?: $job->created_by,
                    'phase_owner_id' => $job->coordinator_id,
                    'target_date' => $job->delivery_date,
                    'health_override' => $job->health,
                    'status' => 'active',
                    'entered_at' => now(),
                    'completed_at' => null,
                ]
            );

            $fresh = $job->fresh();
            // Inject the exact dedicated phases so syncWorkflowTasks cannot
            // accidentally see historical legacy Workflow::phases rows.
            $fresh->load(['workflow']);
            $fresh->workflow->setRelation('phases', $phases);
            $fresh->setRelation('phase', $targetPhase);
            app(JobService::class)->syncWorkflowTasks($fresh, null, true);

            // A publish can replace legacy generated task identities. Repair
            // artwork evidence immediately, before the Order is rendered, so
            // Documents/links never disappear from a completed Artwork stage.
            app(OrderArtworkEvidenceService::class)->repair((int) $job->id);

            app(OrderTaskSequenceService::class)->synchronizeCurrentPhase($fresh->fresh(['phase']));

            if ($changedWorkflow || $changedPhase) {
                $job->activities()->create([
                    'user_id' => auth()->id() ?: $job->created_by,
                    'event' => 'job.order_workflow_rebound',
                    'description' => 'Order synchronized with the published seven-stage Order workflow.',
                ]);
            }
        }, 3);

        // Rebinding can replace phase/task identities while preserving the
        // workflow's business semantics. Invalidate only this Order's derived
        // summary so the next read is rebuilt from the synchronized runtime.
        app(\App\Services\Orders\OrderWorkflowSummaryService::class)->markStale($jobId);
    }

    private function targetSequence(FlowJob $job, int $stageCount): int
    {
        // Keep physical workflow rebinding and every operational read model on
        // one mapping contract. This prevents the maintenance sync from
        // re-introducing a different interpretation of historical stages than
        // Orders / My Tasks / All Tasks use.
        $sequence = OrderStageResolver::resolve(
            $job->phase?->name,
            $job->phase?->short_name,
            $job->phase?->sequence,
            (string) $job->status,
        )['sequence'];

        return max(1, min($stageCount, (int) $sequence));
    }
}
