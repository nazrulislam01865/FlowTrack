<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use App\Services\Inquiries\InquiryTaskService;

final class AppendInquiryTask
{
    public function __construct(private readonly InquiryTaskService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, array $data, User $actor): InquiryTask
    {
        return $this->inquiries->appendTask($inquiry, $data, $actor);
    }
}
