<?php

namespace App\Livewire\Board;

use App\Actions\Orders\MoveOrderPhase;
use App\Actions\Orders\UpdateOrderDeliveryDate;
use App\Queries\Orders\VisibleOrderQuery;
use App\Livewire\Concerns\HandlesInlineEdits;
use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\Task;
use App\Models\User;
use App\Services\BoardService;
use App\Services\BoardTaskPackService;
use App\Services\MasterDataService;
use App\Services\TaskService;
use App\Support\BoardLaneResolver;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    private const TASK_METRIC_FILTERS = ['createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completedThisWeek', 'attention'];
    private const TASK_QUICK_FILTERS = ['all', 'mentions', 'createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completedThisWeek', 'attention', 'overdue', 'today', 'upcoming', 'waiting'];

    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use HandlesInlineEdits;
    use WithPagination;

    public string $mode = 'tasks';
    public ?string $message = null;

    public string $workflow = '';
    public string $search = '';
    public string $job = '';
    public string $client = '';
    #[Url(except: '')]
    public string $assignee = '';
    public string $status = '';
    public string $due = '';
    public string $sort = 'delivery';
    public string $taskSort = 'action';
    public string $taskQuick = 'all';
    public string $taskPhaseFilter = '';
    public array $taskPackMetrics = [];
    public array $taskPackStatusOptions = [];
    public array $taskPackPhaseOptions = [];
    public bool $hideEmptyPhases = false;
    public bool $cardsReady = false;
    public int $cardLimit = 60;
    public int $taskPackPerPage = BoardTaskPackService::JOBS_PER_PAGE;
    public array $expandedJobs = [];
    public bool $taskGroupsExpanded = true;
    public bool $hideCompleted = true;

    public function setMode(string $mode): void
    {
        // Job Board is intentionally disabled; keep this legacy action task-only.
        $this->mode = 'tasks';
        $this->message = null;
        $this->taskQuick = 'all';
        $this->taskSort = 'action';
        $this->resetPage('taskPackPage');
    }

    public function setTaskQuick(string $quick): void
    {
        abort_unless(in_array($quick, self::TASK_QUICK_FILTERS, true), 422);
        $this->taskQuick = $quick;
        $this->resetPage('taskPackPage');
    }

    public function setTaskMetricFilter(string $quick): void
    {
        abort_unless(in_array($quick, self::TASK_METRIC_FILTERS, true), 422);

        $this->search = '';
        $this->taskPhaseFilter = '';
        // Created Today and Completed This Week intentionally include completed
        // work, matching the My Tasks summary-card behaviour.
        $this->hideCompleted = false;
        $this->taskQuick = $this->taskQuick === $quick ? 'all' : $quick;
        $this->resetPage('taskPackPage');
    }

    public function setTaskPhaseFilter(string $phase): void
    {
        $phase = trim($phase);
        abort_unless($phase === '' || in_array($phase, $this->taskPackPhaseOptions, true), 422);

        $this->clearTaskMetricFilterForToolbar();
        $this->taskPhaseFilter = $this->taskPhaseFilter === $phase ? '' : $phase;
        $this->resetPage('taskPackPage');
    }

    public function clearTaskSearch(): void
    {
        $this->search = '';
        $this->resetPage('taskPackPage');
    }

    private function clearTaskMetricFilterForToolbar(): void
    {
        if (in_array($this->taskQuick, self::TASK_METRIC_FILTERS, true)) {
            $this->taskQuick = 'all';
        }
    }

    public function clearFilters(): void
    {
        $this->cardsReady = true;
        $this->search = '';
        $this->job = '';
        $this->client = '';
        $this->assignee = '';
        $this->status = '';
        $this->due = '';
        $this->taskPhaseFilter = '';
        $this->taskQuick = 'all';
        $this->hideCompleted = true;
        $this->cardLimit = 60;
        $this->resetPage('taskPackPage');
    }

    public function clearFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['search', 'job', 'client', 'assignee', 'status', 'due'], true), 422);
        $this->{$filter} = '';
        $this->cardsReady = true;
        $this->cardLimit = 60;
        $this->resetPage('taskPackPage');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['workflow', 'search', 'job', 'client', 'assignee', 'status', 'due', 'sort', 'taskSort', 'taskQuick', 'taskPhaseFilter', 'hideCompleted'], true)) {
            if (in_array($property, ['search', 'taskPhaseFilter', 'hideCompleted'], true)) {
                $this->clearTaskMetricFilterForToolbar();
            }
            $this->cardsReady = true;
            $this->cardLimit = 60;
            if ($this->mode === 'tasks') {
                $this->resetPage('taskPackPage');
            }
        }
    }

    public function updatedTaskPackPerPage(int|string $value): void
    {
        // All Tasks is intentionally fixed at three Order groups per page so
        // each page stays compact and predictable regardless of task count.
        $this->taskPackPerPage = BoardTaskPackService::JOBS_PER_PAGE;
        $this->resetPage('taskPackPage');
    }

    public function loadMore(): void
    {
        $this->cardsReady = true;
        $this->cardLimit = min(300, $this->cardLimit + 60);
    }

    public function loadBoardCards(): void
    {
        $this->cardsReady = true;
    }

    public function toggleJobCard(int $jobId): void
    {
        if (in_array($jobId, $this->expandedJobs, true)) {
            $this->expandedJobs = array_values(array_filter($this->expandedJobs, fn ($id) => $id !== $jobId));
            return;
        }
        $this->expandedJobs[] = $jobId;
    }

    public function toggleEmptyPhases(): void
    {
        $this->hideEmptyPhases = !$this->hideEmptyPhases;
    }

    public function expandAll(): void
    {
        $this->cardsReady = true;
        $this->expandedJobs = app(BoardService::class)
            ->jobs(auth()->user(), $this->jobFilters(), $this->cardLimit)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public function expandVisibleJobs(string $jobIds): void
    {
        $this->cardsReady = true;
        $this->expandedJobs = collect(explode(',', $jobIds))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    public function collapseAll(): void
    {
        $this->expandedJobs = [];
    }

    public function expandAllTaskGroups(): void
    {
        $this->taskGroupsExpanded = true;
    }

    public function collapseAllTaskGroups(): void
    {
        $this->taskGroupsExpanded = false;
    }

    public function moveTask(int $taskId, string $status): void
    {
        abort_unless(auth()->user()->canAccess('tasks.update'), 403);
        $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($taskId);
        app(TaskService::class)->moveStatus($task, $status, auth()->user());
        $this->message = 'Board updated successfully.';
    }

    /**
     * Task Pack list status edits are renderless: only the changed row is
     * updated optimistically in the browser, so Livewire does not re-run the
     * grouped list queries after every status change.
     */
    #[Renderless]
    public function updateTaskStatus(int $taskId, string $status, string $version): array
    {
        $status = trim($status);
        $updatedTask = null;

        $result = $this->persistInlineEdit('task status', function () use ($taskId, $status, $version, &$updatedTask): void {
            $actor = auth()->user();
            abort_unless($actor->canAccess('tasks.update'), 403);

            $allowed = app(MasterDataService::class)
                ->active('order_task_status')
                ->pluck('name')
                ->map(fn ($value) => trim((string) $value))
                ->filter()
                ->unique(fn ($value) => strtolower($value))
                ->values()
                ->all();

            validator(['status' => $status], [
                'status' => ['required', Rule::in($allowed)],
            ])->validate();

            // Keep mutation authorization strict. Normal users may read the
            // surrounding Job's tasks, but TaskService still permits edits only
            // within their configured task scope.
            $visibleTask = app(TaskService::class)->visibleQuery($actor)->findOrFail($taskId);
            $task = Task::query()->whereKey($visibleTask->id)->lockForUpdate()->firstOrFail();

            if ((string) $task->getRawOriginal('updated_at') !== $version) {
                throw ValidationException::withMessages([
                    'status' => 'This task changed since the Board was loaded. Refresh and try again.',
                ]);
            }

            $updatedTask = app(TaskService::class)->moveStatus($task, $status, $actor);
        });

        if (($result['ok'] ?? false) && $updatedTask instanceof Task) {
            $result['version'] = (string) $updatedTask->getRawOriginal('updated_at');
            $result['status'] = (string) $updatedTask->status;
            $result['completed'] = BoardLaneResolver::isCompleted((string) $updatedTask->status);
            $this->taskPackMetrics = app(BoardTaskPackService::class)->metrics(auth()->user());
            $result['metrics'] = $this->taskPackMetrics;
        }

        return $result;
    }

    #[Renderless]
    public function updateTaskDueDate(int $taskId, ?string $date): array
    {
        return $this->persistInlineEdit('task due date', function () use ($taskId, $date) {
            abort_unless(auth()->user()->canAccess('tasks.update'), 403);
            $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($taskId);
            app(TaskService::class)->updateDueDate($task, $date ?: null, auth()->user());
        });
    }

    #[Renderless]
    public function updateJobDueDate(int $jobId, ?string $date): array
    {
        return $this->persistInlineEdit('Job delivery date', function () use ($jobId, $date) {
            abort_unless(auth()->user()->canAccess('jobs.update'), 403);
            $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $jobId);
            app(UpdateOrderDeliveryDate::class)->handle(auth()->user(), (int) $job->id, (string) ($date ?: ''));
        });
    }

    public function moveJob(int $jobId, int $phaseId): void
    {
        abort_unless(auth()->user()->canAccess('jobs.update'), 403);
        try {
            $job = app(VisibleOrderQuery::class)->detail(auth()->user(), $jobId);
            app(MoveOrderPhase::class)->handle($job, $phaseId, auth()->user());
            $this->message = 'Board updated successfully.';
        } catch (Throwable $e) {
            $this->message = $e->getMessage();
        }
    }

    private function jobFilters(): array
    {
        return [
            'workflow' => $this->workflow,
            'search' => $this->search,
            'job' => $this->job,
            'client' => $this->client,
            'assignee' => $this->assignee,
            'status' => $this->status,
            'due' => $this->due,
            'sort' => $this->sort,
        ];
    }

    private function taskFilters(): array
    {
        return [
            'search' => $this->search,
            'assignee' => $this->assignee,
            'quick' => $this->taskQuick,
            'phase' => $this->taskPhaseFilter,
            'sort' => $this->taskSort,
            'hide_completed' => $this->hideCompleted,
        ];
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        $service = app(BoardTaskPackService::class);
        $this->taskPackMetrics = $service->metrics(auth()->user());
        $this->taskPackStatusOptions = $service->statusOptions();
        $this->dispatch(
            'board-task-metrics',
            createdToday: $this->taskPackMetrics['createdToday'] ?? 0,
            notStarted: $this->taskPackMetrics['notStarted'] ?? 0,
            inProgress: $this->taskPackMetrics['inProgress'] ?? 0,
            dueThisWeek: $this->taskPackMetrics['dueThisWeek'] ?? 0,
            completedThisWeek: $this->taskPackMetrics['completedThisWeek'] ?? 0,
            attention: $this->taskPackMetrics['attention'] ?? 0,
            overdue: $this->taskPackMetrics['overdue'] ?? 0,
            today: $this->taskPackMetrics['today'] ?? 0,
            upcoming: $this->taskPackMetrics['upcoming'] ?? 0,
            waiting: $this->taskPackMetrics['waiting'] ?? 0,
            mentions: $this->taskPackMetrics['mentions'] ?? 0,
        );
    }

    protected function prepareForWorkspaceRefresh(): void
    {
        $service = app(BoardTaskPackService::class);
        $this->taskPackMetrics = $service->metrics(auth()->user());
        $this->taskPackStatusOptions = $service->statusOptions();
        $this->dispatch(
            'board-task-metrics',
            createdToday: $this->taskPackMetrics['createdToday'] ?? 0,
            notStarted: $this->taskPackMetrics['notStarted'] ?? 0,
            inProgress: $this->taskPackMetrics['inProgress'] ?? 0,
            dueThisWeek: $this->taskPackMetrics['dueThisWeek'] ?? 0,
            completedThisWeek: $this->taskPackMetrics['completedThisWeek'] ?? 0,
            attention: $this->taskPackMetrics['attention'] ?? 0,
            overdue: $this->taskPackMetrics['overdue'] ?? 0,
            today: $this->taskPackMetrics['today'] ?? 0,
            upcoming: $this->taskPackMetrics['upcoming'] ?? 0,
            waiting: $this->taskPackMetrics['waiting'] ?? 0,
            mentions: $this->taskPackMetrics['mentions'] ?? 0,
        );
    }

    public function render()
    {
        // Job Board is disabled. This endpoint is now the task-only All Tasks page.
        return view('livewire.board.index', $this->taskPackBoardData(auth()->user()));
    }

    private function boardBaseData(User $user): array
    {
        return [
            'jobs' => collect(),
            'phases' => collect(),
            'jobFilterOptions' => collect(),
            'clientFilterOptions' => collect(),
            'assigneeFilterOptions' => collect(),
            'statusFilterOptions' => collect(),
            'workflowFilterOptions' => collect(),
            'hasMoreCards' => false,
            'taskPackGroups' => collect(),
            'taskPackPaginator' => null,
            'taskPackTaskCount' => 0,
            'taskPackAdministratorView' => app(\App\Services\AccessControlService::class)->isAdministrator($user),
        ];
    }

    private function jobBoardData(User $user, BoardService $service): array
    {
        $data = $this->boardBaseData($user);
        $filters = $this->jobFilters();
        $jobRows = $this->cardsReady
            ? $service->jobs($user, $filters, $this->cardLimit + 1)
            : collect();

        $data['hasMoreCards'] = $jobRows->count() > $this->cardLimit;
        $data['jobs'] = $jobRows->take($this->cardLimit)->values();
        $data['phases'] = $service->phases($this->workflow ? (int) $this->workflow : null);

        return $data;
    }

    private function taskPackBoardData(User $user): array
    {
        $data = $this->boardBaseData($user);
        $service = app(BoardTaskPackService::class);
        $page = $service->paginate(
            $user,
            $this->taskFilters(),
            $this->taskPackPerPage,
            'taskPackPage',
        );

        if ($this->taskPackStatusOptions === []) {
            $this->taskPackStatusOptions = $service->statusOptions();
        }
        if ($this->taskPackPhaseOptions === []) {
            $this->taskPackPhaseOptions = $service->phaseOptions();
        }
        if ($this->taskPhaseFilter !== '' && !in_array($this->taskPhaseFilter, $this->taskPackPhaseOptions, true)) {
            $this->taskPhaseFilter = '';
        }
        if ($this->taskPackMetrics === []) {
            $this->taskPackMetrics = $service->metrics($user);
        }

        $data['assigneeFilterOptions'] = $service->assigneeOptions($user);
        $data['taskPackGroups'] = $page['groups'];
        $data['taskPackPaginator'] = $page['paginator'];
        $data['taskPackTaskCount'] = $page['visibleTaskCount'];

        return $data;
    }
}
