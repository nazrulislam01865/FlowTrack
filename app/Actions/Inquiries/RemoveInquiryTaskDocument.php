<?php

namespace App\Actions\Inquiries;

use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryDocumentService;

final class RemoveInquiryTaskDocument
{
    public function __construct(private readonly InquiryDocumentService $inquiries)
    {
    }

    public function handle(InquiryTask $task, int $documentId, User $actor): bool
    {
        return $this->inquiries->removeTaskDocument($task, $documentId, $actor);
    }
}
