<?php

namespace App\Modules\OrderFlow\Application\Actions;

use App\Models\User;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ActivityLogger;
use App\Modules\OrderFlow\Business\Services\IdentifierGenerator;
use App\Modules\OrderFlow\Business\Services\ProgressCalculator;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\FlowTask;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Notifications\FlowTrackAlert;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateTaskAction
{
    public function __construct(
        private readonly IdentifierGenerator $identifiers,
        private readonly ActivityLogger $activityLogger,
        private readonly ProgressCalculator $progressCalculator,
        private readonly WorkflowAutomationService $automation,
    ) {}

    public function execute(FlowJob $job, array $data, User $actor): FlowTask
    {
        return DB::transaction(function () use ($job, $data, $actor) {
            $phase = isset($data['workflow_phase_id'])
                ? WorkflowPhase::query()->where('workflow_template_id', $job->workflow_template_id)->where('is_active', true)->findOrFail($data['workflow_phase_id'])
                : $job->currentPhase;

            $parent = $this->taskForJob($job, $data['parent_id'] ?? null, 'parent');
            $dependency = $this->taskForJob($job, $data['dependency_id'] ?? null, 'dependency');
            $assignee = $this->workspaceUser($job->workspace_id, $data['assignee_id'] ?? null);
            $priority = $this->priorityForWorkspace($job->workspace_id, $data['priority_id'] ?? null);
            $status = MasterRecord::query()
                ->where('workspace_id', $job->workspace_id)
                ->where('type', MasterDataType::TaskStatus->value)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereIn('code', ['READY', 'NS', 'NEW'])
                        ->orWhereIn('name', ['Ready', 'Not Started', 'New']);
                })
                ->orderByRaw("case
                    when upper(code) = 'READY' or upper(name) = 'READY' then 1
                    when upper(code) = 'NS' or upper(name) = 'NOT STARTED' then 2
                    else 3 end")
                ->first()
                ?? MasterRecord::query()
                    ->where('workspace_id', $job->workspace_id)
                    ->where('type', MasterDataType::TaskStatus->value)
                    ->where('status', 'active')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

            if (! $status) {
                throw new DomainException('Configure at least one active Task Status before creating tasks.');
            }

            if (! empty($data['attention_required']) && blank($data['blocker_reason'] ?? null)) {
                throw new DomainException('Add an attention or blocker reason when the task requires attention.');
            }

            $task = FlowTask::create([
                'flow_job_id' => $job->id,
                'workflow_phase_id' => $phase?->id,
                'parent_id' => $parent?->id,
                'dependency_id' => $dependency?->id,
                'created_by' => $actor->id,
                'assignee_id' => $assignee?->id,
                'task_status_id' => $status->id,
                'priority_id' => $priority?->id ?: $job->priority_id,
                'code' => $this->identifiers->task(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'start_rule' => $data['start_rule'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'attention_required' => (bool) ($data['attention_required'] ?? false),
                'blocker_reason' => $data['blocker_reason'] ?? null,
                'progress' => $this->progressCalculator->forTaskStatus($status),
                'sort_order' => ((int) $job->tasks()->where('workflow_phase_id', $phase?->id)->max('sort_order')) + 1,
            ]);

            foreach (collect($data['checklist'] ?? [])->filter()->values() as $index => $label) {
                $task->checklistItems()->create(['label' => $label, 'sort_order' => $index]);
            }

            $this->activityLogger->log(
                $job->workspace_id,
                $actor,
                $task,
                'task.created',
                "Task {$task->code} created for {$job->code}",
                ['assignee_id' => $task->assignee_id, 'workflow_phase_id' => $task->workflow_phase_id],
            );

            $this->progressCalculator->recalculateJob($job);
            $this->automation->synchronize($job, $actor, 'task_creation');

            if ($assignee && $assignee->id !== $actor->id) {
                $assignee->notify(new FlowTrackAlert([
                    'title' => 'New task assigned',
                    'text' => "{$task->code}: {$task->title}",
                    'type' => 'task',
                    'job' => $job->code,
                    'task_id' => $task->id,
                ]));
            }

            return $task->fresh(['job.client', 'phase', 'parent', 'dependency', 'creator', 'assignee', 'status', 'priority', 'checklistItems']);
        });
    }

    private function taskForJob(FlowJob $job, mixed $taskId, string $label): ?FlowTask
    {
        if (! $taskId) {
            return null;
        }

        $task = FlowTask::query()->where('flow_job_id', $job->id)->find($taskId);
        if (! $task) {
            throw new DomainException("The selected {$label} task does not belong to this Job.");
        }

        return $task;
    }

    private function workspaceUser(int $workspaceId, mixed $userId): ?User
    {
        if (! $userId) {
            return null;
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new DomainException('The selected assignee is not an active member of this workspace.');
        }

        return User::query()->findOrFail($userId);
    }

    private function priorityForWorkspace(int $workspaceId, mixed $priorityId): ?MasterRecord
    {
        if (! $priorityId) {
            return null;
        }

        $priority = MasterRecord::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', MasterDataType::Priority->value)
            ->where('status', 'active')
            ->find($priorityId);

        if (! $priority) {
            throw new DomainException('The selected priority is invalid for this workspace.');
        }

        return $priority;
    }
}
