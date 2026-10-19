<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryTaskService;

final class UpdateInquiryTask
{
    public function __construct(private readonly InquiryTaskService $inquiries)
    {
    }

    public function handle(InquiryTask $task, array $data, User $actor): InquiryTask
    {
        return $this->inquiries->updateTask($task, $data, $actor);
    }
}
