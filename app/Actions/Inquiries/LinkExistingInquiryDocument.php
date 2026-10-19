<?php

namespace App\Actions\Inquiries;

use App\Models\Document;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\User;
use App\Services\Inquiries\InquiryDocumentService;

final class LinkExistingInquiryDocument
{
    public function __construct(private readonly InquiryDocumentService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, Document $document, User $actor): InquiryDocument
    {
        return $this->inquiries->linkExistingDocument($inquiry, $document, $actor);
    }
}
