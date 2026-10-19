<?php

namespace App\Services\Inquiries;

use App\Services\LegacyInquiryService;

final class InquiryActivityService
{
    public function __construct(private readonly LegacyInquiryService $legacy)
    {
    }

    public function addInquiryComment(mixed ...$arguments): mixed
    {
        return $this->legacy->addInquiryComment(...$arguments);
    }

    public function addTaskComment(mixed ...$arguments): mixed
    {
        return $this->legacy->addTaskComment(...$arguments);
    }


}
