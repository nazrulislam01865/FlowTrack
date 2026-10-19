<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class SetInquiryAttention
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, string $reason, User $actor): Inquiry
    {
        return $this->inquiries->setInquiryAttentionReason($inquiry, $reason, $actor);
    }
}
