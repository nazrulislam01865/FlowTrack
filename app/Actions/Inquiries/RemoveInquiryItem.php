<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Models\User;
use App\Services\Inquiries\InquiryItemService;

final class RemoveInquiryItem
{
    public function __construct(private readonly InquiryItemService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, InquiryItem $item, User $actor): void
    {
        $this->inquiries->removeItem($inquiry, $item, $actor);
    }
}
