<?php

namespace App\Actions\Inquiries;

use App\Models\Activity;
use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryActivityService;

final class AddInquiryComment
{
    public function __construct(private readonly InquiryActivityService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, string $body, User $actor): Activity
    {
        return $this->inquiries->addInquiryComment($inquiry, $body, $actor);
    }
}
