<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class UpdateInquiryStartedAt
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, ?string $value, User $actor): Inquiry
    {
        return $this->inquiries->updateStartedAt($inquiry, $value, $actor);
    }
}
