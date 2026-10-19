<?php

namespace App\Livewire\Inquiries\Concerns;

trait ManagesInquiryActivity
{
    public function addTaskComment(): void
    {
        $this->validate(['taskComment' => ['required', 'string', 'max:60000']]);
        $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), (int) $this->selectedTaskId, ['inquiry']);
        app(\App\Actions\Inquiries\AddInquiryTaskComment::class)->handle($task, trim($this->taskComment), auth()->user());
        $this->taskComment = '';
    }

    public function deleteInquiryTaskActivity(int $activityId): void
    {
        $inquiry = $this->selectedInquiry();
        app(\App\Services\TaskActivityModerationService::class)->deleteInquiryTaskActivity($inquiry, $activityId, auth()->user());
        $this->resetPage('inquiryActivityPage');
        session()->flash('success', 'Task activity deleted and the deletion was recorded.');
    }

    public function setInquiryActivityTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all', 'comments', 'history'], true), 422);
        $this->inquiryActivityTab = $tab;
        $this->resetPage('inquiryActivityPage');
    }

    public function addInquiryComment(): void
    {
        $this->validate(['inquiryComment' => ['required', 'string', 'max:60000']]);
        app(\App\Actions\Inquiries\AddInquiryComment::class)->handle($this->selectedInquiry(), trim($this->inquiryComment), auth()->user());
        $this->inquiryComment = '';
        $this->resetPage('inquiryActivityPage');
    }
}
