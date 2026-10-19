<?php

namespace App\Actions\Inquiries;

use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Models\User;
use App\Services\Inquiries\InquiryItemService;

final class AddInquiryItem
{
    public function __construct(private readonly InquiryItemService $inquiries)
    {
    }

    public function handle(Inquiry $inquiry, string $category, string $product, int $quantity, User $actor, ?float $unitPrice = null): InquiryItem
    {
        return $this->inquiries->addItem($inquiry, $category, $product, $quantity, $actor, $unitPrice);
    }
}
