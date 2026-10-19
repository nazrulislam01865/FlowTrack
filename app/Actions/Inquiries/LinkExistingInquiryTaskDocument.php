<?php

namespace App\Actions\Inquiries;

use App\Models\Document;
use App\Models\InquiryDocument;
use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryDocumentService;

final class LinkExistingInquiryTaskDocument
{
    public function __construct(private readonly InquiryDocumentService $inquiries)
    {
    }

    public function handle(InquiryTask $task, Document $document, User $actor, ?string $note = null): InquiryDocument
    {
        return $this->inquiries->linkExistingDocumentToTask($task, $document, $actor, $note);
    }
}
