<?php

namespace App\Services\Inquiries;

use App\Services\LegacyInquiryService;

final class InquiryReadService
{
    public const AUTO_COMPLETED_STATUS = LegacyInquiryService::AUTO_COMPLETED_STATUS;

    public function __construct(private readonly LegacyInquiryService $legacy)
    {
    }

    public function workspaceId(mixed ...$arguments): mixed
    {
        return $this->legacy->workspaceId(...$arguments);
    }

    public function inquiryStatusOptions(mixed ...$arguments): mixed
    {
        return $this->legacy->inquiryStatusOptions(...$arguments);
    }

    public function taskStatusRecords(mixed ...$arguments): mixed
    {
        return $this->legacy->taskStatusRecords(...$arguments);
    }

    public function taskStatusOptions(mixed ...$arguments): mixed
    {
        return $this->legacy->taskStatusOptions(...$arguments);
    }

    public function taskStatusRecord(mixed ...$arguments): mixed
    {
        return $this->legacy->taskStatusRecord(...$arguments);
    }

    public function autoInquiryStatusForTaskStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->autoInquiryStatusForTaskStatus(...$arguments);
    }

    public function taskStatusNeedsAttention(mixed ...$arguments): mixed
    {
        return $this->legacy->taskStatusNeedsAttention(...$arguments);
    }

    public function inquiryStatusColor(mixed ...$arguments): mixed
    {
        return $this->legacy->inquiryStatusColor(...$arguments);
    }




    public function openTaskStatusOptions(mixed ...$arguments): mixed
    {
        return $this->legacy->openTaskStatusOptions(...$arguments);
    }

    public function defaultTaskStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->defaultTaskStatus(...$arguments);
    }

    public function isWorkingTaskStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->isWorkingTaskStatus(...$arguments);
    }

    public function resumeTaskStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->resumeTaskStatus(...$arguments);
    }

    public function taskStatusFilterOptions(mixed ...$arguments): mixed
    {
        return $this->legacy->taskStatusFilterOptions(...$arguments);
    }

    public function defaultInquiryStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->defaultInquiryStatus(...$arguments);
    }

    public function visibleQuery(mixed ...$arguments): mixed
    {
        return $this->legacy->visibleQuery(...$arguments);
    }

    public function canEdit(mixed ...$arguments): mixed
    {
        return $this->legacy->canEdit(...$arguments);
    }

    public function canEditVisible(mixed ...$arguments): mixed
    {
        return $this->legacy->canEditVisible(...$arguments);
    }

    public function listQuery(mixed ...$arguments): mixed
    {
        return $this->legacy->listQuery(...$arguments);
    }

    public function paginate(mixed ...$arguments): mixed
    {
        return $this->legacy->paginate(...$arguments);
    }

    public function listRows(mixed ...$arguments): mixed
    {
        return $this->legacy->listRows(...$arguments);
    }

    public function metrics(mixed ...$arguments): mixed
    {
        return $this->legacy->metrics(...$arguments);
    }

    public function findVisible(mixed ...$arguments): mixed
    {
        return $this->legacy->findVisible(...$arguments);
    }

    public function workflowSummary(mixed ...$arguments): mixed
    {
        return $this->legacy->workflowSummary(...$arguments);
    }

    public function workflowRows(mixed ...$arguments): mixed
    {
        return $this->legacy->workflowRows(...$arguments);
    }

    public function taskPackOptions(mixed ...$arguments): mixed
    {
        return $this->legacy->taskPackOptions(...$arguments);
    }

    public function taskPackRows(mixed ...$arguments): mixed
    {
        return $this->legacy->taskPackRows(...$arguments);
    }

    public function documentsPage(mixed ...$arguments): mixed
    {
        return $this->legacy->documentsPage(...$arguments);
    }

    public function activityPage(mixed ...$arguments): mixed
    {
        return $this->legacy->activityPage(...$arguments);
    }

    public function findVisibleTask(mixed ...$arguments): mixed
    {
        return $this->legacy->findVisibleTask(...$arguments);
    }

    public function taskDetail(mixed ...$arguments): mixed
    {
        return $this->legacy->taskDetail(...$arguments);
    }

    public function isActiveTask(mixed ...$arguments): mixed
    {
        return $this->legacy->isActiveTask(...$arguments);
    }

    public function canEditTask(mixed ...$arguments): mixed
    {
        return $this->legacy->canEditTask(...$arguments);
    }

    public function myTaskGroups(mixed ...$arguments): mixed
    {
        return $this->legacy->myTaskGroups(...$arguments);
    }

    public function myTaskMetrics(mixed ...$arguments): mixed
    {
        return $this->legacy->myTaskMetrics(...$arguments);
    }

    public function openMyTaskCount(mixed ...$arguments): mixed
    {
        return $this->legacy->openMyTaskCount(...$arguments);
    }


    public function taskHasSubmissionEvidence(mixed ...$arguments): mixed
    {
        return $this->legacy->taskHasSubmissionEvidence(...$arguments);
    }
}
