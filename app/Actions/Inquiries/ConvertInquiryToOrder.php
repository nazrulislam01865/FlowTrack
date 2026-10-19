<?php

namespace App\Actions\Inquiries;

use App\Models\FlowJob;
use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryLifecycleService;

final class ConvertInquiryToOrder
{
    public function __construct(private readonly InquiryLifecycleService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, User $actor): FlowJob
    {
        return $this->inquiries->convertToOrder($inquiry, $actor);
    }
}
