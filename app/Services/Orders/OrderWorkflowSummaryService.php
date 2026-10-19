<?php

namespace App\Services\Orders;

use App\Models\FlowJob;
use App\Models\OrderHold;
use App\Models\OrderWorkflowSummary;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Services\AccessControlService;
use App\Services\JobService;
use App\Services\OrderWorkflowSetupService;
use App\Support\OrderDetailPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Small materialized read model for the always-visible Order Details workflow
 * summary. It never owns workflow business rules: values are derived from the
 * existing FlowJob / WorkflowPhase / Task runtime and can always be rebuilt.
 */
final class OrderWorkflowSummaryService
{
    private ?bool $tableAvailable = null;

    /**
     * Summary used by the Order Details shell.
     *
     * Administrators, Order creators and users with all-task visibility can use
     * the shared materialized row. Restricted task scopes are calculated from a
     * bounded current-phase query so existing visibility semantics do not change.
     *
     * @return array<string,mixed>
     */
    public function forViewer(FlowJob $job, User $viewer): array
    {
        if (! $this->tableReady() || ! $this->canUseSharedSummary($job, $viewer)) {
            return $this->build($job, $viewer);
        }

        $summary = OrderWorkflowSummary::query()->where('flow_job_id', $job->id)->first();

        if (! $summary || $this->needsRefresh($summary, $job)) {
            $summary = $this->refresh($job);
        }

        return $this->toArray($summary, $job);
    }

