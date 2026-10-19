<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryDocumentService;

final class RemoveInquiryDocument
{
    public function __construct(private readonly InquiryDocumentService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, int $documentId, User $actor): void
    {
        $this->inquiries->removeDocument($inquiry, $documentId, $actor);
    }
}
