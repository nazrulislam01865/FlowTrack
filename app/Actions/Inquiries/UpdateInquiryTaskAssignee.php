<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryTaskService;

final class UpdateInquiryTaskAssignee
{
    public function __construct(private readonly InquiryTaskService $inquiries)
    {
    }

    public function handle(InquiryTask $task, ?int $assigneeId, User $actor): InquiryTask
    {
        return $this->inquiries->updateTaskAssignee($task, $assigneeId, $actor);
    }
}
