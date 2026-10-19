<?php

namespace App\Services\Inquiries;

use App\Services\LegacyInquiryService;

final class InquiryTaskService
{
    public function __construct(private readonly LegacyInquiryService $legacy)
    {
    }

    public function updateTask(mixed ...$arguments): mixed
    {
        return $this->legacy->updateTask(...$arguments);
    }

    public function updateTaskStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->updateTaskStatus(...$arguments);
    }

    public function updateTaskDueDate(mixed ...$arguments): mixed
    {
        return $this->legacy->updateTaskDueDate(...$arguments);
    }

    public function updateTaskAssignee(mixed ...$arguments): mixed
    {
        return $this->legacy->updateTaskAssignee(...$arguments);
    }

    public function completeTask(mixed ...$arguments): mixed
    {
        return $this->legacy->completeTask(...$arguments);
    }

    public function setTaskAttentionReason(mixed ...$arguments): mixed
    {
        return $this->legacy->setTaskAttentionReason(...$arguments);
    }

    public function appendTask(mixed ...$arguments): mixed
    {
        return $this->legacy->appendTask(...$arguments);
    }


}
