<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\MasterDataService;
use App\Services\WorkspaceSettingsService;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Json;
use Livewire\Attributes\Renderless;

trait ManagesInquiryTasks
{
    public function updateTaskStatusInline(int $taskId, string $status): array
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId);
        $saved = app(\App\Actions\Inquiries\UpdateInquiryTaskStatus::class)->handle($task, $status, auth()->user());
        $updatedInquiry = $saved->inquiry()->first(['id', 'status', 'started_at']);
        $inquiryStatus = (string) $updatedInquiry->status;
        $localizedStart = \App\Support\UserLocalTime::localize($updatedInquiry->started_at);
        $this->refreshInquiryListMetrics();

        // Attention is driven by the Inquiry Task Status configuration in Master Data.
        // Changing to a flagged status (for example Waiting) should only surface the
        // configured flag indicator in the task row. Do not interrupt the user with
        // the reason modal automatically; the modal remains available explicitly by
        // clicking the flag icon when a reason / @mention is actually needed.
        if (!(bool) $saved->needs_attention
            && (int) ($this->taskAttentionTaskId ?? 0) === (int) $saved->id) {
            $this->closeTaskAttentionReason();
        }

        return [
            'ok' => true,
            'status' => $saved->status,
            'completed' => $saved->completed_at !== null,
            'needsAttention' => (bool) $saved->needs_attention,
            'attentionReason' => (string) ($saved->attention_reason ?: ''),
            'statusColor' => app(MasterDataService::class)->displayColorFor('inquiry_task_status', (string) $saved->status),
            'inquiryStatus' => $inquiryStatus,
            'inquiryTone' => $this->tone($inquiryStatus),
            'inquiryColor' => app(\App\Queries\Inquiries\InquiryDetailQuery::class)->statusColor($inquiryStatus, (string) $saved->status),
            'inquiryStartValue' => $localizedStart?->format('Y-m-d\\TH:i') ?? '',
            'inquiryStartDisplay' => $localizedStart?->format('M j, Y · g:i A') ?? '—',
        ];
    }

    public function openTaskAttentionReason(int $taskId): void
    {
        $detailQuery = app(\App\Queries\Inquiries\InquiryDetailQuery::class);
        $task = $detailQuery->task(auth()->user(), $taskId);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 422);
        abort_unless($detailQuery->canEditTask(auth()->user(), $task), 403);
        abort_unless($task->needs_attention || $detailQuery->taskStatusNeedsAttention((string) $task->status), 422, 'This task does not require attention.');

        $this->resetValidation('taskAttentionReason');
        $this->taskAttentionTaskId = (int) $task->id;
        $this->taskAttentionReason = (string) ($task->attention_reason ?: '');
        $this->showTaskAttentionModal = true;
    }

    public function closeTaskAttentionReason(): void
    {
        $this->showTaskAttentionModal = false;
        $this->taskAttentionTaskId = null;
        $this->taskAttentionReason = '';
        $this->resetValidation('taskAttentionReason');
    }

    public function saveTaskAttentionReason(): void
    {
        $this->validate([
            'taskAttentionReason' => ['required', 'string', 'max:2000'],
        ], [
            'taskAttentionReason.required' => 'Write the reason why this task requires attention.',
        ]);

        abort_unless($this->taskAttentionTaskId, 422);
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), (int) $this->taskAttentionTaskId);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 422);

        app(\App\Actions\Inquiries\SetInquiryTaskAttention::class)->handle($task, $this->taskAttentionReason, auth()->user());
        $this->closeTaskAttentionReason();
        $this->refreshInquiryListMetrics();
        session()->flash('success', 'Attention reason saved and added to comments.');
    }

    #[Json]
    public function updateTaskDueInline(int $taskId, ?string $date): array
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId);
        $saved = app(\App\Actions\Inquiries\UpdateInquiryTaskDueDate::class)->handle($task, $date, auth()->user());
        return ['ok' => true, 'date' => $saved->due_date?->toDateString()];
    }

    #[Json]
    public function updateTaskAssigneeInline(int $taskId, mixed $assigneeId): array
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId);
        $assigneeId = $assigneeId === '' || $assigneeId === null ? null : (int) $assigneeId;
        $assignee = $assigneeId ? User::query()->where('is_active', true)->findOrFail($assigneeId) : null;

        // Assignee is intentionally editable even after the task is completed.
        // Use the dedicated field updater so completed_at/status are preserved.
        $saved = app(\App\Actions\Inquiries\UpdateInquiryTaskAssignee::class)->handle($task, $assigneeId, auth()->user());

        return [
            'ok' => true,
            'assigneeId' => $saved->assignee_id,
            'assigneeName' => $assignee?->name ?: 'Unassigned',
            'avatarUrl' => $assignee?->profileImageUrl(),
        ];
    }

    public function completeTaskInline(int $taskId): void
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId);
        app(\App\Actions\Inquiries\CompleteInquiryTask::class)->handle($task, auth()->user());
        $this->selectedTaskId = null;
        $this->refreshInquiryListMetrics();
        session()->flash('success', 'Inquiry task completed.');
    }

    public function openTask(int $taskId): void
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->taskDetail(auth()->user(), $taskId);
        abort_unless((int) $task->inquiry_id === (int) $this->selectedInquiryId, 404);
        $this->selectedTaskId = $taskId;
        $this->loadManagementOptions();
        $this->hydrateTaskEditor($task);
    }

    public function closeTask(): void
    {
        $this->selectedTaskId = null;
        $this->taskUpload = null;
        $this->taskComment = '';
    }

    public function saveTask(): void
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), (int) $this->selectedTaskId);
        $this->validate([
            'taskAssigneeId' => ['nullable', 'exists:users,id'],
            'taskDueDate' => ['nullable', 'date'],
            'taskStatus' => ['required', Rule::in(app(\App\Queries\Inquiries\InquiryWorkflowQuery::class)->openTaskStatusOptions((string) $task->status)->all())],
        ]);
        app(\App\Actions\Inquiries\UpdateInquiryTask::class)->handle($task, [
            'assignee_id' => $this->taskAssigneeId,
            'due_date' => $this->taskDueDate,
            'status' => $this->taskStatus,
        ], auth()->user());
        session()->flash('success', 'Task changes saved.');
    }

    public function completeTask(): void
    {
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), (int) $this->selectedTaskId);
        app(\App\Actions\Inquiries\CompleteInquiryTask::class)->handle($task, auth()->user());
        $this->selectedTaskId = null;
        $this->taskUpload = null;
        $this->refreshInquiryListMetrics();
        session()->flash('success', 'Inquiry task completed.');
    }

    public function openAddTaskForm(): void
    {
        $inquiry = $this->selectedInquiry();
        abort_unless(app(AccessControlService::class)->canCreateInquiryTask(auth()->user(), $inquiry), 403);
        abort_if($inquiry->result, 422, 'A completed Inquiry cannot receive another task.');

        $this->newTaskName = '';
        $this->newTaskDescription = '';
        $this->newTaskAssigneeId = auth()->id();
        $this->newTaskAssigneeLabel = (string) (auth()->user()?->name ?: 'Unassigned');
        $this->newTaskDueDate = app(WorkspaceSettingsService::class)->localToday()->addDays(3)->toDateString();
        $this->newTaskRequiresSubmission = false;
        $this->newTaskSubmissionLabel = '';
        $this->showAddTaskForm = true;
        $this->resetValidation();
    }

    public function cancelAddTask(): void
    {
        $this->showAddTaskForm = false;
        $this->newTaskName = '';
        $this->newTaskDescription = '';
        $this->newTaskAssigneeId = null;
        $this->newTaskAssigneeLabel = 'Unassigned';
        $this->newTaskDueDate = '';
        $this->newTaskRequiresSubmission = false;
        $this->newTaskSubmissionLabel = '';
        $this->resetValidation();
    }

    public function setInquiryAddTaskSelector(string $property, mixed $value): void
    {
        abort_unless($this->showAddTaskForm && $this->selectedInquiryId, 422, 'The Add Task form is not open.');
        abort_unless($property === 'newTaskAssigneeId', 422, 'Unsupported Add Task selector.');

        $inquiry = $this->selectedInquiry();
        abort_unless(app(AccessControlService::class)->canCreateInquiryTask(auth()->user(), $inquiry), 403);

        $raw = trim((string) $value);

        if ($raw === '') {
            $this->newTaskAssigneeId = null;
            $this->newTaskAssigneeLabel = 'Unassigned';
            $this->resetValidation('newTaskAssigneeId');
            return;
        }

        abort_unless(ctype_digit($raw), 422, 'Please choose a valid assignee.');
        $assigneeId = (int) $raw;

        // Validate against the exact same permission-aware remote user source
        // used by <x-ui.inline-remote-user>. This keeps the Add Task selector
        // and every other task-assignee picker on one source of truth.
        $page = app(\App\Services\FilterOptionService::class)->searchPage(
            auth()->user(),
            'users',
            'task-assignee',
            '',
            1,
            1,
            [(string) $assigneeId],
            ['parent_type' => 'inquiry', 'parent_id' => (int) $inquiry->id],
        );
        $selected = $page->selectedItems->first(
            fn (array $option): bool => (int) ($option['id'] ?? 0) === $assigneeId
        );
        abort_unless($selected, 422, 'That assignee is no longer available.');

        $this->newTaskAssigneeId = $assigneeId;
        $this->newTaskAssigneeLabel = (string) ($selected['label'] ?? 'Unassigned');
        $this->resetValidation('newTaskAssigneeId');
    }

    public function addInquiryTask(): void
    {
        abort_unless(app(AccessControlService::class)->canCreateInquiryTask(auth()->user(), $this->selectedInquiry()), 403);

        $data = $this->validate([
            'newTaskName' => ['required', 'string', 'max:255'],
            'newTaskDescription' => ['nullable', 'string', 'max:60000'],
            'newTaskAssigneeId' => ['nullable', 'exists:users,id'],
            'newTaskDueDate' => ['nullable', 'date'],
            'newTaskRequiresSubmission' => ['boolean'],
            'newTaskSubmissionLabel' => ['nullable', 'string', 'max:255'],
        ]);

        app(\App\Actions\Inquiries\AppendInquiryTask::class)->handle($this->selectedInquiry(), [
            'name' => $data['newTaskName'],
            'description' => $data['newTaskDescription'] ?? null,
            'assignee_id' => $data['newTaskAssigneeId'] ?? null,
            'due_date' => $data['newTaskDueDate'] ?? null,
            'requires_submission' => (bool) ($data['newTaskRequiresSubmission'] ?? false),
            'submission_label' => $data['newTaskSubmissionLabel'] ?? null,
        ], auth()->user());

        $this->cancelAddTask();
        $this->refreshInquiryListMetrics();
        session()->flash('success', 'Inquiry task added.');
    }

    private function loadUserOptions(): void
    {
        if ($this->userOptions !== []) return;

        $query = User::query()->where('is_active', true);
        $actor = auth()->user();
        $isCreator = $this->selectedInquiryId
            ? Inquiry::query()->whereKey($this->selectedInquiryId)->where('created_by', $actor->id)->exists()
            : false;
        if (! $isCreator && ! $actor->canModule('tasks', 'assign')) $query->whereKey($actor->id);

        $this->userOptions = $query
            ->orderBy('name')
            ->limit(250)
            ->get(['id', 'name'])
            ->map(fn (User $row) => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
    }

    private function hydrateTaskEditor(InquiryTask $task): void
    {
        $this->taskAssigneeId = $task->assignee_id;
        $this->taskDueDate = $task->due_date?->toDateString() ?: '';
        $this->taskStatus = (string) $task->status;
    }
}
