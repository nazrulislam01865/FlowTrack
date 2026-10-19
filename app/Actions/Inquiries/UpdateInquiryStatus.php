<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class UpdateInquiryStatus
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, string $status, User $actor): Inquiry
    {
        return $this->inquiries->updateStatus($inquiry, $status, $actor);
    }
}
