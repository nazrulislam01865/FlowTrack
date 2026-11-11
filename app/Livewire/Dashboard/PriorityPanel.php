<?php

namespace App\Livewire\Dashboard;

use App\Queries\Dashboard\DashboardPriorityWorkQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

final class PriorityPanel extends Panel
{
    public string $priorityTab = 'orders';
    public int $priorityPage = 1;
    public string $priorityFilterIdentity = '';
    private const PER_PAGE = 5;

    protected function heading(): string { return 'Priority work'; }
    protected function placeholderHeight(): int { return 320; }

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

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        [$actor, $filters] = $this->authorizedFilters();
        abort_unless(in_array($this->priorityTab, ['orders', 'inquiries', 'tasks'], true), 422);
        $clientId = $filters->clientId;
        $departmentId = $filters->departmentId;
        $query = $filters->search;
        $identity = hash('sha256', json_encode([
            $clientId, $departmentId, $filters->rangeDays, $query,
        ], JSON_THROW_ON_ERROR));
        if ($this->priorityFilterIdentity !== $identity) {
            $this->priorityPage = 1;
            $this->priorityFilterIdentity = $identity;
        }
        try {
            // Only the selected legacy ranked candidate list; other tabs
            // have no visible counts, so they must not be queried here.
            $rows = app(DashboardPriorityWorkQuery::class)->handleSelected($actor, $filters, $this->priorityTab);
            $filtered = match ($this->priorityTab) {
                'orders' => $this->filterCollection(
                    $rows, $clientId, $departmentId, $query,
                    fn ($row): array => [
                        $row->job_number, $row->title, $row->priority,
                        $row->client?->name, $row->phase?->short_name, $row->phase?->name,
                        $row->owner?->name,
                    ],
                    fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
                    fn ($row): ?int => $row->owner?->department_id ? (int) $row->owner->department_id : null,
                ),
                'inquiries' => $this->filterCollection(
                    $rows, $clientId, $departmentId, $query,
                    fn ($row): array => [
                        $row->inquiry_number, $row->subject, $row->status, $row->priority,
                        $row->client?->name, $row->owner?->name, $row->currentTask?->title,
                        $row->currentTask?->status, $row->currentTask?->assignee?->name,
                    ],
                    fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
                    fn ($row): ?int => $row->currentTask?->assignee?->department_id
                        ? (int) $row->currentTask->assignee->department_id
                        : ($row->owner?->department_id ? (int) $row->owner->department_id : null),
                ),
                'tasks' => $this->filterCollection(
                    $rows, $clientId, $departmentId, $query,
                    fn ($row): array => [
                        $row->task_number, $row->title, $row->status, $row->priority,
                        $row->job?->job_number, $row->job?->title, $row->job?->client?->name,
                        $row->phase?->short_name, $row->phase?->name, $row->assignee?->name,
                    ],
                    fn ($row): ?int => $row->job?->client_id ? (int) $row->job->client_id : null,
                    fn ($row): ?int => $row->assignee?->department_id ? (int) $row->assignee->department_id : null,
                ),
            };
        } catch (QueryException $exception) {
            return $this->failed($exception);
        }

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $this->priorityPage), $lastPage);
        $offset = ($page - 1) * self::PER_PAGE;
        $data = ['priorityJobs' => collect(), 'priorityInquiries' => collect(), 'priorityTasks' => collect()];
        $key = match ($this->priorityTab) {
            'orders' => 'priorityJobs', 'inquiries' => 'priorityInquiries', 'tasks' => 'priorityTasks',
        };
        $data[$key] = $filtered->slice($offset, self::PER_PAGE)->values();
        $data['priorityPagination'] = [
            'page' => $page, 'lastPage' => $lastPage, 'total' => $total,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + self::PER_PAGE, $total),
            'hasPrevious' => $page > 1, 'hasNext' => $page < $lastPage,
        ];
        return view('livewire.dashboard.priority-panel', $data);
    }
}
