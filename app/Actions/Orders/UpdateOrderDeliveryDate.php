<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UpdateOrderDeliveryDate
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, ?string $date): FlowJob
    {
        $date = trim((string) $date);
        if ($date !== '') validator(['date' => $date], ['date' => ['date']])->validate();
        return $this->jobs->updateDeliveryDate($this->orders->detail($actor, $orderId), $date ?: null, $actor);
    }
}
