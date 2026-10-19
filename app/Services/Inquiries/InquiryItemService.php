<?php

namespace App\Services\Inquiries;

use App\Services\LegacyInquiryService;

final class InquiryItemService
{
    public function __construct(private readonly LegacyInquiryService $legacy)
    {
    }

    public function updateItem(mixed ...$arguments): mixed
    {
        return $this->legacy->updateItem(...$arguments);
    }

    public function addItem(mixed ...$arguments): mixed
    {
        return $this->legacy->addItem(...$arguments);
    }

    public function removeItem(mixed ...$arguments): mixed
    {
        return $this->legacy->removeItem(...$arguments);
    }

    public function replaceItems(mixed ...$arguments): mixed
    {
        return $this->legacy->replaceItems(...$arguments);
    }

}
