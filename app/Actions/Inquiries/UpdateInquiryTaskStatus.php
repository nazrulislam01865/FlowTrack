<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryTaskService;

final class UpdateInquiryTaskStatus
{
    public function __construct(private readonly InquiryTaskService $inquiries)
    {
    }

    public function handle(InquiryTask $task, string $status, User $actor): InquiryTask
    {
        return $this->inquiries->updateTaskStatus($task, $status, $actor);
    }
}
