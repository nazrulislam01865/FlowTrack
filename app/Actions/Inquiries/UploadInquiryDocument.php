<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryDocumentService;
use Illuminate\Http\UploadedFile;

final class UploadInquiryDocument
{
    public function __construct(private readonly InquiryDocumentService $inquiries)
    {
    }

    public function handle(
        Inquiry $inquiry,
        UploadedFile $file,
        User $actor,
        ?InquiryTask $task = null,
        ?string $note = null,
        bool $completeRequiredTask = true,
    ): InquiryDocument {
        if ($completeRequiredTask) {
            return $this->inquiries->upload($inquiry, $file, $actor, $task, $note);
        }

        return $this->inquiries->upload($inquiry, $file, $actor, $task, $note, false);
    }
}
