<?php

namespace App\Actions\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Queries\Orders\VisibleOrderQuery;
use App\Services\Orders\OrderLifecycleService;

final class UnlinkOrderInquiry
{
    public function __construct(
        private readonly VisibleOrderQuery $orders,
        private readonly OrderLifecycleService $jobs
    ) {}

    public function handle(User $actor, int $orderId, ?int $inquiryId = null): FlowJob
    {
        return $this->jobs->unlinkSourceInquiry($this->orders->base($actor, $orderId), $actor, $inquiryId);
    }
}
