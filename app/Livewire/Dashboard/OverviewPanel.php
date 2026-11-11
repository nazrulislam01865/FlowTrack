<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Dashboard\Concerns\ReceivesDashboardFilters;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\User;
use App\Queries\Dashboard\DashboardSummaryQuery;
use App\Queries\Orders\OrderListQuery;
use App\Services\AccessControlService;
use App\Services\WorkspaceSettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** The essential Release A read: summary and seven scoped stage cards only. */
class OverviewPanel extends Component
{
    use RefreshesFromWorkspace;
    use ReceivesDashboardFilters;

    public int $rangeDays = 7;

    public string $clientFilter = '';

    public string $teamFilter = '';

    public string $search = '';

    public int $filterGeneration = 0;

    public bool $dashboardReady = false;

    public function loadDashboardPanel(): void
    {
        if ($this->dashboardReady) {
            $this->skipRender();
            return;
        }

        $this->dashboardReady = true;
    }

    public function placeholder(): View
    {
        return view('livewire.dashboard.overview-placeholder');
    }

    #[On('dashboard-scoped-refresh')]
    public function refreshDashboardPanel(): void
    {
        // Livewire re-renders only this mounted, authorized panel; no global
        // dashboard re-query and no mutation of local tab or pagination state.
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // Re-render only the essential panel. No full dashboard query.
    }

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        $actor = auth()->user();
        abort_unless($actor instanceof User && app(AccessControlService::class)->can($actor, 'dashboard', 'view'), 403);
        abort_unless(in_array($this->rangeDays, [1, 7, 30], true), 422);
        $clientId = $this->validatedOption($actor, $this->clientFilter, 'clients');
        $departmentId = $this->validatedOption($actor, $this->teamFilter, 'departments');
        $filters = new DashboardFilterData($clientId, $departmentId, $this->rangeDays, mb_strtolower(trim($this->search)));

        // Use the legacy summary query and dashboardStages unchanged for exact
        // calculation and stage-navigation parity, including updated_at scope.
        try {
            $metrics = app(DashboardSummaryQuery::class)->handle($actor, $filters);
            $stages = app(OrderListQuery::class)->dashboardStages($actor, $clientId, $departmentId, $this->rangeDays);
        } catch (\Illuminate\Database\QueryException $exception) {
            report($exception);
            return view('livewire.dashboard.overview-error');
        }
        $today = app(WorkspaceSettingsService::class)->localToday();
        $cutoff = $today->copy()->subDays(max(0, $this->rangeDays - 1))->startOfDay();

        return view('livewire.dashboard.overview-panel', [
            'metrics' => $metrics,
            'orderStages' => $stages,
            'orderStageNavigationQuery' => array_filter([
                'dashboard_scope' => 1,
                'dashboard_range' => $this->rangeDays,
                'date_from' => $cutoff->toDateString(),
                'date_to' => $today->toDateString(),
                'client' => $clientId > 0 ? $clientId : null,
                'dashboard_team' => $departmentId > 0 ? $departmentId : null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    private function validatedOption(User $actor, string $value, string $type): int
    {
        $value = trim($value);
        if ($value === '') return 0;
        abort_unless(ctype_digit($value) && (int) $value > 0, 422);
        $found = app(\App\Services\FilterOptionService::class)
            ->selectedOptions($actor, $type, 'dashboard', [$value])
            ->first(fn ($item) => (string) ($item['id'] ?? '') === $value);
        abort_unless($found, 422, 'That filter option is no longer available.');
        return (int) $value;
    }
}
