<?php

namespace App\Services\Inquiries;

use App\Services\LegacyInquiryService;

final class InquiryWorkflowService
{
    public function __construct(private readonly LegacyInquiryService $legacy)
    {
    }

    public function saveWorkflow(mixed ...$arguments): mixed
    {
        return $this->legacy->saveWorkflow(...$arguments);
    }

}
