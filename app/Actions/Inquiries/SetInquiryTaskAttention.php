<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryTaskService;

final class SetInquiryTaskAttention
{
    public function __construct(private readonly InquiryTaskService $inquiries)
    {
    }

    public function handle(InquiryTask $task, string $reason, User $actor): InquiryTask
    {
        return $this->inquiries->setTaskAttentionReason($task, $reason, $actor);
    }
}
