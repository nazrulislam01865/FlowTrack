<?php

namespace App\Services\Orders;

use App\Models\FlowJob;
use App\Services\LegacyJobService;

final class OrderLifecycleService
{
    public function __construct(private readonly LegacyJobService $legacy)
    {
    }

    public function create(mixed ...$arguments): mixed
    {
        return $this->legacy->create(...$arguments);
    }

    public function linkSourceInquiry(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->linkSourceInquiry(...$arguments);
    }

    public function unlinkSourceInquiry(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->unlinkSourceInquiry(...$arguments);
    }

    public function setOrderAttentionReason(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->setOrderAttentionReason(...$arguments);
    }

    public function clearOrderAttention(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->clearOrderAttention(...$arguments);
    }

    public function updateDeliveryDate(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateDeliveryDate(...$arguments);
    }

    public function updateUrgencies(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateUrgencies(...$arguments);
    }

    public function updateShippingSelection(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateShippingSelection(...$arguments);
    }

    public function updateOwner(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateOwner(...$arguments);
    }

    public function updateCoordinator(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateCoordinator(...$arguments);
    }

    public function updatePriority(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updatePriority(...$arguments);
    }

    public function updateHealth(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateHealth(...$arguments);
    }

    public function deactivate(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->deactivate(...$arguments);
    }

    public function cancel(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->cancel(...$arguments);
    }

    public function delete(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->delete(...$arguments);
    }

    public function updateShippingDetails(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateShippingDetails(...$arguments);
    }

    public function updateOverviewDetails(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateOverviewDetails(...$arguments);
    }

    public function updateTextField(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateTextField(...$arguments);
    }

    public function updateFinanceField(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateFinanceField(...$arguments);
    }

    private function assertOrderActivityAllowed(array $arguments): void
    {
        $job = $arguments[0] ?? null;
        if ($job instanceof FlowJob) {
            app(OrderHoldService::class)->assertNotHeld($job);
        }
    }

}
