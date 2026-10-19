<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryWorkflowService;

final class SaveInquiryWorkflow
{
    public function __construct(private readonly InquiryWorkflowService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, array $rows, User $actor): void
    {
        $this->inquiries->saveWorkflow($inquiry, $rows, $actor);
    }
}
