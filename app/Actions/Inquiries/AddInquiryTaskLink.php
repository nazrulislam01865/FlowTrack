<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\InquiryTaskLink;
use App\Models\User;
use App\Services\Inquiries\InquiryDocumentService;

final class AddInquiryTaskLink
{
    public function __construct(private readonly InquiryDocumentService $inquiries)
    {
    }

    public function handle(InquiryTask $task, string $url, User $actor): InquiryTaskLink
    {
        return $this->inquiries->addTaskLink($task, $url, $actor);
    }
}
