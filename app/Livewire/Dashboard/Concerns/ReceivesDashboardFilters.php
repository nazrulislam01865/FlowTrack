<?php

namespace App\Livewire\Dashboard\Concerns;

use Livewire\Attributes\On;

/**
 * Synchronize dashboard-owned filters without reactive-prop hydration.
 * A parent filter update notifies mounted panels; inactive lazy panels retain
 * their initial load behavior. No unrelated panel or dashboard query is run here.
 */
trait ReceivesDashboardFilters
{
    protected function dashboardFiltersAreRelevant(): bool
    {
        return true;
    }

    #[On('dashboard-filters-updated')]
    public function receiveDashboardFilters(
        mixed $rangeDays,
        mixed $clientFilter,
        mixed $teamFilter,
        mixed $search,
        mixed $filterGeneration,
    ): void {
        abort_unless(
            is_int($rangeDays) && in_array($rangeDays, [1, 7, 30], true)
            && is_string($clientFilter) && is_string($teamFilter) && is_string($search)
            && is_int($filterGeneration) && $filterGeneration >= 0,
            422,
        );

        // An older bundled response must not revert newer selected filters.
        if ($filterGeneration <= $this->filterGeneration || ! $this->dashboardFiltersAreRelevant()) {
            $this->skipRender();
            return;
        }

        $this->rangeDays = $rangeDays;
        $this->clientFilter = $clientFilter;
        $this->teamFilter = $teamFilter;
        $this->search = $search;
        $this->filterGeneration = $filterGeneration;
    }
}
