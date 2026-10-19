<?php

namespace App\Queries\Orders;

use App\Models\FlowJob;
use App\Models\User;
use App\Services\Orders\OrderReadService;
use Illuminate\Support\Collection;

/**
 * Permission-scoped read boundary for Order detail screens.
 *
 * Phase 5 intentionally delegates to the proven JobService implementation so
 * authorization, workflow hydration and relation loading remain unchanged.
 */
final class VisibleOrderQuery
{
    public function __construct(private readonly OrderReadService $jobs)
    {
    }

    public function scoped(User $actor, int $orderId, array $with = [], array $columns = ['*']): FlowJob
    {
        return $this->jobs->visibleQuery($actor)
            ->with($with)
            ->select($columns)
            ->findOrFail($orderId);
    }

    public function base(User $actor, int $orderId): FlowJob
    {
        return $this->jobs->findVisibleBase($actor, $orderId);
    }

    public function detail(User $actor, int $orderId): FlowJob
    {
        return $this->jobs->findVisible($actor, $orderId);
    }

    public function loadTab(FlowJob $order, User $actor, string $tab): FlowJob
    {
        return $this->jobs->loadVisibleDetailTab($order, $actor, $tab);
    }

    public function loadOverviewShell(FlowJob $order, User $actor): FlowJob
    {
        return $this->jobs->loadVisibleOverviewShell($order, $actor);
    }

    public function loadOverviewSummary(FlowJob $order, User $actor): FlowJob
    {
        return $this->jobs->loadVisibleOverviewSummary($order, $actor);
    }

    public function loadOverviewProducts(FlowJob $order, User $actor): FlowJob
    {
        return $this->jobs->loadVisibleOverviewProducts($order, $actor);
    }

    public function loadOverviewWorkflow(FlowJob $order, User $actor): FlowJob
    {
        return $this->jobs->loadVisibleOverviewWorkflow($order, $actor);
    }

    public function loadOverviewDocuments(FlowJob $order): FlowJob
    {
        return $this->jobs->loadVisibleOverviewDocuments($order);
    }

    public function loadOverviewActivity(
        FlowJob $order,
        string $activityTab = 'all',
        int $page = 1,
        int $perPage = 10,
    ): FlowJob {
        return $this->jobs->loadVisibleOverviewActivity(
            $order,
            $activityTab,
            $page,
            $perPage,
        );
    }

    public function inquiryLinkResults(User $actor, FlowJob $order, string $search, int $limit = 8): Collection
    {
        return $this->jobs->inquiryLinkResults($actor, $order, $search, $limit);
    }
}
