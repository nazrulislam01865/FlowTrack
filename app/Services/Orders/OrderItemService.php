<?php

namespace App\Services\Orders;

use App\Models\FlowJob;
use App\Services\LegacyJobService;

final class OrderItemService
{
    public function __construct(private readonly LegacyJobService $legacy)
    {
    }

    public function updateItemDetails(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateItemDetails(...$arguments);
    }

    public function updateItem(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->updateItem(...$arguments);
    }

    public function addItem(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->addItem(...$arguments);
    }

    public function removeItem(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->removeItem(...$arguments);
    }

    public function restoreItem(mixed ...$arguments): mixed
    {
        $this->assertOrderActivityAllowed($arguments);
        return $this->legacy->restoreItem(...$arguments);
    }

    private function assertOrderActivityAllowed(array $arguments): void
    {
        $job = $arguments[0] ?? null;
        if ($job instanceof FlowJob) {
            app(OrderHoldService::class)->assertNotHeld($job);
        }
    }

}
