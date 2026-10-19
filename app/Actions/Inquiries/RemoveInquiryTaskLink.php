<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryDocumentService;

final class RemoveInquiryTaskLink
{
    public function __construct(private readonly InquiryDocumentService $inquiries)
    {
    }

    public function handle(InquiryTask $task, int $linkId, User $actor): bool
    {
        return $this->inquiries->removeTaskLink($task, $linkId, $actor);
    }
}
