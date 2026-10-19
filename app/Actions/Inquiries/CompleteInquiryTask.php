<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryTaskService;

final class CompleteInquiryTask
{
    public function __construct(private readonly InquiryTaskService $inquiries)
    {
    }

    public function handle(InquiryTask $task, User $actor): InquiryTask
    {
        return $this->inquiries->completeTask($task, $actor);
    }
}
