<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Models\User;
use App\Services\Inquiries\InquiryItemService;

final class UpdateInquiryItem
{
    public function __construct(private readonly InquiryItemService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, InquiryItem $item, string $field, mixed $value, User $actor): InquiryItem
    {
        return $this->inquiries->updateItem($inquiry, $item, $field, $value, $actor);
    }
}
