<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class UpdateInquiryDetailField
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, string $field, mixed $value, User $actor): Inquiry
    {
        return $this->inquiries->updateDetailField($inquiry, $field, $value, $actor);
    }
}
