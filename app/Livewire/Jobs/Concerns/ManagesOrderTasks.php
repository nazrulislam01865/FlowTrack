<?php

namespace App\Livewire\Jobs\Concerns;

use App\Actions\Orders\DeleteOrderTask;
use App\Actions\Orders\AppendOrderTask;
use App\Actions\Orders\CompleteOrderPhase;
use App\Queries\Orders\VisibleOrderQuery;
use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\FlowTaskComment;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\JobService;
use App\Services\MasterDataService;
use App\Services\TaskService;
use App\Services\WorkspaceSettingsService;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;
use Throwable;

/**
 * Phase 5 Order UI workflow extracted from the legacy Jobs coordinator.
 *
 * Public method names and parent Livewire state are intentionally preserved so
 * existing Blade bindings, deep links, validation keys and realtime behavior do
 * not change during the incremental decomposition.
 */
trait ManagesOrderTasks
{
    #[Json]
    public function updateTaskAssigneeFromJob(int $taskId, mixed $assigneeId): array
    {
        $assignee = null;
        $updatedTask = null;
        $result = $this->persistInlineEdit('task assignee', function () use ($taskId, $assigneeId, &$assignee, &$updatedTask) {
            abort_unless($this->selectedJobId, 422);
            $actor = auth()->user();
            $task = Task::query()
                ->where('flow_job_id', $this->selectedJobId)
                ->with([
                    'assignee:id,name,profile_image_path',
                    'job.members',
                    'phase:id,sequence',
                    'setupTemplate',
                ])
                ->findOrFail($taskId);
            if ($assigneeId === 'supplier') {
                app(\App\Services\SupplierBridge\SupplierDefaultTaskAssigner::class)->assignExplicitly($task, $actor);
                $updatedTask = $task->refresh();
                return;
            }
            $assigneeId = $assigneeId === '' ? null : (int) $assigneeId;
            $assignee = $assigneeId ? User::where('is_active', true)->findOrFail($assigneeId) : null;
            $updatedTask = app(TaskService::class)->updateAssignee($task, $assignee, $actor);
            app(\App\Services\SupplierBridge\SupplierDefaultTaskAssigner::class)->clearForTask($updatedTask);
        });

        if (($result['ok'] ?? false) && $updatedTask) {
            $result = array_merge($result, $this->dispatchTaskAssigneeSync($updatedTask));
        }

        return $result;
    }

    #[Json]
    public function updateTaskDueDateFromJob(int $taskId, mixed $date): array
    {
        $updatedTask = null;
        $result = $this->persistInlineEdit('task due date', function () use ($taskId, $date, &$updatedTask) {
            abort_unless($this->selectedJobId, 422);
            $task = Task::where('flow_job_id', $this->selectedJobId)->findOrFail($taskId);
            $date = trim((string) $date);
            if ($date !== '') validator(['date' => $date], ['date' => ['date']])->validate();
            $updatedTask = app(TaskService::class)->updateDueDate($task, $date ?: null, auth()->user());
        });

        if (($result['ok'] ?? false) && $updatedTask) {
            $result = array_merge($result, $this->dispatchTaskAssigneeSync($updatedTask));
        }

        return $result;
    }

    #[Json]
    public function updateEstimatedDeliveryDateFromJob(int $taskId, mixed $date): array
    {
        $savedValue = '';
        $savedDisplay = '';

        $result = $this->persistInlineEdit('estimated delivery date', function () use ($taskId, $date, &$savedValue, &$savedDisplay) {
            abort_unless($this->selectedJobId, 422);

            $task = app(TaskService::class)->visibleQuery(auth()->user())
                ->with(['job', 'setupTemplate'])
                ->where('flow_job_id', $this->selectedJobId)
                ->findOrFail($taskId);
            abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

            $date = trim((string) $date);
            app(\App\Services\OrderWorkflowActionService::class)
                ->updateCompletedEstimatedDeliveryDate($task, auth()->user(), $date);

            $job = FlowJob::query()->findOrFail($task->flow_job_id);
            $savedValue = $job->estimated_delivery_date?->format('Y-m-d') ?? '';
            $savedDisplay = $job->estimated_delivery_date?->format('M j, Y') ?? 'Set date';
        });

        if (($result['ok'] ?? false) === true) {
            $result['value'] = $savedValue;
            $result['display'] = $savedDisplay;
        }

        return $result;
    }

