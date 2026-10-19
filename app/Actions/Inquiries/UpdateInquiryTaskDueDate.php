<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryTaskService;

final class UpdateInquiryTaskDueDate
{
    public function __construct(private readonly InquiryTaskService $inquiries)
    {
    }

    public function handle(InquiryTask $task, ?string $date, User $actor): InquiryTask
    {
        return $this->inquiries->updateTaskDueDate($task, $date, $actor);
    }
}