    public function refresh(int|FlowJob $job): OrderWorkflowSummary
    {
        abort_unless($this->tableReady(), 500, 'Order workflow summary table is not available.');

        $order = $job instanceof FlowJob
            ? $job
            : FlowJob::query()->findOrFail($job);

        $data = $this->build($order, null);
        $now = now();
        $payload = [
            'flow_job_id' => (int) $order->id,
            'workflow_id' => $data['workflow_id'],
            'workflow_name' => $data['workflow_name'],
            'workflow_phase_id' => $data['workflow_phase_id'],
            'current_phase_name' => $data['current_phase_name'],
            'current_phase_short_name' => $data['current_phase_short_name'],
            'current_phase_sequence' => $data['current_phase_sequence'],
            'stage_count' => $data['stage_count'],
            'current_task_count' => $data['current_task_count'],
            'current_applicable_task_count' => $data['current_applicable_task_count'],
            'current_completed_task_count' => $data['current_completed_task_count'],
            'next_task_id' => $data['next_task_id'],
            'next_task_title' => $data['next_task_title'],
            'next_task_assignee_id' => $data['next_task_assignee_id'],
            'progress_percent' => $data['progress_percent'],
            'order_status' => $data['order_status'],
            'is_on_hold' => $data['is_on_hold'],
            'is_stale' => false,
            'refreshed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Atomic upsert avoids a duplicate-key race if two Livewire requests
        // open a newly-created Order before its first summary row exists.
        OrderWorkflowSummary::query()->upsert(
            [$payload],
            ['flow_job_id'],
            [
                'workflow_id', 'workflow_name', 'workflow_phase_id',
                'current_phase_name', 'current_phase_short_name', 'current_phase_sequence',
                'stage_count', 'current_task_count', 'current_applicable_task_count',
                'current_completed_task_count', 'next_task_id', 'next_task_title',
                'next_task_assignee_id', 'progress_percent', 'order_status',
                'is_on_hold', 'is_stale', 'refreshed_at', 'updated_at',
            ],
        );

        return OrderWorkflowSummary::query()->where('flow_job_id', (int) $order->id)->firstOrFail();
    }

    public function markStale(int $orderId): void
    {
        if (! $this->tableReady()) return;

        OrderWorkflowSummary::query()
            ->where('flow_job_id', $orderId)
            ->update(['is_stale' => true]);
    }

    public function markStaleForTaskPackItem(int $taskPackItemId): void
    {
        if (! $this->tableReady()) return;

        OrderWorkflowSummary::query()
            ->whereIn('flow_job_id', Task::query()
                ->select('flow_job_id')
                ->where('task_pack_task_id', $taskPackItemId)
                ->whereNotNull('flow_job_id'))
            ->update(['is_stale' => true]);
    }

    public function markStaleForWorkflow(int $workflowId): void
    {
        if (! $this->tableReady() || $workflowId <= 0) return;

        OrderWorkflowSummary::query()
            ->where('workflow_id', $workflowId)
            ->update(['is_stale' => true]);
    }

    public function updateHoldState(int $orderId, bool $isOnHold): void
    {
        if (! $this->tableReady()) return;

        OrderWorkflowSummary::query()
            ->where('flow_job_id', $orderId)
            ->update([
                'is_on_hold' => $isOnHold,
                'updated_at' => now(),
            ]);
    }

    /**
     * Keep a cached next-action assignee current after a claim/comment action
     * without rebuilding the whole summary. If the changed task is not the
     * cached next task, nothing is written.
     */
    public function updateNextTaskAssignee(Task $task): void
    {
        if (! $this->tableReady()) return;

        OrderWorkflowSummary::query()
            ->where('flow_job_id', (int) $task->flow_job_id)
            ->where('next_task_id', (int) $task->id)
            ->update([
                'next_task_assignee_id' => $task->assignee_id ? (int) $task->assignee_id : null,
                'updated_at' => now(),
            ]);
    }

    public function tableReady(): bool
    {
        return $this->tableAvailable ??= Schema::hasTable('order_workflow_summaries');
    }

    /** @return array<string,mixed> */
    private function build(FlowJob $job, ?User $viewer): array
    {
        $job->refresh();

        $phase = WorkflowPhase::query()
            ->select([
                'id', 'workflow_id', 'workflow_template_id', 'task_pack_id',
                'sequence', 'name', 'short_name', 'is_active', 'color',
            ])
            ->with(['taskPack.items' => fn ($query) => $query
                ->select(['id', 'task_pack_id', 'is_required', 'sort_order', 'automation_key'])
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->find($job->workflow_phase_id);

        $activeOrder = ! $job->completed_at
            && ! in_array((string) $job->status, JobService::INACTIVE_STATUSES, true);

        // Match the existing Overview-shell rule exactly: active Orders that
        // belong to an active reusable Order workflow count the published
        // workflow_template_id stages. Historical/inactive Orders continue to
        // use their saved runtime workflow_id rows.
        $usesPublishedPhases = $activeOrder
            && (int) ($job->workflow_id ?: 0) > 0
            && OrderWorkflowSetupService::orderWorkflowQuery()
                ->whereKey((int) $job->workflow_id)
                ->where('is_active', true)
                ->exists();

        $stageCount = $job->workflow_id
            ? WorkflowPhase::query()
                ->where('is_active', true)
                ->when(
                    $usesPublishedPhases,
                    fn (Builder $query) => $query->where('workflow_template_id', (int) $job->workflow_id),
                    fn (Builder $query) => $query->where('workflow_id', (int) $job->workflow_id),
                )
                ->count()
            : 0;

        $taskQuery = Task::query()
            ->where('flow_job_id', (int) $job->id)
            ->where('workflow_phase_id', (int) ($job->workflow_phase_id ?: 0))
            ->select([
                'id', 'flow_job_id', 'workflow_phase_id', 'task_pack_task_id',
                'assignee_id', 'title', 'status', 'progress', 'completed_at',
            ])
            ->with([
                'assignee:id,name,profile_image_path',
                'setupTemplate:id,task_pack_id,is_required,sort_order,automation_key',
                'template:id,task_pack_id,title,is_required,sequence',
                'documents:id,task_id',
                'links:id,task_id,url,created_at',
            ]);

        if ($viewer) {
            app(AccessControlService::class)->applyTaskScope($taskQuery, $viewer);
        }

        $tasks = $taskQuery->get();

        $working = clone $job;
        $working->setRelation('phase', $phase);
        $working->setRelation('tasks', $tasks);

        $currentTasks = $phase ? OrderDetailPresenter::currentTasks($working) : collect();
        $nextTask = OrderDetailPresenter::nextTask($working);
        $applicableTasks = $currentTasks
            ->reject(fn (Task $task) => OrderDetailPresenter::isSkippedTask($task))
            ->values();

        $workflowName = null;
        if ($job->workflow_id) {
            $workflowName = \App\Models\Workflow::query()
                ->whereKey((int) $job->workflow_id)
                ->value('name');
        }

        return [
            'workflow_id' => $job->workflow_id ? (int) $job->workflow_id : null,
            'workflow_name' => $workflowName ?: 'FlowTrack Order Workflow',
            'workflow_phase_id' => $job->workflow_phase_id ? (int) $job->workflow_phase_id : null,
            'current_phase_name' => (string) ($phase?->name ?: ''),
            'current_phase_short_name' => (string) ($phase?->short_name ?: ''),
            'current_phase_sequence' => max(1, (int) ($phase?->sequence ?? 1)),
            'stage_count' => max(0, (int) $stageCount),
            'current_task_count' => (int) $currentTasks->count(),
            'current_applicable_task_count' => (int) $applicableTasks->count(),
            'current_completed_task_count' => OrderDetailPresenter::completedCount($currentTasks),
            'next_task_id' => $nextTask?->id ? (int) $nextTask->id : null,
            'next_task_title' => $nextTask?->title ? (string) $nextTask->title : null,
            'next_task_assignee_id' => $nextTask?->assignee_id ? (int) $nextTask->assignee_id : null,
            'next_task_assignee_name' => (string) ($nextTask?->assignee?->name ?: ''),
            'progress_percent' => max(0, min(100, (int) ($job->progress ?? 0))),
            'order_status' => (string) ($job->status ?? ''),
            'is_on_hold' => $this->isHeld($job),
        ];
    }

    private function needsRefresh(OrderWorkflowSummary $summary, FlowJob $job): bool
    {
        if ($summary->is_stale) return true;
        if ((int) ($summary->workflow_id ?: 0) !== (int) ($job->workflow_id ?: 0)) return true;
        if ((int) ($summary->workflow_phase_id ?: 0) !== (int) ($job->workflow_phase_id ?: 0)) return true;
        if ((int) $summary->progress_percent !== max(0, min(100, (int) ($job->progress ?? 0)))) return true;
        if ((string) ($summary->order_status ?? '') !== (string) ($job->status ?? '')) return true;
        if ($job->relationLoaded('activeHold') && (bool) $summary->is_on_hold !== (bool) $job->activeHold) return true;

        return false;
    }

    private function canUseSharedSummary(FlowJob $job, User $viewer): bool
    {
        $access = app(AccessControlService::class);

        if ($access->isAdministrator($viewer) || $access->isJobCreator($viewer, $job)) {
            return true;
        }

        return in_array('all_records', $access->scopes($viewer, 'tasks'), true);
    }

    /** @return array<string,mixed> */
    private function toArray(OrderWorkflowSummary $summary, FlowJob $job): array
    {
        $assigneeName = '';
        if ($summary->next_task_assignee_id) {
            $assigneeName = (string) (User::query()
                ->whereKey((int) $summary->next_task_assignee_id)
                ->value('name') ?: '');
        }

        return [
            'workflow_id' => $summary->workflow_id ? (int) $summary->workflow_id : null,
            'workflow_name' => (string) ($summary->workflow_name ?: 'FlowTrack Order Workflow'),
            'workflow_phase_id' => $summary->workflow_phase_id ? (int) $summary->workflow_phase_id : null,
            'current_phase_name' => (string) ($summary->current_phase_name ?: ''),
            'current_phase_short_name' => (string) ($summary->current_phase_short_name ?: ''),
            'current_phase_sequence' => max(1, (int) $summary->current_phase_sequence),
            'stage_count' => max(0, (int) $summary->stage_count),
            'current_task_count' => max(0, (int) $summary->current_task_count),
            'current_applicable_task_count' => max(0, (int) $summary->current_applicable_task_count),
            'current_completed_task_count' => max(0, (int) $summary->current_completed_task_count),
            'next_task_id' => $summary->next_task_id ? (int) $summary->next_task_id : null,
            'next_task_title' => $summary->next_task_title ? (string) $summary->next_task_title : null,
            'next_task_assignee_id' => $summary->next_task_assignee_id ? (int) $summary->next_task_assignee_id : null,
            'next_task_assignee_name' => $assigneeName,
            'progress_percent' => max(0, min(100, (int) ($job->progress ?? $summary->progress_percent))),
            'order_status' => (string) ($job->status ?? $summary->order_status ?? ''),
            'is_on_hold' => (bool) $summary->is_on_hold,
        ];
    }

    private function isHeld(FlowJob $job): bool
    {
        if ($job->relationLoaded('activeHold')) {
            return (bool) $job->activeHold;
        }

        return OrderHold::query()
            ->where('flow_job_id', (int) $job->id)
            ->whereNull('ended_at')
            ->exists();
    }
}
