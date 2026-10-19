<?php

namespace App\Livewire\MyWork;

use App\Actions\Inquiries\UpdateInquiryTaskDueDate;
use App\Actions\Inquiries\UpdateInquiryTaskStatus;
use App\Queries\Inquiries\InquiryDetailQuery;
use App\Queries\Inquiries\InquiryWorkQuery;
use App\Livewire\Concerns\HandlesInlineEdits;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\InquiryTask;
use App\Models\Task;
use App\Models\User;
use App\Services\FilterOptionService;
use App\Services\MyWorkService;
use App\Services\TaskService;
use App\Support\BoardLaneResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use HandlesInlineEdits;
    use WithPagination;

    #[Url(as: 'q', history: true)]
    public string $search = '';

    #[Url(as: 'filter', history: true)]
    public string $quick = 'my_tasks';

    #[Url(as: 'sort', history: true)]
    public string $sort = 'action';

    #[Url(as: 'phase', history: true)]
    public string $phaseFilter = '';

    #[Url(as: 'source', history: true)]
    public string $sourceFilter = 'orders';

    #[Url(as: 'status', history: true)]
    public string $statusFilter = '';

    public string $stageSupplier = '';
    public string $stageAssignee = '';

    public array $metrics = [
        'my_tasks' => null,
        'createdToday' => null,
        'notStarted' => null,
        'inProgress' => null,
        'dueThisWeek' => null,
        'completedThisWeek' => null,
        'attention' => null,
        'overdue' => null,
        'today' => null,
        'upcoming' => null,
        'waiting' => null,
        'mentions' => null,
    ];
    public bool $metricsLoaded = false;
    public array $statusOptions = [];
    public array $phaseOptions = [];
    public int $perPage = MyWorkService::JOBS_PER_PAGE;
    public bool $hideCompleted = false;

    private const METRIC_FILTERS = ['createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completedThisWeek', 'attention'];
    private const QUICK_FILTERS = ['my_tasks', 'all', 'mentions', 'createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completedThisWeek', 'attention', 'overdue', 'today', 'upcoming', 'waiting'];
    private const SORTS = ['action', 'due', 'job'];

    public function mount(): void
    {
        if (!in_array($this->quick, self::QUICK_FILTERS, true)) $this->quick = 'my_tasks';
        if (!in_array($this->sort, self::SORTS, true)) $this->sort = 'action';
        if (!in_array($this->sourceFilter, ['orders', 'inquiries'], true)) $this->sourceFilter = 'orders';
        $this->statusFilter = mb_substr(trim($this->statusFilter), 0, 120);
        if ($this->sourceFilter === 'inquiries' && $this->statusFilter === '') $this->sourceFilter = 'orders';

        $user = auth()->user();
        $service = app(MyWorkService::class);
        // Load the summary from the optimized My Work aggregate during the same
        // request. This avoids starting a second Livewire request with wire:init,
        // which could occupy a PHP worker long after the page was already visible.
        $this->metrics = $service->metrics($user);
        $this->metricsLoaded = true;
        $this->statusOptions = $service->statusOptions();
        $this->phaseOptions = $service->orderPhaseOptions();
        if ($this->phaseFilter !== '' && !in_array($this->phaseFilter, $this->phaseOptions, true)) {
            $this->phaseFilter = '';
        }
    }

    public function updatedSearch(): void
    {
        $this->clearMetricFilterForToolbar();
        $this->resetPage('workPage');
    }

    public function updatedPhaseFilter(): void
    {
        $this->clearMetricFilterForToolbar();
        $this->resetPage('workPage');
    }

    public function updatedStatusFilter(): void
    {
        // A status selected directly on My Tasks belongs to the Order-task
        // view. The special Inquiry source is only entered from the Dashboard.
        $this->sourceFilter = 'orders';
        $this->clearMetricFilterForToolbar();
        $this->resetPage('workPage');
    }

    public function updatedStageSupplier(): void
    {
        $this->stageSupplier = $this->normalizeStageEntityFilter($this->stageSupplier);
        $this->clearMetricFilterForToolbar();
        $this->resetPage('workPage');
    }

    public function updatedStageAssignee(): void
    {
        $this->stageAssignee = $this->normalizeStageEntityFilter($this->stageAssignee);
        $this->clearMetricFilterForToolbar();
        $this->resetPage('workPage');
    }

    public function setPhaseFilter(string $phase): void
    {
        $phase = trim($phase);
        abort_unless($phase === '' || in_array($phase, $this->phaseOptions, true), 422);

        $this->clearMetricFilterForToolbar();
        $nextPhase = $this->phaseFilter === $phase ? '' : $phase;

        // Task-status chips belong to the selected workflow stage. Changing or
        // clearing the stage must not carry a stale status into another stage.
        if ($nextPhase !== $this->phaseFilter) {
            $this->statusFilter = '';
            $this->stageSupplier = '';
            $this->stageAssignee = '';
        }

        $this->phaseFilter = $nextPhase;
        $this->resetPage('workPage');
    }

    public function setTaskStatusFilter(string $status): void
    {
        $status = trim($status);
        abort_unless($status === '' || in_array($status, $this->statusOptions, true), 422);

        $this->sourceFilter = 'orders';
        $this->clearMetricFilterForToolbar();
        $this->statusFilter = $status !== '' && $this->statusFilter === $status ? '' : $status;
        $this->resetPage('workPage');
    }

    public function updatedSort(string $value): void
    {
        if (!in_array($value, self::SORTS, true)) $this->sort = 'action';
        $this->resetPage('workPage');
    }

    public function updatedHideCompleted(): void
    {
        $this->clearMetricFilterForToolbar();
        $this->resetPage('workPage');
    }

    public function setQuick(string $quick): void
    {
        abort_unless(in_array($quick, ['my_tasks', 'mentions'], true), 422);
        $this->quick = $quick;
        $this->resetPage('workPage');
    }

    public function setMetricFilter(string $quick): void
    {
        abort_unless(in_array($quick, self::METRIC_FILTERS, true), 422);

        $this->search = '';
        $this->phaseFilter = '';
        $this->sourceFilter = 'orders';
        $this->statusFilter = '';
        $this->stageSupplier = '';
        $this->stageAssignee = '';
        $this->hideCompleted = false;
        // Summary cards are shortcuts over the same personal task scope. Clicking
        // the active card again returns to the normal My Tasks view.
        $this->quick = $this->quick === $quick ? 'my_tasks' : $quick;
        $this->resetPage('workPage');
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage('workPage');
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->phaseFilter = '';
        $this->sourceFilter = 'orders';
        $this->statusFilter = '';
        $this->stageSupplier = '';
        $this->stageAssignee = '';
        $this->quick = 'my_tasks';
        $this->hideCompleted = false;
        $this->resetPage('workPage');
    }

    public function clearStatusFilter(): void
    {
        $this->sourceFilter = 'orders';
        $this->statusFilter = '';
        $this->search = '';
        $this->quick = 'my_tasks';
        $this->hideCompleted = false;
        $this->resetPage('workPage');
    }

    private function clearMetricFilterForToolbar(): void
    {
        if (in_array($this->quick, self::METRIC_FILTERS, true)) {
            $this->quick = 'my_tasks';
        }
    }

    private function normalizeStageEntityFilter(string $value): string
    {
        $value = trim($value);
        if ($value === '' || ! ctype_digit($value) || (int) $value < 1) {
            return '';
        }

        return (string) ((int) $value);
    }

    #[Renderless]
    public function loadMetrics(): void
    {
        $this->refreshMetricsSnapshot();
    }

    #[Renderless]
    public function updateTaskStatus(int $taskId, string $status, string $version): array
    {
        $status = trim($status);
        $updatedTask = null;
        $result = $this->persistInlineEdit('task status', function () use ($taskId, $status, $version, &$updatedTask): void {
            $actor = auth()->user();
            $allowed = $this->statusOptions ?: app(MyWorkService::class)->statusOptions();
            validator(['status' => $status], [
                'status' => ['required', Rule::in($allowed)],
            ])->validate();

            $personalTask = app(MyWorkService::class)->findPersonalVisibleTask($actor, $taskId);
            $task = Task::query()
                ->whereKey($personalTask->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((string) $task->getRawOriginal('updated_at') !== $version) {
                throw ValidationException::withMessages([
                    'status' => 'This task changed since the list was loaded. Refresh My Work and try again.',
                ]);
            }

            $updatedTask = app(TaskService::class)->moveStatus($task, $status, $actor);
        });

        if (($result['ok'] ?? false) && $updatedTask instanceof Task) {
            $result['version'] = (string) $updatedTask->getRawOriginal('updated_at');
            $result['status'] = (string) $updatedTask->status;
            $result['completed'] = BoardLaneResolver::isCompleted($updatedTask->status);
            // Every role now sees active tasks only. A status change can complete
            // the current task, unlock the next one or advance the Order phase, so
            // always refresh the grouped table, including for Admin/Super Admin.
            $result['refresh'] = true;
            // Keep the counters accurate without launching a second background
            // Livewire request. The optimized aggregate is fast and bounded.
            $this->refreshMetricsSnapshot(true);
        }

        return $result;
    }

    #[Renderless]
    public function updateTaskAssignee(int $taskId, mixed $assigneeId, string $version): array
    {
        $assignee = null;
        $updatedTask = null;

        $result = $this->persistInlineEdit('task assignee', function () use ($taskId, $assigneeId, $version, &$assignee, &$updatedTask): void {
            $actor = auth()->user();
            $personalTask = app(MyWorkService::class)->findPersonalVisibleTask($actor, $taskId);
            $task = Task::query()->whereKey($personalTask->id)->lockForUpdate()->firstOrFail();

            if ((string) $task->getRawOriginal('updated_at') !== $version) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'This task changed since the list was loaded. Refresh My Tasks and try again.',
                ]);
            }

            $assigneeId = trim((string) $assigneeId) === '' ? null : (int) $assigneeId;
            $assignee = $assigneeId
                ? User::query()->where('is_active', true)->findOrFail($assigneeId)
                : null;

            $updatedTask = app(TaskService::class)->updateDetailField(
                $task,
                'assignee_id',
                $assigneeId,
                $actor,
            );
        });

        if (($result['ok'] ?? false) && $updatedTask instanceof Task) {
            $result['value'] = $updatedTask->assignee_id ? (string) $updatedTask->assignee_id : '';
            $result['display'] = (string) ($assignee?->name ?: 'Unassigned');
            $result['version'] = (string) $updatedTask->getRawOriginal('updated_at');
            $result['avatarUrl'] = $assignee?->profileImageUrl();

            // My Tasks is assignee-only for every role. Reassigning the row
            // away from the current user must therefore remove it immediately,
            // including when the actor is Admin or Super Admin.
            $needsListRefresh = trim($this->search) !== '' || $this->quick === 'attention';
            if (!$needsListRefresh) {
                try {
                    app(MyWorkService::class)->findPersonalVisibleTask(auth()->user(), (int) $updatedTask->id);
                } catch (ModelNotFoundException) {
                    $needsListRefresh = true;
                }
            }
            $result['refresh'] = $needsListRefresh;
            $this->refreshMetricsSnapshot(true);
        }

        return $result;
    }

    #[Renderless]
    public function updateTaskDueDate(int $taskId, ?string $date): array
    {
        $date = trim((string) $date);

        $result = $this->persistInlineEdit('task due date', function () use ($taskId, $date) {
            $actor = auth()->user();
            if ($date !== '') {
                validator(['date' => $date], ['date' => ['date']])->validate();
            }

            $task = app(MyWorkService::class)->findPersonalVisibleTask($actor, $taskId);
            app(TaskService::class)->updateDueDate($task, $date ?: null, $actor);
        });

        if ($result['ok'] ?? false) {
            $this->refreshMetricsSnapshot(true);
        }

        return $result;
    }

    #[Renderless]
    public function updateInquiryTaskStatus(int $taskId, string $status, string $version): array
    {
        $status = trim($status);
        $updatedTask = null;

        $result = $this->persistInlineEdit('inquiry task status', function () use ($taskId, $status, $version, &$updatedTask): void {
            $actor = auth()->user();
            $visibleTask = app(InquiryDetailQuery::class)->task($actor, $taskId);
            $task = InquiryTask::query()->whereKey($visibleTask->id)->lockForUpdate()->firstOrFail();

            if ((string) $task->getRawOriginal('updated_at') !== $version) {
                throw ValidationException::withMessages([
                    'status' => 'This Inquiry task changed since the list was loaded. Refresh My Tasks and try again.',
                ]);
            }

            $updatedTask = app(UpdateInquiryTaskStatus::class)->handle($task, $status, $actor);
        });

        if (($result['ok'] ?? false) && $updatedTask instanceof InquiryTask) {
            $result['version'] = (string) $updatedTask->getRawOriginal('updated_at');
            $result['status'] = (string) $updatedTask->status;
            $result['refresh'] = $this->sourceFilter === 'inquiries' && $this->statusFilter !== '';
        }

        return $result;
    }

    #[Renderless]
    public function updateInquiryTaskDueDate(int $taskId, ?string $date): array
    {
        $date = trim((string) $date);
        $updatedTask = null;

        $result = $this->persistInlineEdit('inquiry task due date', function () use ($taskId, $date, &$updatedTask): void {
            $actor = auth()->user();
            if ($date !== '') {
                validator(['date' => $date], ['date' => ['date']])->validate();
            }

            $task = app(InquiryDetailQuery::class)->task($actor, $taskId);
            $updatedTask = app(UpdateInquiryTaskDueDate::class)->handle($task, $date ?: null, $actor);
        });

        if (($result['ok'] ?? false) && $updatedTask instanceof InquiryTask) {
            $result['version'] = (string) $updatedTask->getRawOriginal('updated_at');
        }

        return $result;
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        $this->refreshMetricsSnapshot(true);
        $this->statusOptions = app(MyWorkService::class)->statusOptions();
    }

    private function refreshMetricsSnapshot(bool $fresh = false): void
    {
        $service = app(MyWorkService::class);
        $this->metrics = $service->metrics(auth()->user(), $fresh);
        $this->metricsLoaded = true;
        $this->dispatch(
            'my-work-metrics',
            my_tasks: $this->metrics['my_tasks'] ?? 0,
            createdToday: $this->metrics['createdToday'] ?? 0,
            notStarted: $this->metrics['notStarted'] ?? 0,
            inProgress: $this->metrics['inProgress'] ?? 0,
            dueThisWeek: $this->metrics['dueThisWeek'] ?? 0,
            completedThisWeek: $this->metrics['completedThisWeek'] ?? 0,
            attention: $this->metrics['attention'] ?? 0,
            overdue: $this->metrics['overdue'] ?? 0,
            today: $this->metrics['today'] ?? 0,
            upcoming: $this->metrics['upcoming'] ?? 0,
            waiting: $this->metrics['waiting'] ?? 0,
            mentions: $this->metrics['mentions'] ?? 0,
        );
    }

    protected function prepareForWorkspaceRefresh(): void
    {
        $this->refreshMetricsSnapshot(true);
        $this->statusOptions = app(MyWorkService::class)->statusOptions();
    }

    public function render()
    {
        $user = auth()->user();

        if ($this->sourceFilter === 'inquiries' && $this->statusFilter !== '') {
            $inquiryGroups = app(InquiryWorkQuery::class)->groups($user, [
                'search' => $this->search,
                'quick' => $this->quick,
                'sort' => $this->sort,
                'status' => $this->statusFilter,
            ], 80);

            return view('livewire.my-work.index', [
                'inquiryGroups' => $inquiryGroups,
                'inquiryVisibleTaskCount' => $inquiryGroups->sum(fn (array $group) => (int) ($group['taskCount'] ?? 0)),
                'workGroups' => collect(),
                'workPaginator' => null,
                'visibleTaskCount' => 0,
                'searchNeedsMoreCharacters' => false,
            ]);
        }

        $service = app(MyWorkService::class);

        // Required by the workflow-stage cards that remain above the restored
        // previous table/filter layout. Each card continues to filter the same
        // My Tasks result set through setPhaseFilter().
        $taskStages = $service->orderPhaseCards($user);

        $page = $service->paginate($user, [
            'search' => $this->search,
            'quick' => $this->quick,
            'sort' => $this->sort,
            'phase' => $this->phaseFilter,
            'status' => $this->statusFilter,
            'stage_supplier_id' => $this->stageSupplier !== '' ? (int) $this->stageSupplier : null,
            'stage_assignee_id' => $this->stageAssignee !== '' ? (int) $this->stageAssignee : null,
            'hide_completed' => $this->hideCompleted,
        ], $this->perPage, 'workPage');

        $filterOptions = app(FilterOptionService::class);
        $stageSupplierOptions = $this->phaseFilter !== ''
            ? $filterOptions->options($user, 'suppliers', 'order-list', '', $this->stageSupplier !== '' ? (int) $this->stageSupplier : null, 20)
            : collect();
        $stageAssigneeOptions = $this->phaseFilter !== ''
            ? $filterOptions->options($user, 'users', 'order-list-user-filter', '', $this->stageAssignee !== '' ? (int) $this->stageAssignee : null, 20)
            : collect();

        return view('livewire.my-work.index', [
            'inquiryGroups' => collect(),
            'inquiryVisibleTaskCount' => 0,
            'workGroups' => $page['groups'],
            'workPaginator' => $page['paginator'],
            'visibleTaskCount' => $page['visibleTaskCount'],
            'searchNeedsMoreCharacters' => trim($this->search) !== '' && ! $service->searchIsUsable($this->search),
            'taskStages' => $taskStages,
            'stageSupplierOptions' => $stageSupplierOptions,
            'stageAssigneeOptions' => $stageAssigneeOptions,
        ]);
    }
}
