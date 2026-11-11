<?php

namespace App\Livewire\Dashboard;

use App\Queries\Dashboard\DashboardCatalogueReadinessQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

final class CataloguePanel extends Panel
{
    // Range, Client, Team and Search do not affect catalogue readiness.
    protected function dashboardFiltersAreRelevant(): bool { return false; }

    protected function heading(): string { return 'Catalogue readiness'; }
    protected function placeholderHeight(): int { return 300; }

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        [$actor, $filters] = $this->authorizedFilters();
        try {
            return view('livewire.dashboard.catalogue-panel', [
                'catalogueReadiness' => app(DashboardCatalogueReadinessQuery::class)->handle($actor),
            ]);
        } catch (QueryException $exception) {
            return $this->failed($exception);
        }
    }
}
