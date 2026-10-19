<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class ClearInquiryAttention
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, User $actor): Inquiry
    {
        return $this->inquiries->clearInquiryAttention($inquiry, $actor);
    }
}
