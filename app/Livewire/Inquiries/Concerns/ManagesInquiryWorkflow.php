<?php

namespace App\Livewire\Inquiries\Concerns;

use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Services\WorkspaceSettingsService;

trait ManagesInquiryWorkflow
{
    public function openWorkflowManager(): void
    {
        $inquiry = $this->selectedInquiry(['tasks.assignee:id,name']);
        $detailQuery = app(\App\Queries\Inquiries\InquiryDetailQuery::class);
        foreach ($inquiry->tasks->filter(fn (InquiryTask $task) => !$task->completed_at) as $openTask) {
            abort_unless($detailQuery->canEditTask(auth()->user(), $openTask), 403, 'You do not have permission to manage the full Inquiry taskflow.');
        }
        $activeId = $inquiry->tasks->first(fn (InquiryTask $task) => !$task->completed_at)?->id;
        $this->managerRows = $inquiry->tasks->map(fn (InquiryTask $task) => [
            'id' => (int) $task->id,
            'source_id' => $task->source_task_pack_item_id ? (int) $task->source_task_pack_item_id : null,
            'phase_id' => $task->source_workflow_phase_id ? (int) $task->source_workflow_phase_id : null,
            'name' => (string) $task->title,
            'description' => (string) ($task->description ?: ''),
            'assignee_id' => $task->assignee_id ? (int) $task->assignee_id : null,
            'setup_assignee_id' => $task->setup_assignee_id ? (int) $task->setup_assignee_id : null,
            'due_date' => $task->due_date?->toDateString() ?: '',
            'requires_submission' => (bool) $task->requires_submission,
            'submission_label' => (string) ($task->submission_label ?: ''),
            'state' => $task->completed_at ? 'completed' : ((int) $task->id === (int) $activeId ? 'active' : 'future'),
        ])->values()->all();
        $this->loadManagementOptions();
        $this->showWorkflowManager = true;
    }

    public function closeWorkflowManager(): void
    {
        $this->showWorkflowManager = false;
        $this->managerRows = [];
    }

    public function addManagerTask(): void
    {
        $row = $this->blankWorkflowRow();
        $row['state'] = 'future';
        $this->managerRows[] = $row;
    }

    public function appendManagerTemplate(): void
    {
        if (!$this->managerTemplateId) return;
        $inquiry = $this->selectedInquiry();
        $rows = app(\App\Queries\Inquiries\InquiryWorkflowQuery::class)->taskPackRows($this->managerTemplateId, $inquiry->received_date?->toDateString(), $inquiry->owner_id);
        foreach ($rows as $row) {
            $row['state'] = 'future';
            $this->managerRows[] = $row;
        }
    }

    public function removeManagerTask(int $index): void
    {
        $row = $this->managerRows[$index] ?? null;
        if (!$row || in_array($row['state'] ?? '', ['completed', 'active'], true)) return;
        array_splice($this->managerRows, $index, 1);
        $this->managerRows = array_values($this->managerRows);
    }

    public function moveManagerTask(int $index, int $direction): void
    {
        $target = $index + $direction;
        if ($target < 0 || $target >= count($this->managerRows)) return;
        $a = $this->managerRows[$index] ?? null;
        $b = $this->managerRows[$target] ?? null;
        if (!$a || !$b || ($a['state'] ?? '') !== 'future' || ($b['state'] ?? '') !== 'future') return;
        [$this->managerRows[$index], $this->managerRows[$target]] = [$this->managerRows[$target], $this->managerRows[$index]];
        $this->managerRows = array_values($this->managerRows);
    }

    public function saveWorkflow(): void
    {
        $this->validate([
            'managerRows' => ['required', 'array', 'min:1'],
            'managerRows.*.name' => ['required', 'string', 'max:255'],
            'managerRows.*.description' => ['nullable', 'string', 'max:60000'],
            'managerRows.*.assignee_id' => ['nullable', 'exists:users,id'],
            'managerRows.*.due_date' => ['nullable', 'date'],
            'managerRows.*.requires_submission' => ['boolean'],
            'managerRows.*.submission_label' => ['nullable', 'string', 'max:255'],
        ]);
        app(\App\Actions\Inquiries\SaveInquiryWorkflow::class)->handle($this->selectedInquiry(), $this->managerRows, auth()->user());
        $this->showWorkflowManager = false;
        $this->managerRows = [];
        $this->refreshInquiryListMetrics();
        session()->flash('success', 'Inquiry taskflow saved.');
    }

    private function loadManagementOptions(): void
    {
        $this->loadUserOptions();
        $this->taskPackOptions = app(\App\Queries\Inquiries\InquiryWorkflowQuery::class)->taskPacks()->map(fn ($pack) => ['id' => (int) $pack->id, 'name' => (string) $pack->name])->all();
        $this->managerTemplateId ??= $this->taskPackOptions[0]['id'] ?? null;
    }

    private function blankWorkflowRow(): array
    {
        return [
            'id' => null,
            'source_id' => null,
            'phase_id' => null,
            'name' => 'New Inquiry Task',
            'description' => 'Describe what must be completed for this task.',
            'assignee_id' => auth()->id(),
            'assignee_name' => (string) auth()->user()->name,
            'due_date' => app(WorkspaceSettingsService::class)->localToday()->addDays(3)->toDateString(),
            'requires_submission' => false,
            'submission_label' => '',
            'state' => 'future',
        ];
    }
}
