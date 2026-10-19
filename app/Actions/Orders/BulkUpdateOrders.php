<?php

namespace App\Actions\Orders;

use App\Models\User;
use App\Services\Orders\OrderLifecycleService;
use App\Services\Orders\OrderReadService;

final class BulkUpdateOrders
{
    public function __construct(
        private readonly OrderReadService $reads,
        private readonly OrderLifecycleService $writes,
    ) {
    }

    public function handle(User $actor, array $orderIds, string $action): void
    {
        abort_unless(in_array($action, ['deactivate', 'cancel', 'delete'], true), 422);

        $ids = collect($orderIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return;

        $orders = $this->reads->visibleQuery($actor)->whereIn('id', $ids)->get();
        foreach ($orders as $order) {
            match ($action) {
                'deactivate' => $this->writes->deactivate($order, $actor),
                'cancel' => $this->writes->cancel($order, $actor),
                'delete' => $this->writes->delete($order, $actor),
            };
        }
    }
}