    /**
     * Livewire hook for the file button shown on each Order Overview task row.
     * Each task owns its temporary upload slot so several rows can be used
     * independently without changing the selected Task detail state.
     */
    public function openAddOrderTaskForm(): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        abort_unless(app(AccessControlService::class)->canCreateJobTask($user, $job), 403);
        abort_if($job->completed_at || $job->status === 'Completed', 422, 'A completed Order cannot receive another task.');
        abort_if(in_array($job->status, JobService::INACTIVE_STATUSES, true), 422, 'An inactive Order cannot receive another task.');

        $this->newOrderTaskName = '';
        $this->newOrderTaskDescription = '';
        $this->newOrderTaskPhaseId = null;
        $this->newOrderTaskAssigneeId = $user->id;
        $this->newOrderTaskDueDate = app(WorkspaceSettingsService::class)->localToday()->addDays(3)->toDateString();
        $this->showAddOrderTaskForm = true;
        $this->resetValidation([
            'newOrderTaskName',
            'newOrderTaskDescription',
            'newOrderTaskPhaseId',
            'newOrderTaskAssigneeId',
            'newOrderTaskDueDate',
        ]);
    }

    public function cancelAddOrderTask(bool $resetValidation = true): void
    {
        $this->showAddOrderTaskForm = false;
        $this->newOrderTaskName = '';
        $this->newOrderTaskDescription = '';
        $this->newOrderTaskPhaseId = null;
        $this->newOrderTaskAssigneeId = null;
        $this->newOrderTaskDueDate = '';
        if ($resetValidation) {
            $this->resetValidation([
                'newOrderTaskName',
                'newOrderTaskDescription',
                'newOrderTaskPhaseId',
                'newOrderTaskAssigneeId',
                'newOrderTaskDueDate',
            ]);
        }
    }

    public function addOrderTask(?string $description = null): void
    {
        abort_unless($this->selectedJobId && $this->detailTab === 'overview', 422);

        // The rich-text editor is intentionally isolated from Livewire DOM morphs
        // so typing, formatting, paste and screenshot insertion remain stable in
        // the dynamically opened Add Task form. Accept its canonical value at
        // submit time, then run the normal server-side validation/persistence.
        if ($description !== null) {
            $this->newOrderTaskDescription = $description;
        }

        $user = auth()->user();
        $job = app(VisibleOrderQuery::class)->base($user, $this->selectedJobId);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        abort_unless(app(AccessControlService::class)->canCreateJobTask($user, $job), 403);

        $data = $this->validate([
            'newOrderTaskName' => ['required', 'string', 'max:255'],
            'newOrderTaskDescription' => ['nullable', 'string', 'max:60000'],
            'newOrderTaskPhaseId' => ['required', 'integer', 'exists:workflow_phases,id'],
            'newOrderTaskAssigneeId' => ['nullable', 'integer', 'exists:users,id'],
            'newOrderTaskDueDate' => ['nullable', 'date'],
        ]);

        if (filled($data['newOrderTaskAssigneeId'] ?? null)) {
            $allowedAssigneeIds = $this->userOptions($user)->pluck('id')->map(fn ($id) => (int) $id);
            abort_unless($allowedAssigneeIds->contains((int) $data['newOrderTaskAssigneeId']), 403);
        }

        app(AppendOrderTask::class)->handle($job, [
            'title' => $data['newOrderTaskName'],
            'description' => $data['newOrderTaskDescription'] ?? null,
            'workflow_phase_id' => (int) $data['newOrderTaskPhaseId'],
            'assignee_id' => $data['newOrderTaskAssigneeId'] ?? null,
            'due_date' => $data['newOrderTaskDueDate'] ?? null,
        ], $user);

        $phaseId = (int) $data['newOrderTaskPhaseId'];
        if ($phaseId > 0) {
            $this->expandedPhaseIds = array_values(array_unique([
                ...array_map('intval', $this->expandedPhaseIds),
                $phaseId,
            ]));
        }

        $this->cancelAddOrderTask();
        $this->dispatchOrderRuntimeRefresh();
        session()->flash('success', 'Order task added.');
    }

    #[Json]
    public function updateTaskStatusFromJob(int $taskId, mixed $status): array
    {
        $updatedTask = null;
        $result = $this->persistInlineEdit('task status', function () use ($taskId, $status, &$updatedTask) {
            abort_unless($this->selectedJobId, 422);
            $status = trim((string) $status);
            abort_if($status === '', 422, 'Task status is required.');

            $task = Task::where('flow_job_id', $this->selectedJobId)->findOrFail($taskId);
            $updatedTask = app(TaskService::class)->moveStatus($task, $status, auth()->user());
        });

        if (($result['ok'] ?? false) && $updatedTask) {
            $result = array_merge($result, $this->dispatchTaskAssigneeSync($updatedTask));
            $currentPhaseId = $this->syncOverviewWorkflowSelectionToCurrentPhase();
            if ($currentPhaseId && $currentPhaseId !== (int) $updatedTask->workflow_phase_id) {
                $result['refresh'] = true;
                $result['workflowPhaseId'] = $currentPhaseId;
            }
        }

        return $result;
    }

    public function completePhase(): void
    {
        if (!$this->selectedJobId) return;
        try {
            $job = app(CompleteOrderPhase::class)->handle(app(VisibleOrderQuery::class)->detail(auth()->user(), $this->selectedJobId), auth()->user());
            $this->expandedPhaseIds = $job->phase ? [(int) $job->phase->id] : [];
            $this->syncOverviewWorkflowSelectionToCurrentPhase();
            $this->dispatchOrderRuntimeRefresh();
            session()->flash('success', 'Phase completed and the next configured phase is active.');
        } catch (Throwable $e) {
            if (trim((string) $e->getMessage()) === \App\Services\Orders\OrderHoldService::BLOCKED_ACTIVITY_MESSAGE) {
                $this->dispatch('flowtrack:order-held-blocked', action: 'Complete phase');
                return;
            }

            $this->addError('phaseCompletion', $e->getMessage());
        }
    }

    public function openTask(int $id): void
    {
        // Preserve the original task-detail behavior: opening a task normally
        // exposes inline editing when the current user has edit permission.
        $this->openTaskWithMode($id, true);
    }

    public function viewTask(int $id): void
    {
        // Explicit View from the overview action menu remains read-only.
        $this->openTaskWithMode($id, false);
    }

    public function editTask(int $id): void
    {
        $this->openTaskWithMode($id, true);
    }

    private function openTaskWithMode(int $id, bool $editMode): void
    {
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($id);
        if ($this->selectedJobId && (int) $this->selectedJobId !== (int) $task->flow_job_id) {
            $this->overviewPhaseId = null;
            $this->lastOverviewWorkflowPhaseId = null;
        }
        if ($editMode) {
            abort_unless(app(AccessControlService::class)->canEditVisibleTask(auth()->user(), $task), 403);
        }

        $this->selectedJobId = (int) $task->flow_job_id;
        $this->selectedTaskId = $task->id;
        $this->resetTaskDetailProgressiveSections();
        $this->focusComment = null;
        $this->taskEditMode = $editMode;
        $this->resetOverviewTaskResourceUi();
        $this->taskDocumentUploads = [];
        $this->taskExistingDocumentId = null;
        $this->showTaskDocumentPicker = false;
        $this->loadTaskForm($id);
    }

    public function deleteTaskFromJob(int $id): void
    {
        abort_unless($this->selectedJobId, 422);
        app(DeleteOrderTask::class)->handle(auth()->user(), $this->selectedJobId, $id);
        $this->dispatchOrderRuntimeRefresh();

        if ((int) $this->selectedTaskId === $id) {
            $this->closeTask();
        }
    }

    public function closeTask(): void { $this->selectedTaskId = null; $this->focusComment = null; $this->taskEditMode = false; $this->taskComment = ''; $this->newChecklistItem = ''; $this->taskActivityTab = 'all'; $this->taskActivityPage = 1; $this->taskDocumentUploads = []; $this->taskExistingDocumentId = null; $this->showTaskDocumentPicker = false; $this->resetTaskDetailProgressiveSections(); }

    public function markTaskComplete(): void
    {
        abort_unless($this->selectedTaskId, 422);
        $this->resetErrorBag('taskCompletion');

        $task = app(TaskService::class)->visibleQuery(auth()->user())
            ->with(['documents','documentCategory','setupTemplate.documentCategory'])
            ->findOrFail($this->selectedTaskId);
        abort_unless(app(\App\Services\AccessControlService::class)->canEditTask(auth()->user(), $task), 403);

        $task = app(TaskService::class)->moveStatus($task, 'Completed', auth()->user());
        $this->dispatchTaskAssigneeSync($task);
        $currentPhaseId = $this->syncOverviewWorkflowSelectionToCurrentPhase();

        if ($currentPhaseId && $currentPhaseId !== (int) $task->workflow_phase_id) {
            // The completed task closed its stage. Drop the historical task
            // detail so the freshly-active stage (Billing after Shipment) is
            // immediately visible instead of leaving the user in Shipment.
            $this->closeTask();
        } else {
            $this->loadTaskForm($task->id);
        }

        session()->flash('success', 'Task marked complete.');
    }

    public function addTaskComment(): void
    {
        if (!$this->selectedTaskId || !app(\App\Services\RichTextService::class)->hasContent($this->taskComment)) return;
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
        app(TaskService::class)->addComment($task, $this->taskComment, auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);
        $this->taskComment = '';
        $this->taskActivityPage = 1;
    }

    public function deleteTaskComment(int $commentId): void
    {
        abort_unless($this->selectedTaskId, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
        app(\App\Services\TaskActivityModerationService::class)->deleteOrderTaskComment($task, $commentId, auth()->user());
        if ($this->focusComment === 'task-'.$commentId) $this->focusComment = null;
        $this->taskActivityPage = 1;
        session()->flash('success', 'Task comment deleted and the deletion was recorded in activity.');
    }

    public function deleteTaskActivity(int $activityId): void
    {
        abort_unless($this->selectedTaskId, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
        app(\App\Services\TaskActivityModerationService::class)->deleteOrderTaskActivity($task, $activityId, auth()->user());
        $this->taskActivityPage = 1;
        session()->flash('success', 'Task activity deleted and the deletion was recorded.');
    }

    public function setTaskActivityTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all','comments','history'], true), 422);
        $this->taskActivityTab = $tab;
        $this->taskActivityPage = 1;
    }

    public function setTaskActivityPage(int $page): void
    {
        $this->taskActivityPage = max(1, $page);
    }

    #[Renderless]
    public function updateSelectedTaskField(string $field, mixed $value): array
    {
        $labels = [
            'title' => 'task name',
            'assignee_id' => 'task assignee',
            'status' => 'task status',
            'priority' => 'task priority',
            'start_date' => 'task start date',
            'due_date' => 'task due date',
            'description' => 'task description',
        ];

        $updatedTask = null;
        $result = $this->persistInlineEdit($labels[$field] ?? 'task field', function () use ($field, $value, &$updatedTask) {
            abort_unless($this->selectedTaskId, 422);
            $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
            if ($field === 'assignee_id' && filled($value)) User::where('is_active', true)->findOrFail((int) $value);
            if (in_array($field, ['start_date','due_date'], true) && filled($value)) validator(['date'=>$value], ['date'=>['date']])->validate();
            $updatedTask = app(TaskService::class)->updateDetailField($task, $field, $value, auth()->user());

            if ($field === 'status') $this->taskStatus = (string) $updatedTask->status;
            if ($field === 'assignee_id') $this->taskAssigneeId = $updatedTask->assignee_id ? (int) $updatedTask->assignee_id : null;
        });

        if ($field === 'status' && ($result['ok'] ?? false) && $updatedTask) {
            $timezone = app(WorkspaceSettingsService::class)->displayTimezone();
            $completedLocal = $updatedTask->completed_at?->copy()->timezone($timezone);
            $this->dispatch('task-completion-updated',
                completedDate: $completedLocal?->format('M j, Y') ?? '—',
                completedTime: $completedLocal?->format('g:i A') ?? ''
            );

            $currentPhaseId = $this->syncOverviewWorkflowSelectionToCurrentPhase();
            if ($currentPhaseId && $currentPhaseId !== (int) $updatedTask->workflow_phase_id) {
                $result['refresh'] = true;
                $result['workflowPhaseId'] = $currentPhaseId;
                $this->closeTask();
            }
        }

        if ($field === 'assignee_id' && ($result['ok'] ?? false) && $updatedTask) {
            $updatedTask->loadMissing('assignee:id,name,profile_image_path');
            $result['avatarUrl'] = $updatedTask->assignee?->profileImageUrl();
        }

        if (($result['ok'] ?? false) && $updatedTask) {
            $result = array_merge($result, $this->dispatchTaskAssigneeSync($updatedTask));
        }

        if ($field === 'description' && ($result['ok'] ?? false) && $updatedTask) {
            $result['value'] = (string) ($updatedTask->description ?? '');
            $result['displayHtml'] = app(\App\Services\MentionService::class)
                ->render($result['value']);
        }

        return $result;
    }

    public function addTaskChecklistItem(): void
    {
        abort_unless($this->selectedTaskId, 422);
        $this->validate(['newChecklistItem'=>['required','string','max:255']]);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
        app(TaskService::class)->addChecklistItem($task, $this->newChecklistItem, auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);
        $this->newChecklistItem = '';
    }

    public function toggleTaskChecklistItem(int $itemId, bool $completed): void
    {
        abort_unless($this->selectedTaskId, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->with('checklistItems')->findOrFail($this->selectedTaskId);
        abort_unless(app(\App\Services\AccessControlService::class)->canEditTask(auth()->user(), $task), 403, 'Only the assigned person or an authorised administrator can complete checklist items.');
        $item = $task->checklistItems->firstWhere('id', $itemId);
        abort_unless($item, 404);
        app(TaskService::class)->toggleChecklistItem($task, $item, $completed, auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);
    }

    public function deleteTaskChecklistItem(int $itemId): void
    {
        abort_unless($this->selectedTaskId, 422);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->with('checklistItems')->findOrFail($this->selectedTaskId);
        $item = $task->checklistItems->firstWhere('id', $itemId);
        abort_unless($item, 404);
        app(TaskService::class)->deleteChecklistItem($task, $item, auth()->user());
        $this->dispatchTaskAssigneeSync($task->id);
    }

    public function setTaskFlag(string $flag): void
    {
        abort_unless($this->selectedTaskId, 422);

        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
        $flag = trim($flag);

        if ($flag !== '') {
            $allowed = app(MasterDataService::class)->active('order_task_flag')->pluck('name')->map(fn ($name) => trim((string) $name));
            $currentLegacyFlag = $task->needs_attention ? trim((string) $task->attention_reason) : '';
            abort_unless($allowed->contains($flag) || ($currentLegacyFlag !== '' && $currentLegacyFlag === $flag), 422, 'Select a valid Task Flag.');
        }

        $updated = app(TaskService::class)->setAttentionFlag($task, $flag !== '' ? $flag : null, auth()->user());
        $this->dispatchTaskAssigneeSync($updated);
        $this->taskAttention = (bool) $updated->needs_attention;
        $this->taskAttentionReason = (string) $updated->attention_reason;
    }

    public function toggleTaskAttention(): void
    {
        if (!$this->selectedTaskId) return;
        $task = app(TaskService::class)->visibleQuery(auth()->user())->with('orderTaskFlag:id,type,name,color,status,sort_order,metadata')->findOrFail($this->selectedTaskId);
        $flag = $task->needs_attention
            ? null
            : (app(\App\Services\TaskFlagService::class)->defaultActive()?->name ?: 'Management attention');
        $updated = app(TaskService::class)->setAttentionFlag($task, $flag, auth()->user());
        $this->dispatchTaskAssigneeSync($updated);
        $this->taskAttention = (bool) $updated->needs_attention;
        $this->taskAttentionReason = (string) $updated->attention_reason;
    }

    public function saveTask(): void
    {
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
        abort_unless(app(AccessControlService::class)->canEditTask(auth()->user(), $task), 403);
        $this->validate([
            'taskStatus' => ['required','string','max:50'],
            'taskAssigneeId' => ['nullable','exists:users,id'],
            'taskProgress' => ['required','integer','between:0,100'],
            'taskAttentionReason' => ['nullable','string','max:1000'],
        ]);
        $updatedTask = app(TaskService::class)->update($task, [
            'status' => $this->taskStatus,
            'assignee_id' => $this->taskAssigneeId,
            'progress' => $this->taskProgress,
            'attention_reason' => $this->taskAttentionReason,
        ], auth()->user());
        if (trim($this->taskComment) !== '') app(TaskService::class)->addComment($updatedTask, $this->taskComment, auth()->user());
        $this->syncOverviewWorkflowSelectionToCurrentPhase();
        session()->flash('success', 'Task update saved.');
        $this->selectedTaskId = null;
        $this->taskComment = '';
    }

    /** @return array{taskId:int, assigneeId:int|string, assigneeName:string, avatarUrl:string} */
    private function dispatchTaskAssigneeSync(Task|int $task): array
    {
        // TaskService mutation methods already return the canonical saved Task.
        // Re-query only when a caller supplies an id; otherwise keep the same
        // request-local model and load just the relation required by the payload.
        $task = $task instanceof Task ? $task : Task::query()->findOrFail($task);
        $task->loadMissing('assignee:id,name,profile_image_path', 'supplierAssignment');

        $payload = [
            'taskId' => (int) $task->id,
            'assigneeId' => $task->supplierAssignment ? 'supplier' : ($task->assignee_id ? (int) $task->assignee_id : ''),
            'assigneeName' => (string) ($task->supplierAssignment
                ? 'Supplier'.($task->assignee ? ' + '.$task->assignee->name : '')
                : ($task->assignee?->name ?: 'Unassigned')),
            'avatarUrl' => (string) ($task->assignee?->profileImageUrl() ?? ''),
        ];

        if ((int) $this->selectedTaskId === (int) $task->id) {
            $this->taskAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;
        }

        $this->dispatch(
            'task-assignee-updated',
            taskId: $payload['taskId'],
            assigneeId: $payload['assigneeId'],
            assigneeName: $payload['assigneeName'],
            avatarUrl: $payload['avatarUrl'],
        );

        // Workflow task actions run inside the isolated OrderWorkflowSection.
        // The summary cards live in the parent Jobs component, so without this
        // event their saved progress/next-action state stays stale until a full
        // browser refresh. Reuse the existing runtime refresh contract instead
        // of duplicating workflow/progress business logic in the UI.
        $this->dispatchOrderRuntimeRefresh();

        return $payload;
    }

    private function dispatchOrderRuntimeRefresh(): void
    {
        if (! $this->selectedJobId) return;

        $this->dispatch('order-runtime-refreshed', orderId: (int) $this->selectedJobId);
    }

    private function loadTaskForm(int $id): void
    {
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($id);
        $this->taskStatus = $task->status;
        $this->taskAssigneeId = $task->assignee_id;
        $this->taskProgress = $task->progress;
        $this->taskAttention = $task->needs_attention;
        $this->taskAttentionReason = (string) $task->attention_reason;
        $this->taskComment = '';
        $this->newChecklistItem = '';
        $this->taskActivityTab = 'all';
        $this->taskActivityPage = 1;
    }

    private function applyFocusedComment(): void
    {
        $focus = trim((string) $this->focusComment);
        if ($focus === '') return;

        if ($this->selectedTaskId && preg_match('/^task-(\d+)$/', $focus, $matches) === 1) {
            $comment = FlowTaskComment::query()
                ->where('flow_task_id', $this->selectedTaskId)
                ->find((int) $matches[1]);

            if (!$comment) {
                $this->focusComment = null;
                return;
            }

            $newerCount = FlowTaskComment::query()
                ->where('flow_task_id', $this->selectedTaskId)
                ->where(function ($query) use ($comment): void {
                    $query->where('created_at', '>', $comment->created_at)
                        ->orWhere(function ($sameTime) use ($comment): void {
                            $sameTime->where('created_at', $comment->created_at)->where('id', '>', $comment->id);
                        });
                })
                ->count();

            $this->taskActivityTab = 'comments';
            $this->taskActivityPage = intdiv($newerCount, 30) + 1;
            return;
        }

        if ($this->selectedJobId && !$this->selectedTaskId && preg_match('/^job-(\d+)$/', $focus, $matches) === 1) {
            $activity = Activity::query()
                ->where('subject_type', FlowJob::class)
                ->where('subject_id', $this->selectedJobId)
                ->where('event', 'job.comment')
                ->find((int) $matches[1]);

            if (!$activity) {
                $this->focusComment = null;
                return;
            }

            $newerCount = Activity::query()
                ->where('subject_type', FlowJob::class)
                ->where('subject_id', $this->selectedJobId)
                ->where('event', 'job.comment')
                ->where(function ($query) use ($activity): void {
                    $query->where('created_at', '>', $activity->created_at)
                        ->orWhere(function ($sameTime) use ($activity): void {
                            $sameTime->where('created_at', $activity->created_at)->where('id', '>', $activity->id);
                        });
                })
                ->count();

            $this->detailTab = 'overview';
            $this->jobActivityTab = 'comments';
            $this->jobActivityPage = intdiv($newerCount, 10) + 1;
            return;
        }

        $this->focusComment = null;
    }

}
