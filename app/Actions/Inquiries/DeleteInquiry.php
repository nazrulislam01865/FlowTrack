<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class DeleteInquiry
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, User $actor): void
    {
        $this->inquiries->delete($inquiry, $actor);
    }
}
