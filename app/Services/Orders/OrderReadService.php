<?php

namespace App\Services\Orders;

use App\Services\LegacyJobService;

final class OrderReadService
{
    public function __construct(private readonly LegacyJobService $legacy)
    {
    }

    public function visibleQuery(mixed ...$arguments): mixed
    {
        return $this->legacy->visibleQuery(...$arguments);
    }

    public function activeQuery(mixed ...$arguments): mixed
    {
        return $this->legacy->activeQuery(...$arguments);
    }

    public function filteredQuery(mixed ...$arguments): mixed
    {
        return $this->legacy->filteredQuery(...$arguments);
    }

    public function filteredIds(mixed ...$arguments): mixed
    {
        return $this->legacy->filteredIds(...$arguments);
    }

    public function paginate(mixed ...$arguments): mixed
    {
        return $this->legacy->paginate(...$arguments);
    }

    public function ordersListQuery(mixed ...$arguments): mixed
    {
        return $this->legacy->ordersListQuery(...$arguments);
    }

    public function paginateOrders(mixed ...$arguments): mixed
    {
        return $this->legacy->paginateOrders(...$arguments);
    }

    public function bulkImportNumber(mixed ...$arguments): mixed
    {
        return $this->legacy->bulkImportNumber(...$arguments);
    }

    public function summaryCounts(mixed ...$arguments): mixed
    {
        return $this->legacy->summaryCounts(...$arguments);
    }

    public function findVisibleBase(mixed ...$arguments): mixed
    {
        return $this->legacy->findVisibleBase(...$arguments);
    }

    public function inquiryLinkResults(mixed ...$arguments): mixed
    {
        return $this->legacy->inquiryLinkResults(...$arguments);
    }

    public function loadVisibleDetailTab(mixed ...$arguments): mixed
    {
        return $this->legacy->loadVisibleDetailTab(...$arguments);
    }

    public function loadVisibleOverviewShell(mixed ...$arguments): mixed
    {
        return $this->legacy->loadVisibleOverviewShell(...$arguments);
    }

    public function loadVisibleOverviewSummary(mixed ...$arguments): mixed
    {
        return $this->legacy->loadVisibleOverviewSummary(...$arguments);
    }

    public function loadVisibleOverviewProducts(mixed ...$arguments): mixed
    {
        return $this->legacy->loadVisibleOverviewProducts(...$arguments);
    }

    public function loadVisibleOverviewWorkflow(mixed ...$arguments): mixed
    {
        return $this->legacy->loadVisibleOverviewWorkflow(...$arguments);
    }

    public function loadVisibleOverviewDocuments(mixed ...$arguments): mixed
    {
        return $this->legacy->loadVisibleOverviewDocuments(...$arguments);
    }

    public function loadVisibleOverviewActivity(mixed ...$arguments): mixed
    {
        return $this->legacy->loadVisibleOverviewActivity(...$arguments);
    }

    public function findVisible(mixed ...$arguments): mixed
    {
        return $this->legacy->findVisible(...$arguments);
    }

}
