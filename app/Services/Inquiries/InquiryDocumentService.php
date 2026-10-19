<?php

namespace App\Services\Inquiries;

use App\Services\LegacyInquiryService;

final class InquiryDocumentService
{
    public function __construct(private readonly LegacyInquiryService $legacy)
    {
    }

    public function upload(mixed ...$arguments): mixed
    {
        return $this->legacy->upload(...$arguments);
    }

    public function linkExistingDocument(mixed ...$arguments): mixed
    {
        return $this->legacy->linkExistingDocument(...$arguments);
    }

    public function linkExistingDocumentToTask(mixed ...$arguments): mixed
    {
        return $this->legacy->linkExistingDocumentToTask(...$arguments);
    }

    public function addTaskLink(mixed ...$arguments): mixed
    {
        return $this->legacy->addTaskLink(...$arguments);
    }

    public function removeTaskLink(mixed ...$arguments): mixed
    {
        return $this->legacy->removeTaskLink(...$arguments);
    }

    public function removeDocument(mixed ...$arguments): mixed
    {
        return $this->legacy->removeDocument(...$arguments);
    }

    public function removeTaskDocument(mixed ...$arguments): mixed
    {
        return $this->legacy->removeTaskDocument(...$arguments);
    }

}
