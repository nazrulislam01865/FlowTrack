<?php

namespace App\Livewire\TeamPerformance;

use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Queries\Dashboard\DashboardTeamPerformanceQuery;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Services\FilterOptionService;
use Livewire\Attributes\Url;
use Livewire\Component;

class Report extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;

    private const TEAM_LAZY_BATCH = 8;

    #[Url(as: 'period', history: true, except: 'this_week')]
    public string $teamPeriod = 'this_week';

    #[Url(as: 'from', history: true, except: '')]
    public string $teamCustomFrom = '';

    #[Url(as: 'to', history: true, except: '')]
    public string $teamCustomTo = '';

    #[Url(as: 'client', history: true, except: '')]
    public string $clientFilter = '';

    #[Url(as: 'department', history: true, except: '')]
    public string $teamFilter = '';

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'sort', history: true, except: 'performance')]
    public string $sort = 'performance';

    public int $teamLimit = self::TEAM_LAZY_BATCH;

    public function mount(): void
    {
        if (!in_array($this->teamPeriod, ['this_week', 'this_month', 'last_30_days', 'custom'], true)) {
            $this->teamPeriod = 'this_week';
        }

        if (!in_array($this->sort, ['performance', 'workload', 'name'], true)) {
            $this->sort = 'performance';
        }
    }

    public function updatedTeamPeriod(string $period): void
    {
        if (!in_array($period, ['this_week', 'this_month', 'last_30_days', 'custom'], true)) {
            $this->teamPeriod = 'this_week';
        }

        if ($this->teamPeriod === 'custom' && ($this->teamCustomFrom === '' || $this->teamCustomTo === '')) {
            $today = app(\App\Services\WorkspaceSettingsService::class)->localToday();
            $this->teamCustomFrom = $today->copy()->startOfWeek()->toDateString();
            $this->teamCustomTo = $today->toDateString();
        }

        $this->resetTeamLazyLoad();
    }

    public function updatedTeamCustomFrom(): void
    {
        $this->resetTeamLazyLoad();
    }

    public function updatedTeamCustomTo(): void
    {
        $this->resetTeamLazyLoad();
    }

    public function updatedSearch(): void
    {
        $this->resetTeamLazyLoad();
    }

    public function updatedSort(string $sort): void
    {
        if (!in_array($sort, ['performance', 'workload', 'name'], true)) {
            $this->sort = 'performance';
        }

        $this->resetTeamLazyLoad();
    }

    public function setReportFilter(string $property, mixed $value): void
    {
        abort_unless(in_array($property, ['clientFilter', 'teamFilter'], true), 422, 'Unsupported report filter.');
        abort_unless(auth()->user()->canAccess('reports.view'), 403);

        $raw = trim((string) $value);
        if ($raw === '') {
            $this->{$property} = '';
            $this->resetTeamLazyLoad();
            return;
        }

        abort_unless(ctype_digit($raw), 422, 'Please choose a valid filter option.');
        $id = (int) $raw;
        $type = $property === 'clientFilter' ? 'clients' : 'departments';
        $selected = app(FilterOptionService::class)
            ->options(auth()->user(), $type, 'dashboard', '', $id, 20)
            ->first(fn ($item) => (string) ($item['id'] ?? '') === (string) $id);
        abort_unless($selected, 422, 'That filter option is no longer available.');

        $this->{$property} = (string) $id;
        $this->resetTeamLazyLoad();
    }

    public function clearFilters(): void
    {
        $this->clientFilter = '';
        $this->teamFilter = '';
        $this->search = '';
        $this->sort = 'performance';
        $this->resetTeamLazyLoad();
    }

    public function loadMoreTeamPerformance(): void
    {
        $this->teamLimit += self::TEAM_LAZY_BATCH;
    }

    private function resetTeamLazyLoad(): void
    {
        $this->teamLimit = self::TEAM_LAZY_BATCH;
    }

    public function render()
    {
        $user = auth()->user();
        $clientId = max(0, (int) $this->clientFilter);
        $departmentId = max(0, (int) $this->teamFilter);
        $query = mb_strtolower(trim($this->search));
        $teamQuery = app(DashboardTeamPerformanceQuery::class);

        $teamPerformance = $teamQuery->rows(
            $user,
            new DashboardFilterData($clientId, $departmentId),
            $this->teamPeriod,
            $this->teamCustomFrom ?: null,
            $this->teamCustomTo ?: null,
            $this->sort,
        )
            ->filter(fn ($row) => $departmentId <= 0 || (int) ($row->department_id ?? 0) === $departmentId)
            ->filter(function ($row) use ($query): bool {
                if ($query === '') return true;

                // The Team filter already handles departments. Keep the free
                // text search employee-specific so a search such as "ina"
                // does not also match everyone in "Finance Department".
                return str_contains(mb_strtolower((string) $row->name), $query);
            });


        $resultCount = $teamPerformance->count();
        $visibleLimit = min(
            max(self::TEAM_LAZY_BATCH, $this->teamLimit),
            max(self::TEAM_LAZY_BATCH, $resultCount),
        );
        $visibleTeamPerformance = $teamPerformance
            ->take($visibleLimit)
            ->values();
        $visibleCount = $visibleTeamPerformance->count();

        $filterOptions = app(FilterOptionService::class);

        return view('livewire.team-performance.report', [
            'assigneePerformance' => $visibleTeamPerformance,
            'teamReportingPeriod' => $teamQuery->reportingPeriod(
                $user,
                $this->teamPeriod,
                $this->teamCustomFrom ?: null,
                $this->teamCustomTo ?: null,
            ),
            'reportClientFilterOptions' => $filterOptions->options($user, 'clients', 'dashboard', '', $clientId ?: null, 6),
            'reportTeamFilterOptions' => $filterOptions->options($user, 'departments', 'dashboard', '', $departmentId ?: null, 6),
            'resultCount' => $resultCount,
            'visibleCount' => $visibleCount,
            'hasMoreTeamPerformance' => $visibleCount < $resultCount,
            'nextTeamBatchCount' => min(self::TEAM_LAZY_BATCH, max(0, $resultCount - $visibleCount)),
        ]);
    }
}
