<?php

namespace App\Services\Inquiries;

use App\Services\LegacyInquiryService;

final class InquiryLifecycleService
{
    public function __construct(private readonly LegacyInquiryService $legacy)
    {
    }

    public function delete(mixed ...$arguments): mixed
    {
        return $this->legacy->delete(...$arguments);
    }

    public function create(mixed ...$arguments): mixed
    {
        return $this->legacy->create(...$arguments);
    }

    public function updateFinanceField(mixed ...$arguments): mixed
    {
        return $this->legacy->updateFinanceField(...$arguments);
    }

    public function updateDetailField(mixed ...$arguments): mixed
    {
        return $this->legacy->updateDetailField(...$arguments);
    }

    public function updateStartedAt(mixed ...$arguments): mixed
    {
        return $this->legacy->updateStartedAt(...$arguments);
    }

    public function updateStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->updateStatus(...$arguments);
    }

    public function setInquiryAttentionReason(mixed ...$arguments): mixed
    {
        return $this->legacy->setInquiryAttentionReason(...$arguments);
    }

    public function clearInquiryAttention(mixed ...$arguments): mixed
    {
        return $this->legacy->clearInquiryAttention(...$arguments);
    }

    public function convertToOrder(mixed ...$arguments): mixed
    {
        return $this->legacy->convertToOrder(...$arguments);
    }

    public function markDead(mixed ...$arguments): mixed
    {
        return $this->legacy->markDead(...$arguments);
    }

    public function syncAutomaticStatus(mixed ...$arguments): mixed
    {
        return $this->legacy->syncAutomaticStatus(...$arguments);
    }

}
