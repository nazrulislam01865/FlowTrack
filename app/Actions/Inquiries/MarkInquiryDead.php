<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class MarkInquiryDead
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, string $reason, ?string $note, User $actor): Inquiry
    {
        return $this->inquiries->markDead($inquiry, $reason, $note, $actor);
    }
}
