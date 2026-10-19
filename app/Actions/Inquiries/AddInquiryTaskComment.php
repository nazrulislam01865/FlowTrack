<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\InquiryTaskComment;
use App\Models\User;
use App\Services\Inquiries\InquiryActivityService;

final class AddInquiryTaskComment
{
    public function __construct(private readonly InquiryActivityService $inquiries)
    {
    }

    public function handle(InquiryTask $task, string $body, User $actor): InquiryTaskComment
    {
        return $this->inquiries->addTaskComment($task, $body, $actor);
    }
}
