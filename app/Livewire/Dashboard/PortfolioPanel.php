<?php

namespace App\Livewire\Dashboard;

use App\Queries\Dashboard\DashboardClientPortfolioQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

final class PortfolioPanel extends Panel
{
    protected function heading(): string { return 'Client portfolio'; }
    protected function placeholderHeight(): int { return 300; }

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        [$actor, $filters] = $this->authorizedFilters();
        try {
            $rows = app(DashboardClientPortfolioQuery::class)->handle($actor, $filters);
        } catch (QueryException $exception) {
            return $this->failed($exception);
        }
        return view('livewire.dashboard.portfolio-panel', [
            'clientPortfolio' => collect($rows)
                ->filter(fn ($row) => $filters->clientId <= 0 || (int) $row->id === $filters->clientId)
                ->filter(fn ($row) => $filters->search === '' || str_contains(mb_strtolower((string) $row->name), $filters->search))
                ->take(4)->values(),
        ]);
    }
}
