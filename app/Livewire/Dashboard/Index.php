<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Services\AccessControlService;
use App\DTOs\Dashboard\DashboardFilterData;
use App\Queries\Dashboard\DashboardPrimaryQuery;
use App\Queries\Orders\OrderListQuery;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;

    public int $rangeDays = 7;
    public string $clientFilter = '';
    public string $teamFilter = '';
    public string $search = '';
    public string $flowTab = 'orders';
    public string $priorityTab = 'orders';
    public string $taskStatusTab = 'orders';
    public string $activityTab = 'all';
    public string $attentionTab = 'all';
    public int $priorityPage = 1;

    private const PRIORITY_PER_PAGE = 5;

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // Re-render the management dashboard when realtime state changes.
        // Reset Priority Work so realtime removals cannot leave the user on an empty page.
        $this->priorityPage = 1;
    }

    public function setRange(int $days): void
    {
        abort_unless(in_array($days, [1, 7, 30], true), 422);
        $this->rangeDays = $days;
        $this->priorityPage = 1;
    }

    public function updatedClientFilter(): void
    {
        $this->priorityPage = 1;
    }

    public function updatedTeamFilter(): void
    {
        $this->priorityPage = 1;
    }

    public function updatedSearch(): void
    {
        $this->priorityPage = 1;
    }

    public function setDashboardFilter(string $property, mixed $value): void
    {
        abort_unless(in_array($property, ['clientFilter', 'teamFilter'], true), 422, 'Unsupported dashboard filter.');
        abort_unless(auth()->user()->canAccess('dashboard.view'), 403);

        $raw = trim((string) $value);
        if ($raw === '') {
            if ($property === 'clientFilter') {
                $this->clientFilter = '';
            } else {
                $this->teamFilter = '';
            }
            $this->priorityPage = 1;
            return;
        }

        abort_unless(ctype_digit($raw), 422, 'Please choose a valid filter option.');
        $id = (int) $raw;
        $type = $property === 'clientFilter' ? 'clients' : 'departments';
        $selected = app(\App\Services\FilterOptionService::class)
            ->options(auth()->user(), $type, 'dashboard', '', $id, 20)
            ->first(fn ($item) => (string) ($item['id'] ?? '') === (string) $id);
        abort_unless($selected, 422, 'That filter option is no longer available.');

        if ($property === 'clientFilter') {
            $this->clientFilter = (string) $id;
        } else {
            $this->teamFilter = (string) $id;
        }
        $this->priorityPage = 1;
    }

    public function setFlowTab(string $tab): void
    {
        abort_unless(in_array($tab, ['orders', 'inquiries'], true), 422);
        $this->flowTab = $tab;
    }

    public function setPriorityTab(string $tab): void
    {
        abort_unless(in_array($tab, ['orders', 'inquiries', 'tasks'], true), 422);
        $this->priorityTab = $tab;
        $this->priorityPage = 1;
    }

    public function previousPriorityPage(): void
    {
        $this->priorityPage = max(1, $this->priorityPage - 1);
    }

    public function nextPriorityPage(): void
    {
        $this->priorityPage++;
    }

    public function setTaskStatusTab(string $tab): void
    {
        abort_unless(in_array($tab, ['orders', 'inquiries'], true), 422);
        $this->taskStatusTab = $tab;
    }

    public function setActivityTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all', 'orders', 'inquiries', 'tasks'], true), 422);
        $this->activityTab = $tab;
    }

    public function setAttentionTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all', 'orders', 'inquiries'], true), 422);
        $this->attentionTab = $tab;
    }

    public function render()
    {
        $user = auth()->user();
        $clientId = max(0, (int) $this->clientFilter);
        $departmentId = max(0, (int) $this->teamFilter);
        $query = mb_strtolower(trim($this->search));
        $data = app(DashboardPrimaryQuery::class)->handle(
            $user,
            new DashboardFilterData($clientId, $departmentId, $this->rangeDays, $query),
        );

        // Reuse the exact Orders-page seven-stage source while applying the same
        // global period / Client / Team scope as the rest of the dashboard.
        // Previously this called stages($user), which intentionally returns the
        // unfiltered Orders-page totals and made these cards ignore dashboard filters.
        $data['orderStages'] = app(OrderListQuery::class)->dashboardStages(
            $user,
            $clientId,
            $departmentId,
            $this->rangeDays,
        );
        $filterOptions = app(\App\Services\FilterOptionService::class);
        $data['dashboardClientFilterOptions'] = $filterOptions->options($user, 'clients', 'dashboard', '', $clientId ?: null, 6);
        $data['dashboardTeamFilterOptions'] = $filterOptions->options($user, 'departments', 'dashboard', '', $departmentId ?: null, 6);
        $data['administratorView'] = app(AccessControlService::class)->isAdministrator($user);
        $today = app(\App\Services\WorkspaceSettingsService::class)->localToday();
        $cutoff = $today->copy()
            ->subDays(max(0, $this->rangeDays - 1))
            ->startOfDay();

        // Stage cards must open the Orders table with the exact same dashboard
        // scope that produced the card count. Keep the dashboard's operational
        // 'touched during period' semantics (updated_at), plus Client and Team.
        $data['orderStageNavigationQuery'] = array_filter([
            'dashboard_scope' => 1,
            'dashboard_range' => $this->rangeDays,
            'date_from' => $cutoff->toDateString(),
            'date_to' => $today->toDateString(),
            'client' => $clientId > 0 ? $clientId : null,
            'dashboard_team' => $departmentId > 0 ? $departmentId : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $filteredPriorityInquiries = $this->filterCollection(
            $data['priorityInquiries'],
            $clientId,
            $departmentId,
            $query,
            fn ($row): array => [
                $row->inquiry_number, $row->subject, $row->status, $row->priority,
                $row->client?->name, $row->owner?->name, $row->currentTask?->title,
                $row->currentTask?->status, $row->currentTask?->assignee?->name,
            ],
            fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
            fn ($row): ?int => $row->currentTask?->assignee?->department_id
                ? (int) $row->currentTask->assignee->department_id
                : ($row->owner?->department_id ? (int) $row->owner->department_id : null),
        );

        $data['attentionTasks'] = $this->filterCollection(
            $data['attentionTasks'],
            $clientId,
            $departmentId,
            $query,
            fn ($row): array => [
                $row->task_number, $row->title, $row->status,
                $row->job?->job_number, $row->job?->title,
                $row->job?->client?->name, $row->assignee?->name,
            ],
            fn ($row): ?int => $row->job?->client_id ? (int) $row->job->client_id : null,
            fn ($row): ?int => $row->assignee?->department_id ? (int) $row->assignee->department_id : null,
        )->take(4)->values();

        $filteredPriorityJobs = $this->filterCollection(
            $data['priorityJobs'],
            $clientId,
            $departmentId,
            $query,
            fn ($row): array => [
                $row->job_number, $row->title, $row->priority,
                $row->client?->name, $row->phase?->short_name, $row->phase?->name,
                $row->owner?->name,
            ],
            fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
            fn ($row): ?int => $row->owner?->department_id ? (int) $row->owner->department_id : null,
        );

        $attentionOrders = $this->filterCollection(
            $data['attentionOrders'] ?? collect(),
            $clientId,
            $departmentId,
            $query,
            fn ($row): array => [
                $row->job_number, $row->title, $row->attention_reason,
                $row->client?->name, $row->owner?->name,
                $row->flaggedTasks?->first()?->attention_reason,
                $row->tasks?->pluck('title')->filter()->implode(' '),
                $row->tasks?->pluck('status')->filter()->implode(' '),
                $row->tasks?->pluck('attention_reason')->filter()->implode(' '),
            ],
            fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
            fn ($row): ?int => $row->owner?->department_id ? (int) $row->owner->department_id : null,
        );

        $attentionInquiries = $this->filterCollection(
            $data['attentionInquiries'] ?? collect(),
            $clientId,
            $departmentId,
            $query,
            fn ($row): array => [
                $row->inquiry_number, $row->subject, $row->status,
                $row->client?->name, $row->owner?->name,
                $row->currentTask?->title, $row->currentTask?->status,
                $row->currentTask?->attention_reason, $row->currentTask?->assignee?->name,
            ],
            fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
            fn ($row): ?int => $row->currentTask?->assignee?->department_id
                ? (int) $row->currentTask->assignee->department_id
                : ($row->owner?->department_id ? (int) $row->owner->department_id : null),
        );

        $data['attentionOrderCount'] = $attentionOrders->count();
        $data['attentionInquiryCount'] = $attentionInquiries->count();
        $data['attentionTotalCount'] = $data['attentionOrderCount'] + $data['attentionInquiryCount'];
        $data['attentionOrders'] = $attentionOrders;
        $data['attentionInquiries'] = $attentionInquiries;
        $attentionSort = static function (array $left, array $right) use ($today): int {
            $score = static function (array $item) use ($today): array {
                $row = $item['record'];
                $isOrder = $item['kind'] === 'orders';
                $due = $isOrder ? $row->delivery_date : ($row->currentTask?->due_date ?: $row->required_delivery_date);

                return [
                    $due && $due->lt($today) ? 0 : 1,
                    ((bool) ($row->needs_attention ?? false) || (bool) ($row->attention_requested ?? false)) ? 0 : 1,
                    -($row->updated_at?->timestamp ?? 0),
                ];
            };

            return $score($left) <=> $score($right);
        };

        $data['attentionItems'] = match ($this->attentionTab) {
            'orders' => $attentionOrders
                ->map(fn ($row) => ['kind' => 'orders', 'record' => $row])
                ->take(6)
                ->values(),
            'inquiries' => $attentionInquiries
                ->map(fn ($row) => ['kind' => 'inquiries', 'record' => $row])
                ->take(6)
                ->values(),
            default => $attentionOrders
                ->take(3)
                ->map(fn ($row) => ['kind' => 'orders', 'record' => $row])
                ->concat(
                    $attentionInquiries
                        ->take(3)
                        ->map(fn ($row) => ['kind' => 'inquiries', 'record' => $row])
                )
                ->sort($attentionSort)
                ->values(),
        };

        $filteredPriorityTasks = $this->filterCollection(
            $data['priorityTasks'],
            $clientId,
            $departmentId,
            $query,
            fn ($row): array => [
                $row->task_number, $row->title, $row->status, $row->priority,
                $row->job?->job_number, $row->job?->title, $row->job?->client?->name,
                $row->phase?->short_name, $row->phase?->name, $row->assignee?->name,
            ],
            fn ($row): ?int => $row->job?->client_id ? (int) $row->job->client_id : null,
            fn ($row): ?int => $row->assignee?->department_id ? (int) $row->assignee->department_id : null,
        );

        $priorityCounts = [
            'orders' => $filteredPriorityJobs->count(),
            'inquiries' => $filteredPriorityInquiries->count(),
            'tasks' => $filteredPriorityTasks->count(),
        ];
        $priorityTotal = (int) ($priorityCounts[$this->priorityTab] ?? 0);
        $priorityLastPage = max(1, (int) ceil($priorityTotal / self::PRIORITY_PER_PAGE));
        $priorityPage = min(max(1, $this->priorityPage), $priorityLastPage);
        $priorityOffset = ($priorityPage - 1) * self::PRIORITY_PER_PAGE;

        $data['priorityJobs'] = $filteredPriorityJobs
            ->slice($priorityOffset, self::PRIORITY_PER_PAGE)
            ->values();
        $data['priorityInquiries'] = $filteredPriorityInquiries
            ->slice($priorityOffset, self::PRIORITY_PER_PAGE)
            ->values();
        $data['priorityTasks'] = $filteredPriorityTasks
            ->slice($priorityOffset, self::PRIORITY_PER_PAGE)
            ->values();
        $data['priorityPagination'] = [
            'page' => $priorityPage,
            'lastPage' => $priorityLastPage,
            'total' => $priorityTotal,
            'from' => $priorityTotal > 0 ? $priorityOffset + 1 : 0,
            'to' => min($priorityOffset + self::PRIORITY_PER_PAGE, $priorityTotal),
            'hasPrevious' => $priorityPage > 1,
            'hasNext' => $priorityPage < $priorityLastPage,
        ];

        $data['clientPortfolio'] = collect($data['clientPortfolio'])
            ->filter(fn ($row) => $clientId <= 0 || (int) $row->id === $clientId)
            ->filter(fn ($row) => $query === '' || str_contains(mb_strtolower((string) $row->name), $query))
            ->take(4)
            ->values();

        $teamPerformance = collect($data['assigneePerformance'])
            ->filter(fn ($row) => $departmentId <= 0 || (int) ($row->department_id ?? 0) === $departmentId)
            ->filter(function ($row) use ($query): bool {
                if ($query === '') return true;
                return str_contains(mb_strtolower(implode(' ', array_filter([
                    $row->name, $row->department?->name,
                ]))), $query);
            });

        // Phase 12: the Team Performance query owns decoration/sorting.

        $data['teamUserTotal'] = $teamPerformance->count();
        $data['teamHiddenCount'] = max(0, $data['teamUserTotal'] - 4);
        $data['assigneePerformance'] = $teamPerformance->take(4)->values();

        $data['recentActivity'] = collect($data['recentActivity'])
            ->filter(fn ($row) => !$row->created_at || $row->created_at->gte($cutoff))
            ->filter(function ($row): bool {
                return $this->activityTab === 'all' || (string) ($row->dashboard_kind ?? '') === $this->activityTab;
            })
            ->filter(fn ($row) => $clientId <= 0 || (int) ($row->dashboard_client_id ?? 0) === $clientId)
            ->filter(fn ($row) => $departmentId <= 0 || (int) ($row->dashboard_department_id ?? 0) === $departmentId)
            ->filter(function ($row) use ($query): bool {
                if ($query === '') return true;
                $haystack = mb_strtolower(trim(implode(' ', array_filter([
                    (string) ($row->dashboard_title ?? ''),
                    (string) ($row->dashboard_detail ?? ''),
                    (string) ($row->event ?? ''),
                ]))));
                return str_contains($haystack, $query);
            })
            ->take(6)
            ->values();

        return view('livewire.dashboard.index', $data);
    }

    private function filterCollection(
        Collection $rows,
        int $clientId,
        int $departmentId,
        string $query,
        callable $searchFields,
        callable $clientResolver,
        callable $departmentResolver,
    ): Collection {
        return $rows
            ->filter(fn ($row) => $clientId <= 0 || (int) ($clientResolver($row) ?? 0) === $clientId)
            ->filter(fn ($row) => $departmentId <= 0 || (int) ($departmentResolver($row) ?? 0) === $departmentId)
            ->filter(function ($row) use ($query, $searchFields): bool {
                if ($query === '') return true;
                $haystack = mb_strtolower(implode(' ', array_filter(array_map(
                    static fn ($value) => trim((string) $value),
                    $searchFields($row)
                ))));
                return str_contains($haystack, $query);
            })
            ->values();
    }
}
