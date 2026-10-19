<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\User;
use App\Services\Inquiries\InquiryItemService;

final class ReplaceInquiryItems
{
    public function __construct(private readonly InquiryItemService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, array $items, User $actor): Inquiry
    {
        return $this->inquiries->replaceItems($inquiry, $items, $actor);
    }
}
