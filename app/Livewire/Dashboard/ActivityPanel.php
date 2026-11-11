<?php

namespace App\Livewire\Dashboard;

use App\Queries\Dashboard\DashboardActivityQuery;
use App\Services\WorkspaceSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

final class ActivityPanel extends Panel
{
    public string $activityTab = 'all';
    protected function heading(): string { return 'Recent activity'; }
    protected function placeholderHeight(): int { return 320; }

    public function setActivityTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all', 'orders', 'inquiries', 'tasks'], true), 422);
        $this->activityTab = $tab;
    }

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        [$actor, $filters] = $this->authorizedFilters();
        abort_unless(in_array($this->activityTab, ['all', 'orders', 'inquiries', 'tasks'], true), 422);
        try {
            $rows = app(DashboardActivityQuery::class)->handle($actor, $filters);
        } catch (QueryException $exception) {
            return $this->failed($exception);
        }
        $today = app(WorkspaceSettingsService::class)->localToday();
        $cutoff = $today->copy()->subDays(max(0, $filters->rangeDays - 1))->startOfDay();
        $visible = collect($rows)
            ->filter(fn ($row) => !$row->created_at || $row->created_at->gte($cutoff))
            ->filter(fn ($row) => $this->activityTab === 'all' || (string) ($row->dashboard_kind ?? '') === $this->activityTab)
            ->filter(fn ($row) => $filters->clientId <= 0 || (int) ($row->dashboard_client_id ?? 0) === $filters->clientId)
            ->filter(fn ($row) => $filters->departmentId <= 0 || (int) ($row->dashboard_department_id ?? 0) === $filters->departmentId)
            ->filter(function ($row) use ($filters): bool {
                if ($filters->search === '') return true;
                $haystack = mb_strtolower(trim(implode(' ', array_filter([
                    (string) ($row->dashboard_title ?? ''),
                    (string) ($row->dashboard_detail ?? ''),
                    (string) ($row->event ?? ''),
                ]))));
                return str_contains($haystack, $filters->search);
            })->take(6)->values();
        return view('livewire.dashboard.activity-panel', ['recentActivity' => $visible]);
    }
}
