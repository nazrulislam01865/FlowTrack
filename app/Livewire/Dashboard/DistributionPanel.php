<?php

namespace App\Livewire\Dashboard;

use App\Queries\Dashboard\DashboardDistributionQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

final class DistributionPanel extends Panel
{
    public string $flowTab = 'orders';
    public string $taskStatusTab = 'orders';
    protected function heading(): string { return 'Work moving through FlowTrack'; }
    protected function placeholderHeight(): int { return 300; }

    public function setFlowTab(string $tab): void
    {
        abort_unless(in_array($tab, ['orders', 'inquiries'], true), 422);
        $this->flowTab = $tab;
    }

    public function setTaskStatusTab(string $tab): void
    {
        abort_unless(in_array($tab, ['orders', 'inquiries'], true), 422);
        $this->taskStatusTab = $tab;
    }

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        [$actor, $filters] = $this->authorizedFilters();
        abort_unless(in_array($this->flowTab, ['orders', 'inquiries'], true), 422);
        abort_unless(in_array($this->taskStatusTab, ['orders', 'inquiries'], true), 422);
        try {
            return view('livewire.dashboard.distribution-panel', app(DashboardDistributionQuery::class)->handle($actor, $filters));
        } catch (QueryException $exception) {
            return $this->failed($exception);
        }
    }
}
