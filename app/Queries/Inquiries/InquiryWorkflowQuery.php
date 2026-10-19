<?php

namespace App\Queries\Inquiries;

use App\Services\Inquiries\InquiryReadService;
use Illuminate\Support\Collection;

/** Read-only workflow/task option boundary used by Inquiry UI orchestration. */
final class InquiryWorkflowQuery
{
    public function __construct(private readonly InquiryReadService $inquiries)
    {
    }

    public function summary(int $workflowId): array
    {
        return $this->inquiries->workflowSummary($workflowId);
    }

    public function rows(int $workflowId, ?string $baseDate = null): array
    {
        return $this->inquiries->workflowRows($workflowId, $baseDate);
    }

    public function taskPacks(): Collection
    {
        return $this->inquiries->taskPackOptions();
    }

    public function taskPackRows(int $taskPackId, ?string $baseDate, ?int $fallbackAssigneeId): array
    {
        return $this->inquiries->taskPackRows($taskPackId, $baseDate, $fallbackAssigneeId);
    }

    public function openTaskStatusOptions(?string $currentStatus = null): Collection
    {
        return $this->inquiries->openTaskStatusOptions($currentStatus);
    }

    public function taskHasSubmissionEvidence(\App\Models\InquiryTask $task): bool
    {
        return $this->inquiries->taskHasSubmissionEvidence($task);
    }

    public function autoInquiryStatusForTaskStatus(string $status): string
    {
        return $this->inquiries->autoInquiryStatusForTaskStatus($status);
    }

    public function defaultTaskStatus(): string
    {
        return $this->inquiries->defaultTaskStatus();
    }

    public function defaultInquiryStatus(): string
    {
        return $this->inquiries->defaultInquiryStatus();
    }

    public function isCompletionStatus(string $status): bool
    {
        return $this->inquiries->autoInquiryStatusForTaskStatus($status) === InquiryReadService::AUTO_COMPLETED_STATUS;
    }
}
