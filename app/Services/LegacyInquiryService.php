<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\Document;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\InquiryItem;
use App\Models\InquiryTask;
use App\Models\InquiryTaskComment;
use App\Models\InquiryTaskLink;
use App\Models\MasterRecord;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Support\MasterColor;
use App\Support\StoredFileResponse;
use App\Support\UserLocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LegacyInquiryService
{
    public const FINAL_STATUSES = ['Converted', 'Dead'];
    public const AUTO_READY_STATUS = 'To do';
    public const AUTO_IN_PROGRESS_STATUS = 'In Progress';
    public const AUTO_COMPLETED_STATUS = 'Completed';

    public function workspaceId(): int
    {
        return app(SetupContext::class)->workspaceId();
    }

    /**
     * Parent Inquiry working statuses are derived from Inquiry Task Status
     * Master Data. There is no separate editable Inquiry Status catalogue.
     */
    public function inquiryStatusOptions(): Collection
    {
        return $this->taskStatusRecords()
            ->map(fn (MasterRecord $record): string => $record->inquiryAutoStatus())
            ->map(fn (string $name): string => trim($name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();
    }

    /** Active Inquiry Task Status Master Data records in configured sequence. */
    public function taskStatusRecords(): Collection
    {
        return app(MasterDataService::class)->active('inquiry_task_status');
    }

    /**
     * Inquiry task statuses come directly from active Inquiry Task Status
     * Master Data. A task's historical status remains visible until changed.
     */
    public function taskStatusOptions(?string $currentStatus = null): Collection
    {
        $statuses = $this->taskStatusRecords()
            ->pluck('name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values();

        $currentStatus = trim((string) $currentStatus);
        if ($currentStatus !== '' && !$statuses->contains(fn (string $name) => strcasecmp($name, $currentStatus) === 0)) {
            $statuses->prepend($currentStatus);
        }

        return $statuses->values();
    }

    public function taskStatusRecord(string $status, bool $activeOnly = true): ?MasterRecord
    {
        $status = trim($status);
        if ($status === '') return null;

        $active = $this->taskStatusRecords()->first(
            fn (MasterRecord $record): bool => strcasecmp((string) $record->name, $status) === 0
        );
        if ($active || $activeOnly) return $active;

        return MasterRecord::withTrashed()
            ->forWorkspace($this->workspaceId())
            ->ofType('inquiry_task_status')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($status)])
            ->orderByDesc('id')
            ->first();
    }

    public function autoInquiryStatusForTaskStatus(string $status): string
    {
        if ($record = $this->taskStatusRecord($status, false)) {
            return $record->inquiryAutoStatus();
        }

        $normalized = mb_strtolower(trim($status));
        return match (true) {
            in_array($normalized, ['not started', 'ready', 'to do', 'todo'], true) => self::AUTO_READY_STATUS,
            in_array($normalized, ['completed', 'complete', 'done'], true) => self::AUTO_COMPLETED_STATUS,
            in_array($normalized, ['cancelled', 'canceled'], true) => 'Cancelled',
            in_array($normalized, ['in progress', 'in review', 'review', 'revision required', 'waiting', 'waiting for client', 'waiting for supplier'], true) => self::AUTO_IN_PROGRESS_STATUS,
            default => trim($status) !== '' ? trim($status) : self::AUTO_READY_STATUS,
        };
    }

    public function taskStatusNeedsAttention(string $status): bool
    {
        if ($record = $this->taskStatusRecord($status, false)) {
            return $record->requiresAttention();
        }

        $normalized = mb_strtolower(trim($status));
        return str_starts_with($normalized, 'waiting')
            || str_contains($normalized, 'attention')
            || in_array($normalized, ['blocked', 'on hold', 'delayed', 'at risk'], true)
            || str_contains($normalized, 'revision');
    }

    public function inquiryStatusColor(?string $autoStatus, ?string $taskStatus = null): ?string
    {
        $masterData = app(MasterDataService::class);
        $autoStatus = trim((string) $autoStatus);

        // A task color is appropriate only when that task maps to the same parent
        // Inquiry status. Once the workflow has already progressed, the parent can
        // intentionally stay In Progress while the next task is still Not Started;
        // in that case using the next task's gray color would make the parent status
        // look as though it had regressed as well.
        if (filled($taskStatus) && ($record = $this->taskStatusRecord((string) $taskStatus, false))) {
            if (strcasecmp($record->inquiryAutoStatus(), $autoStatus) === 0) {
                return $masterData->displayColorFor('inquiry_task_status', (string) $record->name);
            }
        }

        $mapped = $this->taskStatusRecords()->first(
            fn (MasterRecord $record): bool => strcasecmp($record->inquiryAutoStatus(), $autoStatus) === 0
        );

        return $mapped
            ? $masterData->displayColorFor('inquiry_task_status', (string) $mapped->name)
            : \App\Support\MasterColor::defaultFor('inquiry_status', $autoStatus);
    }

    private function isCompletionTaskStatus(string $status): bool
    {
        return strcasecmp($this->autoInquiryStatusForTaskStatus($status), self::AUTO_COMPLETED_STATUS) === 0;
    }

    private function taskStatusPayload(string $status, ?InquiryTask $existing = null): array
    {
        $record = $this->taskStatusRecord($status, false);
        $canonical = $record?->name ?: trim($status);
        $needsAttention = $record ? $record->requiresAttention() : $this->taskStatusNeedsAttention($canonical);
        $sameStatus = $existing && strcasecmp(trim((string) $existing->status), trim((string) $canonical)) === 0;

        return [
            'status' => $canonical,
            'inquiry_task_status_id' => $record?->id,
            'needs_attention' => $needsAttention,
            'attention_reason' => $needsAttention && $sameStatus ? $existing?->attention_reason : null,
        ];
    }

    private function defaultTaskStatusPayload(?InquiryTask $existing = null): array
    {
        return $this->taskStatusPayload($this->defaultTaskStatus(), $existing);
    }

    /** Active, non-terminal task statuses used by task edit forms. */
    public function openTaskStatusOptions(?string $currentStatus = null): Collection
    {
        return $this->taskStatusOptions($currentStatus)
            ->reject(fn (string $name) => $this->isCompletionTaskStatus($name))
            ->values();
    }

    /**
     * The first active non-terminal Inquiry Task Status in Master Data sequence
     * is the initial status for every new/queued Inquiry task.
     */
    public function defaultTaskStatus(): string
    {
        $status = $this->openTaskStatusOptions()->first();

        if (!$status) {
            throw ValidationException::withMessages([
                'task_status' => 'Add at least one active non-completed Inquiry Task Status in Master Data before creating or editing Inquiry tasks.',
            ]);
        }

        return (string) $status;
    }

    public function isWorkingTaskStatus(string $status): bool
    {
        $auto = mb_strtolower($this->autoInquiryStatusForTaskStatus($status));
        return !in_array($auto, [mb_strtolower(self::AUTO_READY_STATUS), mb_strtolower(self::AUTO_COMPLETED_STATUS), 'cancelled'], true);
    }

    /**
     * Status used when system rules reopen a completed Inquiry task.
     */
    public function resumeTaskStatus(): string
    {
        $statuses = $this->openTaskStatusOptions();
        $default = $this->defaultTaskStatus();
        $working = $statuses->first(fn (string $name) => $this->isWorkingTaskStatus($name));
        $next = $statuses->first(fn (string $name) => strcasecmp($name, $default) !== 0);

        return (string) ($working ?: $next ?: $default);
    }

    /**
     * Inquiry-list Task Status options come only from active Master Data →
     * Inquiry Task Statuses, in the configured Master Data sequence.
     */
    public function taskStatusFilterOptions(): Collection
    {
        return $this->taskStatusRecords()
            ->pluck('name')
            ->map(fn ($name): string => trim((string) $name))
            ->filter()
            ->unique(fn (string $name): string => mb_strtolower($name))
            ->values();
    }

    public function defaultInquiryStatus(): string
    {
        $statuses = $this->inquiryStatusOptions();
        $preferred = $statuses->first(fn (string $status) => strcasecmp($status, self::AUTO_READY_STATUS) === 0);
        $status = $preferred ?: $statuses->first();

        if (!$status) {
            throw ValidationException::withMessages([
                'status' => 'Add at least one active Inquiry Task Status in Master Data before creating or activating an Inquiry.',
            ]);
        }

        return (string) $status;
    }

    public function visibleQuery(User $user): Builder
    {
        $query = Inquiry::query()->where('workspace_id', $this->workspaceId());
        return app(AccessControlService::class)->applyInquiryScope($query, $user);
    }

    public function canEdit(User $user, Inquiry $inquiry): bool
    {
        $access = app(AccessControlService::class);
        if ($access->isAdministrator($user) || $access->isInquiryCreator($user, $inquiry)) return true;
        if (!$access->can($user, 'inquiries', 'edit')) return false;
        if (!$this->visibleQuery($user)->whereKey($inquiry->id)->exists()) return false;
        if ($access->canEditAll($user, 'inquiries')) return true;

        return (int) $inquiry->owner_id === (int) $user->id;
    }

    /**
     * Authorization for an Inquiry already loaded through visibleQuery().
     * Keeping the visibility check out of detail render avoids repeating the
     * same EXISTS scope query on every Livewire refresh.
     */
    public function canEditVisible(User $user, Inquiry $inquiry): bool
    {
        $access = app(AccessControlService::class);
        if ($access->isAdministrator($user) || $access->isInquiryCreator($user, $inquiry)) return true;
        if (!$access->can($user, 'inquiries', 'edit')) return false;
        if ($access->canEditAll($user, 'inquiries')) return true;
        return (int) $inquiry->owner_id === (int) $user->id;
    }

    /**
     * Canonical Inquiry list builder shared by the paginated list and exports.
     * Keeping the filters here guarantees exports never bypass the user's
     * Inquiry record scope or silently ignore an active list filter.
     */
    public function listQuery(User $user, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $quick = (string) ($filters['quick'] ?? 'all');
        $metricFilter = (string) ($filters['metric_filter'] ?? '');
        $clientId = (int) ($filters['client_id'] ?? 0);
        $status = trim((string) ($filters['status'] ?? ''));
        $hideCompleted = (bool) ($filters['hide_completed'] ?? false);
        [$dateFromUtc, $dateToUtc] = app(WorkspaceSettingsService::class)->localDateRangeUtcBounds(
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
        );

        if (!in_array($metricFilter, ['', 'createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completed', 'completedThisWeek', 'attention', 'dashboardOpen'], true)) {
            $metricFilter = '';
        }

        if ($status !== '' && !$this->taskStatusFilterOptions()->contains($status)) {
            $status = '';
        }

        return $this->visibleQuery($user)
            // Completion on the list is taskflow-derived. A legacy/imported Inquiry
            // can still have status Ready/In Progress and completed_at = NULL even
            // though every active Inquiry task is complete. Filter by both the
            // Inquiry lifecycle fields and the actual taskflow so Hide completed
            // always matches the Completed row the user sees in listRows().
            ->when($hideCompleted && !in_array($metricFilter, ['completed', 'completedThisWeek'], true), fn (Builder $q) => $this->applyUnfinishedListScope($q))
            ->when($metricFilter !== '', fn (Builder $q) => $this->applyMetricListScope($q, $metricFilter, $user))
            ->when($status !== '', fn (Builder $q) => $this->applyTaskStatusListScope($q, $status))
            ->when($quick === 'attention', fn (Builder $q) => $this->applyAttentionNeededListScope($q, $user))
            ->when($clientId > 0, fn (Builder $q) => $q->where('inquiries.client_id', $clientId))
            ->when($dateFromUtc, fn (Builder $q) => $q->where('inquiries.created_at', '>=', $dateFromUtc))
            ->when($dateToUtc, fn (Builder $q) => $q->where('inquiries.created_at', '<=', $dateToUtc))
            ->when($search !== '', function (Builder $q) use ($search): void {
                $q->where(function (Builder $match) use ($search): void {
                    $like = '%'.$search.'%';
                    $match->whereLike('inquiry_number', $like)
                        ->orWhereLike('subject', $like)
                        ->orWhereLike('reference_number', $like)
                        ->orWhereLike('client_contact', $like)
                        ->orWhereHas('creator', fn (Builder $creator) => $creator->whereLike('name', $like))
                        ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like))
                        ->orWhereHas('items', fn (Builder $item) => $item->whereLike('item_name', $like)->orWhereLike('category', $like))
                        ->orWhereHas('tasks', fn (Builder $task) => $task
                            ->whereLike('title', $like)
                            ->orWhereHas('assignee', fn (Builder $assignee) => $assignee->whereLike('name', $like)));
                });
            });
    }

    public function paginate(User $user, array $filters, int $perPage = 20, string $pageName = 'inquiryPage'): LengthAwarePaginator
    {
        $query = $this->listQuery($user, $filters);

        // Inquiry tasks can be worked in parallel. The list must therefore show
        // the furthest task that has actually started, not simply the first open
        // task. If nothing has started yet, fall back to the first queued task.
        // Keep every current-task subquery on the exact same ordering so title,
        // assignee, due date and position always describe the same task.
        $currentTaskDueDate = null;
        $currentTask = $this->currentTaskSubquery('title', $currentTaskDueDate);
        $currentTaskAssignee = $this->currentTaskSubquery('assignee_id', $currentTaskDueDate);
        $currentTaskDue = $this->currentTaskSubquery('due_date', $currentTaskDueDate);
        $currentTaskStatus = $this->currentTaskSubquery('status', $currentTaskDueDate);
        $currentTaskNeedsAttention = $this->currentTaskSubquery('needs_attention', $currentTaskDueDate);
        $currentTaskAttentionReason = $this->currentTaskSubquery('attention_reason', $currentTaskDueDate);
        $currentTaskSequence = $this->currentTaskSubquery('sequence', $currentTaskDueDate);
        $currentTaskSourceItem = $this->currentTaskSubquery('source_task_pack_item_id', $currentTaskDueDate);
        $firstItem = InquiryItem::query()
            ->select('item_name')
            ->whereColumn('inquiry_items.inquiry_id', 'inquiries.id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(1);

        return $query
            ->reorder()
            ->orderByDesc('inquiries.created_at')
            ->orderByDesc('inquiries.id')
            ->select([
                'inquiries.id', 'inquiries.inquiry_number', 'inquiries.client_id', 'inquiries.owner_id', 'inquiries.created_by',
                'inquiries.subject', 'inquiries.client_contact', 'inquiries.received_date', 'inquiries.priority', 'inquiries.status', 'inquiries.needs_attention', 'inquiries.attention_reason', 'inquiries.started_at', 'inquiries.result',
                'inquiries.converted_job_id', 'inquiries.created_at', 'inquiries.updated_at',
            ])
            ->selectSub($currentTask, 'current_task_title')
            ->selectSub($currentTaskAssignee, 'current_task_assignee_id')
            ->selectSub($currentTaskDue, 'current_task_due_date')
            ->selectSub($currentTaskStatus, 'current_task_status')
            ->selectSub($currentTaskNeedsAttention, 'current_task_needs_attention')
            ->selectSub($currentTaskAttentionReason, 'current_task_attention_reason')
            ->selectSub($currentTaskSequence, 'current_task_sequence')
            ->selectSub($currentTaskSourceItem, 'current_task_source_item_id')
            ->selectSub($firstItem, 'first_item_name')
            ->with(['client:id,code,name,logo_path', 'owner:id,name,profile_image_path', 'creator:id,name,profile_image_path', 'convertedJob:id,job_number,order_number'])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn (Builder $task) => $task->whereNotNull('completed_at'),
                'tasks as progressed_tasks_count' => fn (Builder $task) => $task->where(function (Builder $progressed): void {
                    $progressed->whereNotNull('started_at')->orWhereNotNull('completed_at');
                }),
            ])
            ->paginate(max(1, min(50, $perPage)), ['*'], $pageName);
    }

    /**
     * Inquiry summary cards are record-level shortcuts. Each scope is shared by
     * both the counter query and the clicked-card list filter so the number on a
     * card always matches the rows the user receives.
     */
    private function applyMetricListScope(Builder $query, string $metric, User $user): Builder
    {
        return match ($metric) {
            'createdToday' => $this->applyCreatedTodayListScope($query),
            'notStarted' => $this->applyNotStartedListScope($query),
            'inProgress' => $this->applyInProgressListScope($query),
            'dueThisWeek' => $this->applyDueThisWeekListScope($query),
            'completed' => $this->applyCompletedListScope($query),
            'completedThisWeek' => $this->applyCompletedThisWeekListScope($query),
            'attention' => $this->applyAttentionNeededListScope($query, $user),
            'dashboardOpen' => $this->applyDashboardOpenInquiryScope($query),
            default => $query,
        };
    }

    /** Match the Dashboard "Open inquiries" KPI exactly. */
    private function applyDashboardOpenInquiryScope(Builder $query): Builder
    {
        return $query
            ->whereNull('inquiries.result')
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'");
    }

    private function applyCreatedTodayListScope(Builder $query): Builder
    {
        $today = app(WorkspaceSettingsService::class)->localToday();

        return $query->whereBetween('inquiries.created_at', [
            $today->utc(),
            $today->endOfDay()->utc(),
        ]);
    }

    private function applyNotStartedListScope(Builder $query): Builder
    {
        return $this->applyUnfinishedListScope($query)
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'")
            ->whereDoesntHave('tasks', function (Builder $task): void {
                $task->where(function (Builder $started): void {
                    $started->whereNotNull('inquiry_tasks.started_at')
                        ->orWhereNotNull('inquiry_tasks.completed_at');
                });
            });
    }

    private function applyInProgressListScope(Builder $query): Builder
    {
        return $this->applyUnfinishedListScope($query)
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'")
            ->whereHas('tasks', function (Builder $task): void {
                $task->where(function (Builder $started): void {
                    $started->whereNotNull('inquiry_tasks.started_at')
                        ->orWhereNotNull('inquiry_tasks.completed_at');
                });
            });
    }

    private function applyDueThisWeekListScope(Builder $query): Builder
    {
        $today = app(WorkspaceSettingsService::class)->localToday();
        $weekStart = $today->startOfWeek()->toDateString();
        $weekEnd = $today->endOfWeek()->toDateString();

        return $this->applyUnfinishedListScope($query)
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'")
            ->whereBetween('inquiries.required_delivery_date', [$weekStart, $weekEnd]);
    }

    /**
     * Match the same Completed state shown in listRows(): the Inquiry has a
     * taskflow, every active task is completed, and it has not already moved to
     * a final converted/closed result.
     */
    private function applyCompletedListScope(Builder $query): Builder
    {
        return $query
            ->whereNull('inquiries.result')
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'")
            ->whereHas('tasks')
            ->whereDoesntHave('tasks', fn (Builder $task) => $task->whereNull('completed_at'));
    }

    private function applyCompletedThisWeekListScope(Builder $query): Builder
    {
        [$weekStartUtc, $weekEndUtc] = app(WorkspaceSettingsService::class)->localWeekUtcBounds();

        return $query
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'")
            ->whereNotNull('inquiries.completed_at')
            ->whereBetween('inquiries.completed_at', [$weekStartUtc, $weekEndUtc]);
    }

    /**
     * Filter by the SAME current task whose status is displayed in the Inquiry
     * list. The selected value must be an active Inquiry Task Status from Master
     * Data. Matching an arbitrary task in the workflow made rows appear under a
     * status even when their visible Current Task had a different status.
     */
    private function applyTaskStatusListScope(Builder $query, string $status): Builder
    {
        $record = $this->taskStatusRecord($status, true);
        if (!$record) {
            return $query->whereRaw('1 = 0');
        }

        // A completed task is no longer returned by currentTaskSubquery(). For a
        // Master Data status mapped to the automatic Completed state, use the
        // same completed-Inquiry definition as the list and summary card.
        if ($this->isCompletionTaskStatus((string) $record->name)) {
            return $this->applyCompletedListScope($query);
        }

        $currentTaskStatusSql = $this->currentTaskSubquery('status')->toSql();
        $canonicalStatus = mb_strtolower(trim((string) $record->name));

        return $query->whereRaw(
            "LOWER(TRIM(COALESCE(($currentTaskStatusSql), ''))) = ?",
            [$canonicalStatus]
        );
    }

    /**
     * A record needs attention when the Inquiry itself is flagged or any open
     * task is blocked/revision-required, overdue, unassigned, or has already
     * been marked by the task-status attention rules (including waiting rules).
     */
    private function applyAttentionNeededListScope(Builder $query, User $user): Builder
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        return $this->applyUnfinishedListScope($query)
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'")
            ->where(function (Builder $attention) use ($today): void {
                $attention->where('inquiries.needs_attention', true)
                    ->orWhereHas('tasks', function (Builder $task) use ($today): void {
                        $task->whereNull('inquiry_tasks.completed_at')
                            ->where(function (Builder $taskAttention) use ($today): void {
                                $taskAttention->where('inquiry_tasks.needs_attention', true)
                                    ->orWhereNull('inquiry_tasks.assignee_id')
                                    ->orWhereDate('inquiry_tasks.due_date', '<', $today)
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(inquiry_tasks.status, ''))) LIKE '%blocked%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(inquiry_tasks.status, ''))) LIKE '%revision%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(inquiry_tasks.status, ''))) LIKE '%overdue%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(inquiry_tasks.status, ''))) LIKE '%delayed%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(inquiry_tasks.status, ''))) LIKE '%attention%'");
                            });
                    });
            });
    }

    private function currentTaskSubquery(string $column, ?string $dueDate = null): Builder
    {
        return InquiryTask::query()
            ->select($column)
            ->whereColumn('inquiry_tasks.inquiry_id', 'inquiries.id')
            ->whereNull('inquiry_tasks.deleted_at')
            ->whereNull('inquiry_tasks.completed_at')
            ->when($dueDate !== null, fn (Builder $task) => $task->whereDate('inquiry_tasks.due_date', $dueDate))
            ->orderByRaw('CASE WHEN inquiry_tasks.started_at IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByRaw('CASE WHEN inquiry_tasks.started_at IS NOT NULL THEN inquiry_tasks.sequence END DESC')
            ->orderBy('inquiry_tasks.sequence')
            ->limit(1);
    }

    /**
     * Restrict an Inquiry list query to records that are genuinely unfinished.
     *
     * The Inquiry list derives Completed from task counts, so checking only
     * inquiries.status/completed_at is insufficient. This scope deliberately
     * mirrors that visible behavior: an Inquiry with at least one task and no
     * remaining incomplete task is finished even if its parent lifecycle fields
     * have not been synchronized yet.
     */
    private function applyUnfinishedListScope(Builder $query): Builder
    {
        return $query
            ->whereNull('inquiries.completed_at')
            ->whereNull('inquiries.result')
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) NOT IN ('completed', 'converted', 'closed', 'dead')")
            ->where(function (Builder $taskflow): void {
                // An Inquiry with no tasks is not considered completed. Otherwise
                // at least one active task must still be incomplete.
                $taskflow->whereDoesntHave('tasks')
                    ->orWhereHas('tasks', fn (Builder $task) => $task->whereNull('completed_at'));
            });
    }

    public function listRows(LengthAwarePaginator $paginator, User $user): Collection
    {
        $canViewTasks = app(AccessControlService::class)->can($user, 'tasks', 'view');
        $assigneeIds = $canViewTasks ? collect($paginator->items())
            ->pluck('current_task_assignee_id')->filter()->map(fn ($id) => (int) $id)->unique()->values() : collect();
        $assignees = $assigneeIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $assigneeIds)->get(['id', 'name', 'profile_image_path'])->keyBy('id');
        $sourceTaskPackItemIds = collect($paginator->items())
            ->pluck('current_task_source_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $taskColorsBySourceItem = $sourceTaskPackItemIds->isEmpty()
            ? collect()
            : TaskPackItem::query()
                ->whereIn('id', $sourceTaskPackItemIds)
                ->get(['id', 'color'])
                ->mapWithKeys(fn (TaskPackItem $item): array => [(int) $item->id => MasterColor::normalize((string) $item->color)]);
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        return collect($paginator->items())->map(function (Inquiry $inquiry) use ($assignees, $canViewTasks, $taskColorsBySourceItem, $today): array {
            $taskAssignee = $assignees->get((int) $inquiry->current_task_assignee_id);
            $total = (int) $inquiry->tasks_count;
            $done = (int) $inquiry->completed_tasks_count;
            $progressed = min($total, max($done, (int) $inquiry->progressed_tasks_count));
            $currentPosition = $inquiry->current_task_title
                ? max(1, min($total, (int) ($inquiry->current_task_sequence ?: max(1, $progressed))))
                : ($total > 0 ? $total : 0);
            $status = match (true) {
                $inquiry->result === 'converted' => 'Converted',
                $inquiry->result === 'dead' => 'Closed',
                (string) $inquiry->status === 'Draft' => 'Draft',
                default => (string) ($inquiry->status ?: self::AUTO_READY_STATUS),
            };
            $isCompleted = $status === self::AUTO_COMPLETED_STATUS;
            $currentTaskStatus = $canViewTasks
                ? trim((string) ($inquiry->current_task_status ?: ($isCompleted ? self::AUTO_COMPLETED_STATUS : '')))
                : 'Restricted';
            $currentTaskDueDate = $canViewTasks && $inquiry->current_task_due_date
                ? date('Y-m-d', strtotime((string) $inquiry->current_task_due_date))
                : null;
            $taskNeedsAttention = $canViewTasks && (bool) $inquiry->current_task_needs_attention;
            $inquiryNeedsAttention = (bool) ($inquiry->needs_attention ?? false);
            $needsAttention = $inquiryNeedsAttention || $taskNeedsAttention;
            $attentionReason = $inquiryNeedsAttention
                ? trim((string) ($inquiry->attention_reason ?? ''))
                : ($taskNeedsAttention ? trim((string) $inquiry->current_task_attention_reason) : '');
            $taskFlag = match (true) {
                !$canViewTasks && !$inquiryNeedsAttention => 'Restricted',
                $inquiryNeedsAttention => 'Requires attention',
                $isCompleted || $currentTaskStatus === '' => 'No flag',
                $taskNeedsAttention => 'Requires attention',
                $currentTaskDueDate !== null && $currentTaskDueDate < $today => 'Overdue',
                $currentTaskDueDate === $today => 'Due Today',
                default => 'No flag',
            };

            // While an Inquiry is active, keep the existing behavior and show the
            // current task's assignee. Once the workflow is completed there is no
            // active task assignee to represent, so the list must show the Inquiry's
            // own assignee/owner. If the Inquiry has no owner, fall back to the
            // creator so a completed row never incorrectly appears Unassigned.
            $displayAssignee = $isCompleted
                ? ($inquiry->owner ?: $inquiry->creator)
                : $taskAssignee;
            // Progress on the Inquiry list represents taskflow advancement, not
            // only finished tasks. A task begins contributing as soon as it is
            // taken into a working status (started_at is set). This keeps a
            // workflow with two active tasks at 2/4 instead of incorrectly 0/4.
            $progress = $done === $total && $total > 0 ? $total : $progressed;
            $progressPercent = $total > 0 ? max(0, min(100, (int) round(($progress / $total) * 100))) : 0;

            return [
                'id' => (int) $inquiry->id,
                'number' => (string) $inquiry->inquiry_number,
                'createdBy' => (string) ($inquiry->creator?->name ?: 'System'),
                'createdDate' => UserLocalTime::format($inquiry->created_at, 'M j, Y'),
                'createdTime' => UserLocalTime::format($inquiry->created_at, 'g:i A'),
                'title' => (string) $inquiry->subject,
                'titlePreview' => Str::words((string) $inquiry->subject, 12, '...'),
                'client' => (string) ($inquiry->client?->name ?: 'No client'),
                'clientCode' => (string) ($inquiry->client?->code ?: ''),
                'clientLogoUrl' => $inquiry->client?->logoUrl(),
                'clientContact' => (string) ($inquiry->client_contact ?: ''),
                'item' => blank($inquiry->first_item_name) ? null : (string) $inquiry->first_item_name,
                'currentTask' => $canViewTasks ? (string) ($inquiry->current_task_title ?: ($done === $total && $total > 0 ? 'Completed' : 'No active task')) : 'Restricted',
                'taskCaption' => $canViewTasks ? ($done === $total && $total > 0 ? 'Workflow tasks finished' : 'Task '.$currentPosition.' of '.$total) : 'Task access not granted',
                'progress' => $canViewTasks ? $progress : 0,
                'total' => $canViewTasks ? $total : 0,
                'progressPercent' => $canViewTasks ? $progressPercent : 0,
                'completedTaskCount' => $canViewTasks ? $done : 0,
                'hasCompletedTask' => $done > 0,
                'activeTaskColor' => $taskColorsBySourceItem->get((int) ($inquiry->current_task_source_item_id ?? 0)),
                'assignee' => $isCompleted
                    ? (string) ($displayAssignee?->name ?: 'System')
                    : ($canViewTasks ? (string) ($displayAssignee?->name ?: 'Unassigned') : '—'),
                'assigneeAvatar' => $isCompleted
                    ? $displayAssignee?->profileImageUrl()
                    : ($canViewTasks ? $displayAssignee?->profileImageUrl() : null),
                'due' => $canViewTasks && $inquiry->current_task_due_date ? date('M j', strtotime((string) $inquiry->current_task_due_date)) : '—',
                'taskStatus' => $currentTaskStatus !== '' ? $currentTaskStatus : '—',
                'flag' => $taskFlag,
                'flagReason' => $attentionReason,
                'hasStarted' => filled($inquiry->started_at),
                'startedDate' => UserLocalTime::format($inquiry->started_at, 'M j, Y'),
                'startedTime' => UserLocalTime::format($inquiry->started_at, 'g:i A'),
                'updatedDate' => UserLocalTime::format($inquiry->updated_at, 'M j, Y'),
                'updatedTime' => UserLocalTime::format($inquiry->updated_at, 'g:i A'),
                'priority' => (string) ($inquiry->priority ?: 'Medium'),
                'status' => $status,
            ];
        })->values();
    }

    public function metrics(User $user, array $filters = []): array
    {
        $filters['metric_filter'] = '';
        $base = $this->listQuery($user, $filters);
        $settings = app(WorkspaceSettingsService::class);
        $today = $settings->localToday();
        [$weekStartUtc, $weekEndUtc] = $settings->localWeekUtcBounds();
        $weekStart = $today->startOfWeek()->toDateString();
        $weekEnd = $today->endOfWeek()->toDateString();
        $todayDate = $today->toDateString();
        $todayStartUtc = $today->utc();
        $todayEndUtc = $today->endOfDay()->utc();

        $status = "LOWER(TRIM(COALESCE(inquiries.status, '')))";
        $taskExists = "EXISTS (SELECT 1 FROM inquiry_tasks metric_tasks WHERE metric_tasks.inquiry_id = inquiries.id AND metric_tasks.deleted_at IS NULL)";
        $incompleteTaskExists = "EXISTS (SELECT 1 FROM inquiry_tasks metric_open_tasks WHERE metric_open_tasks.inquiry_id = inquiries.id AND metric_open_tasks.deleted_at IS NULL AND metric_open_tasks.completed_at IS NULL)";
        $progressedTaskExists = "EXISTS (SELECT 1 FROM inquiry_tasks metric_progress_tasks WHERE metric_progress_tasks.inquiry_id = inquiries.id AND metric_progress_tasks.deleted_at IS NULL AND (metric_progress_tasks.started_at IS NOT NULL OR metric_progress_tasks.completed_at IS NOT NULL))";
        $unfinished = "inquiries.completed_at IS NULL AND inquiries.result IS NULL AND {$status} NOT IN ('completed', 'converted', 'closed', 'dead') AND ((NOT {$taskExists}) OR {$incompleteTaskExists})";
        $completed = "inquiries.result IS NULL AND {$status} != 'draft' AND {$taskExists} AND NOT {$incompleteTaskExists}";
        $attentionTaskExists = "EXISTS (SELECT 1 FROM inquiry_tasks metric_attention_tasks WHERE metric_attention_tasks.inquiry_id = inquiries.id AND metric_attention_tasks.deleted_at IS NULL AND metric_attention_tasks.completed_at IS NULL AND (metric_attention_tasks.needs_attention = 1 OR metric_attention_tasks.assignee_id IS NULL OR DATE(metric_attention_tasks.due_date) < ? OR LOWER(TRIM(COALESCE(metric_attention_tasks.status, ''))) LIKE '%blocked%' OR LOWER(TRIM(COALESCE(metric_attention_tasks.status, ''))) LIKE '%revision%' OR LOWER(TRIM(COALESCE(metric_attention_tasks.status, ''))) LIKE '%overdue%' OR LOWER(TRIM(COALESCE(metric_attention_tasks.status, ''))) LIKE '%delayed%' OR LOWER(TRIM(COALESCE(metric_attention_tasks.status, ''))) LIKE '%attention%'))";

        $row = (clone $base)
            ->reorder()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN inquiries.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS created_today,
                 COALESCE(SUM(CASE WHEN {$unfinished} AND {$status} != 'draft' AND NOT {$progressedTaskExists} THEN 1 ELSE 0 END), 0) AS not_started,
                 COALESCE(SUM(CASE WHEN {$unfinished} AND {$status} != 'draft' AND {$progressedTaskExists} THEN 1 ELSE 0 END), 0) AS in_progress,
                 COALESCE(SUM(CASE WHEN {$unfinished} AND {$status} != 'draft' AND inquiries.required_delivery_date BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS due_this_week,
                 COALESCE(SUM(CASE WHEN {$completed} THEN 1 ELSE 0 END), 0) AS completed,
                 COALESCE(SUM(CASE WHEN {$status} != 'draft' AND inquiries.completed_at IS NOT NULL AND inquiries.completed_at BETWEEN ? AND ? THEN 1 ELSE 0 END), 0) AS completed_this_week,
                 COALESCE(SUM(CASE WHEN {$unfinished} AND {$status} != 'draft' AND (inquiries.needs_attention = 1 OR {$attentionTaskExists}) THEN 1 ELSE 0 END), 0) AS attention",
                [
                    $todayStartUtc,
                    $todayEndUtc,
                    $weekStart,
                    $weekEnd,
                    $weekStartUtc,
                    $weekEndUtc,
                    $todayDate,
                ],
            )
            ->first();

        return [
            'createdToday' => (int) ($row?->created_today ?? 0),
            'notStarted' => (int) ($row?->not_started ?? 0),
            'inProgress' => (int) ($row?->in_progress ?? 0),
            'dueThisWeek' => (int) ($row?->due_this_week ?? 0),
            'completed' => (int) ($row?->completed ?? 0),
            'completedThisWeek' => (int) ($row?->completed_this_week ?? 0),
            'attention' => (int) ($row?->attention ?? 0),
        ];
    }

    public function findVisible(User $user, int $id, array $with = []): Inquiry
    {
        return $this->visibleQuery($user)->with($with)->findOrFail($id);
    }

    public function delete(Inquiry $inquiry, User $actor): void
    {
        $access = app(AccessControlService::class);
        abort_unless($access->can($actor, 'inquiries', 'delete'), 403);
        abort_unless($this->visibleQuery($actor)->whereKey($inquiry->id)->exists(), 404);

        DB::transaction(function () use ($inquiry, $actor): void {
            $this->activity(
                $inquiry,
                $actor,
                'inquiry.deleted',
                $inquiry->inquiry_number.' deleted',
            );

            // Deleting an Inquiry must not delete any already-created Order.
            // Remove the many-link row and, when this was the legacy primary
            // source, promote the oldest remaining linked Inquiry so older Order
            // code still has a stable source_inquiry_id value.
            $linkedOrderIds = DB::table('flow_job_inquiries')
                ->where('inquiry_id', $inquiry->id)
                ->pluck('flow_job_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($inquiry->converted_job_id) {
                $linkedOrderIds[] = (int) $inquiry->converted_job_id;
            }
            $linkedOrderIds = array_merge(
                $linkedOrderIds,
                FlowJob::query()->where('source_inquiry_id', $inquiry->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );

            $linkedOrderIds = array_values(array_unique(array_filter($linkedOrderIds)));
            DB::table('flow_job_inquiries')->where('inquiry_id', $inquiry->id)->delete();

            foreach ($linkedOrderIds as $orderId) {
                $order = FlowJob::query()->whereKey($orderId)->lockForUpdate()->first();
                if (!$order || (int) ($order->source_inquiry_id ?? 0) !== (int) $inquiry->id) {
                    continue;
                }

                $replacementInquiryId = DB::table('flow_job_inquiries')
                    ->join('inquiries', 'inquiries.id', '=', 'flow_job_inquiries.inquiry_id')
                    ->where('flow_job_inquiries.flow_job_id', $order->id)
                    ->whereNull('inquiries.deleted_at')
                    ->orderBy('flow_job_inquiries.created_at')
                    ->orderBy('flow_job_inquiries.id')
                    ->value('flow_job_inquiries.inquiry_id');

                $order->update([
                    'source_inquiry_id' => $replacementInquiryId ? (int) $replacementInquiryId : null,
                ]);
            }

            $inquiry->delete();
        });

        app(DashboardService::class)->forget($actor->id);
        app(ReportService::class)->forget($actor->id);
    }

    public function create(array $data, User $actor, bool $draft = false): Inquiry
    {
        $access = app(AccessControlService::class);
        abort_unless($access->can($actor, 'inquiries', 'create'), 403);
        $requestedOwnerId = (int) (($data['owner_id'] ?? null) ?: $actor->id);
        if ($requestedOwnerId !== (int) $actor->id) {
            abort_unless($access->can($actor, 'inquiries', 'assign'), 403);
        }

        return DB::transaction(function () use ($data, $actor, $draft): Inquiry {
            $inquiry = Inquiry::create([
                'workspace_id' => $this->workspaceId(),
                'inquiry_number' => $this->nextNumber(),
                'client_id' => (int) $data['client_id'],
                'owner_id' => (int) (($data['owner_id'] ?? null) ?: $actor->id),
                'created_by' => $actor->id,
                'source_task_pack_id' => ($data['source_task_pack_id'] ?? null) ?: null,
                'source_workflow_template_id' => ($data['source_workflow_template_id'] ?? null) ?: null,
                'reference_number' => blank($data['reference_number'] ?? null) ? null : trim((string) $data['reference_number']),
                'client_contact' => blank($data['client_contact'] ?? null) ? null : trim((string) $data['client_contact']),
                'received_date' => $data['received_date'],
                'request_source' => blank($data['request_source'] ?? null) ? null : $data['request_source'],
                'subject' => trim((string) $data['subject']),
                'requirement_notes' => app(RichTextService::class)->normalize($data['requirement_notes'] ?? null, 10000, 'requirement_notes'),
                'target_price' => filled($data['target_price'] ?? null) ? $data['target_price'] : null,
                'currency' => ($data['currency'] ?? null) ?: 'USD',
                'required_delivery_date' => ($data['required_delivery_date'] ?? null) ?: null,
                'priority' => ($data['priority'] ?? null) ?: 'Medium',
                'initial_follow_up_date' => ($data['initial_follow_up_date'] ?? null) ?: null,
                'status' => $draft ? 'Draft' : self::AUTO_READY_STATUS,
            ]);

            foreach (array_values($data['items'] ?? []) as $index => $item) {
                InquiryItem::create([
                    'inquiry_id' => $inquiry->id,
                    'category' => ($item['category'] ?? null) ?: null,
                    'item_name' => trim((string) $item['name']),
                    'quantity' => $item['quantity'],
                    'unit_price' => filled($item['unit_price'] ?? null) ? round((float) $item['unit_price'], 2) : null,
                    'unit' => ($item['unit'] ?? null) ?: 'pcs',
                    'notes' => blank($item['notes'] ?? null) ? null : trim((string) $item['notes']),
                    'sort_order' => $index,
                ]);
            }

            foreach (array_values($data['tasks']) as $index => $task) {
                InquiryTask::create([
                    'inquiry_id' => $inquiry->id,
                    'source_task_pack_item_id' => ($task['source_id'] ?? null) ?: null,
                    'source_workflow_phase_id' => ($task['phase_id'] ?? null) ?: null,
                    'setup_assignee_id' => ($task['setup_assignee_id'] ?? $task['assignee_id'] ?? null) ?: null,
                    'assignee_id' => ($task['assignee_id'] ?? null) ?: null,
                    'title' => trim((string) $task['name']),
                    'description' => app(RichTextService::class)->normalize($task['description'] ?? null, 10000, 'description'),
                    'sequence' => $index + 1,
                    'due_date' => ($task['due_date'] ?? null) ?: null,
                    ...$this->defaultTaskStatusPayload(),
                    'started_at' => null,
                    'requires_submission' => (bool) ($task['requires_submission'] ?? false),
                    'submission_label' => blank($task['submission_label'] ?? null) ? null : trim((string) $task['submission_label']),
                ]);
            }

            $this->activity($inquiry, $actor, $draft ? 'inquiry.draft_saved' : 'inquiry.created', $draft ? 'Inquiry draft saved' : $inquiry->inquiry_number.' created with '.count($data['tasks']).' taskflow tasks.');

            if (!$draft) {
                $first = $inquiry->tasks()->orderBy('sequence')->first();
                if ($first) $this->notifyTaskAssigned($first, $actor);

                if (filled($inquiry->requirement_notes)) {
                    $this->notifyMentions($inquiry, null, (string) $inquiry->requirement_notes, $actor);
                }

                $inquiry->tasks()
                    ->whereNotNull('description')
                    ->get(['id', 'inquiry_id', 'description'])
                    ->each(function (InquiryTask $task) use ($inquiry, $actor): void {
                        if (filled($task->description)) {
                            $this->notifyMentions($inquiry, $task, (string) $task->description, $actor);
                        }
                    });
            }

            return $inquiry->refresh();
        });
    }


    public function updateFinanceField(Inquiry $inquiry, string $field, mixed $value, User $actor): Inquiry
    {
        abort_unless(app(AccessControlService::class)->canEditParentRecordModule($actor, 'finance', $inquiry), 403);
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_if($inquiry->result, 422, 'Finance on a closed Inquiry cannot be changed.');
        abort_unless(in_array($field, ['target_price', 'currency'], true), 422, 'This Inquiry finance field cannot be edited inline.');

        if ($field === 'target_price') {
            $raw = trim((string) $value);
            if ($raw === '') {
                $value = null;
            } else {
                abort_unless(is_numeric($raw), 422, 'Target price must be a number.');
                $value = round((float) $raw, 4);
                abort_if($value < 0 || $value > 9999999999.9999, 422, 'Target price is outside the allowed range.');
            }
        } else {
            $value = strtoupper(trim((string) $value));
            abort_unless((bool) preg_match('/^[A-Z]{3}$/', $value), 422, 'Currency must be a 3-letter code.');
            $currentCurrency = strtoupper((string) ($inquiry->currency ?? ''));
            $validCurrency = $value === $currentCurrency
                || app(MasterDataService::class)->active('currency')->contains(fn ($currency) => strtoupper((string) $currency->code) === $value);
            abort_unless($validCurrency, 422, 'Select a valid active currency.');
        }

        $inquiry->update([$field => $value]);
        $this->activity(
            $inquiry,
            $actor,
            'inquiry.finance_updated',
            $field === 'target_price' ? 'Inquiry target price updated.' : 'Inquiry currency updated.'
        );

        app(DashboardService::class)->forget($actor->id);
        app(ReportService::class)->forget($actor->id);

        return $inquiry->refresh();
    }

    public function updateItem(Inquiry $inquiry, InquiryItem $item, string $field, mixed $value, User $actor): InquiryItem
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'edit'), 403);
        abort_unless((int) $item->inquiry_id === (int) $inquiry->id, 404);
        abort_unless(in_array($field, ['category', 'item_name', 'quantity', 'unit_price', 'notes'], true), 422, 'This Inquiry product field cannot be edited inline.');

        $saved = DB::transaction(function () use ($inquiry, $item, $field, $value, $actor): InquiryItem {
            $lockedInquiry = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'edit'), 403);
            abort_unless($this->canEdit($actor, $lockedInquiry), 403);
            abort_if($lockedInquiry->result, 422, 'Products on a closed Inquiry cannot be changed.');

            $lockedItem = InquiryItem::query()
                ->where('inquiry_id', $lockedInquiry->id)
                ->lockForUpdate()
                ->findOrFail($item->id);

            $wasDraft = blank($lockedItem->item_name);
            $originalCategory = (string) ($lockedItem->category ?? '');

            if ($field === 'quantity') {
                $value = (int) $value;
                abort_if($value < 1 || $value > 999999999, 422, 'Quantity must be at least 1.');
            } elseif ($field === 'unit_price') {
                $raw = trim((string) $value);
                if ($raw === '') {
                    $value = null;
                } else {
                    abort_unless(is_numeric($raw), 422, 'Unit price must be a number.');
                    $value = round((float) $raw, 2);
                    abort_if($value < 0 || $value > 999999999999.99, 422, 'Unit price is outside the allowed range.');
                }
            } elseif ($field === 'notes') {
                $value = trim((string) $value);
                abort_if(mb_strlen($value) > 2000, 422, 'Product notes may not exceed 2000 characters.');
                $value = $value !== '' ? $value : null;
            } elseif ($field === 'category') {
                $value = trim((string) $value);
                abort_if($value === '', 422, 'Product category is required.');
                abort_unless(
                    app(MasterDataService::class)->active('product_category')->contains('name', $value),
                    422,
                    'Select a valid active product category.'
                );
            } else {
                $value = trim((string) $value);
                abort_if($value === '', 422, 'Product is required.');
                abort_if(blank($lockedItem->category), 422, 'Select a product category first.');
                $validProduct = app(FilterOptionService::class)
                    ->options($actor, 'products', 'inquiry-detail', '', $value, 20, [
                        'category' => (string) $lockedItem->category,
                    ])
                    ->contains(fn ($option) => (string) ($option['id'] ?? '') === $value);
                abort_unless($validProduct, 422, 'Select a valid active product for this category.');
            }

            $lockedItem->update([$field => $value]);

            // Category and product are dependent. Never leave a product selected
            // from the previous category after an inline category change.
            if ($field === 'category' && $value !== $originalCategory && filled($lockedItem->item_name)) {
                $lockedItem->update(['item_name' => '']);
            }

            $lockedItem = $lockedItem->refresh();
            if ($wasDraft && blank($lockedItem->item_name)) {
                return $lockedItem;
            }

            if ($wasDraft && $field === 'item_name') {
                $this->activity(
                    $lockedInquiry,
                    $actor,
                    'inquiry.product_added',
                    'Product '.$lockedItem->item_name.' added to the Inquiry.'
                );
            } else {
                $this->activity(
                    $lockedInquiry,
                    $actor,
                    'inquiry.product_updated',
                    'Inquiry product details updated.'
                );
            }

            $lockedInquiry->touch();
            return $lockedItem;
        }, 3);

        app(DashboardService::class)->forget($actor->id);
        app(ReportService::class)->forget($actor->id);

        return $saved;
    }

    public function addItem(Inquiry $inquiry, string $category, string $product, int $quantity, User $actor, ?float $unitPrice = null): InquiryItem
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'view') && app(AccessControlService::class)->can($actor, 'catalog_products', 'create'), 403);
        $item = DB::transaction(function () use ($inquiry, $category, $product, $quantity, $actor, $unitPrice): InquiryItem {
            $lockedInquiry = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canEdit($actor, $lockedInquiry), 403);
            abort_if($lockedInquiry->result, 422, 'Products on a closed Inquiry cannot be changed.');

            // Older Inquiry Details builds created a blank database row before the
            // user selected a Product. The shared Add Product panel keeps draft
            // state in Livewire instead, so remove those unfinished legacy rows.
            $lockedInquiry->items()
                ->where(fn ($query) => $query->whereNull('item_name')->orWhere('item_name', ''))
                ->delete();

            abort_if($lockedInquiry->items()->count() >= 25, 422, 'An Inquiry can contain up to 25 product rows.');

            $category = trim($category);
            $product = trim($product);
            if ($category !== '') {
                abort_unless(
                    app(MasterDataService::class)->active('product_category')->contains('name', $category),
                    422,
                    'Select a valid active product category.'
                );
            }
            if ($product !== '') {
                abort_if($category === '', 422, 'Select a product category first.');
                $categoryRecord = MasterRecord::query()
                    ->forWorkspace(app(MasterDataService::class)->workspaceId())
                    ->ofType('product_category')
                    ->active()
                    ->where('name', $category)
                    ->first(['id']);

                $validProduct = app(ProductCatalogService::class)
                    ->activeProductsQuery()
                    ->where('name', $product)
                    ->where(function ($query) use ($category, $categoryRecord): void {
                        if ($categoryRecord) {
                            $query->where('parent_id', (int) $categoryRecord->id);
                        }

                        $query->orWhere(function ($legacy) use ($category): void {
                            $legacy->whereNull('parent_id')
                                ->where(function ($description) use ($category): void {
                                    $description->where('description', $category)
                                        ->orWhereLike('description', $category.' ·%');
                                });
                        });
                    })
                    ->exists();
                abort_unless($validProduct, 422, 'Select a valid active product for this category.');
            }

            $item = InquiryItem::create([
                'inquiry_id' => $lockedInquiry->id,
                'category' => $category !== '' ? $category : null,
                'item_name' => $product,
                'quantity' => max(1, min(999999999, $quantity)),
                'unit' => 'pcs',
                'unit_price' => $unitPrice !== null ? round(max(0, min(999999999999.99, $unitPrice)), 2) : null,
                'sort_order' => ((int) $lockedInquiry->items()->max('sort_order')) + 1,
            ]);

            if ($product !== '') {
                $this->activity($lockedInquiry, $actor, 'inquiry.product_added', 'Product '.$product.' added to the Inquiry.');
            }
            $lockedInquiry->touch();

            return $item->refresh();
        }, 3);

        app(DashboardService::class)->forget($actor->id);
        app(ReportService::class)->forget($actor->id);

        return $item;
    }

    public function removeItem(Inquiry $inquiry, InquiryItem $item, User $actor): void
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'view') && app(AccessControlService::class)->can($actor, 'catalog_products', 'delete'), 403);
        DB::transaction(function () use ($inquiry, $item, $actor): void {
            $lockedInquiry = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canEdit($actor, $lockedInquiry), 403);
            abort_if($lockedInquiry->result, 422, 'Products on a closed Inquiry cannot be changed.');

            $lockedItem = InquiryItem::query()
                ->where('inquiry_id', $lockedInquiry->id)
                ->lockForUpdate()
                ->findOrFail($item->id);
            $wasDraft = blank($lockedItem->item_name);

            $productName = trim((string) $lockedItem->item_name);
            $lockedItem->delete();
            $lockedInquiry->touch();

            if (! $wasDraft) {
                $this->activity(
                    $lockedInquiry,
                    $actor,
                    'inquiry.product_removed',
                    ($productName !== '' ? 'Product '.$productName : 'Product').' removed from the Inquiry.'
                );
            }
        }, 3);

        app(DashboardService::class)->forget($actor->id);
        app(ReportService::class)->forget($actor->id);
    }


    public function replaceItems(Inquiry $inquiry, array $items, User $actor): Inquiry
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'edit'), 403);
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_if($inquiry->result, 422, 'Products on a closed Inquiry cannot be changed.');
        abort_if($items === [], 422, 'An Inquiry must keep at least one product.');

        DB::transaction(function () use ($inquiry, $items, $actor): void {
            $locked = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'edit'), 403);
            abort_unless($this->canEdit($actor, $locked), 403);
            abort_if($locked->result, 422, 'Products on a closed Inquiry cannot be changed.');

            $locked->items()->delete();
            foreach (array_values($items) as $index => $item) {
                InquiryItem::create([
                    'inquiry_id' => $locked->id,
                    'category' => blank($item['category'] ?? null) ? null : trim((string) $item['category']),
                    'item_name' => trim((string) $item['name']),
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                    'unit_price' => filled($item['unit_price'] ?? null) ? round((float) $item['unit_price'], 2) : null,
                    'unit' => ($item['unit'] ?? null) ?: 'pcs',
                    'notes' => blank($item['notes'] ?? null) ? null : trim((string) $item['notes']),
                    'sort_order' => $index,
                ]);
            }

            $locked->touch();
            $this->activity(
                $locked,
                $actor,
                'inquiry.items_updated',
                count($items).' '.Str::plural('product', count($items)).' updated on the Inquiry.',
            );
        });

        app(DashboardService::class)->forget($actor->id);
        app(ReportService::class)->forget($actor->id);

        return $inquiry->fresh(['items']);
    }

    public function workflowSummary(int $workflowId): array
    {
        $workflow = WorkflowTemplate::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_active', true)
            ->with([
                'phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence'),
                'phases.taskPack' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_snapshot', false)
                    ->withCount('items'),
            ])
            ->findOrFail($workflowId);

        $phases = $workflow->phases
            ->filter(fn ($phase) => $phase->taskPack && (int) $phase->taskPack->items_count > 0);

        return [
            'phases' => $phases->count(),
            'tasks' => $phases->sum(fn ($phase) => (int) $phase->taskPack->items_count),
        ];
    }

    public function workflowRows(int $workflowId, ?string $baseDate = null): array
    {
        $workflow = WorkflowTemplate::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_active', true)
            ->with([
                'phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence'),
                'phases.taskPack' => fn ($query) => $query->where('is_active', true)->where('is_snapshot', false),
                'phases.taskPack.items.defaultAssignee:id,name',
                'phases.taskPack.items.documentCategory:id,name',
            ])
            ->findOrFail($workflowId);

        $base = $baseDate
            ? \Carbon\Carbon::parse($baseDate)
            : app(WorkspaceSettingsService::class)->localToday();

        return $workflow->phases
            ->flatMap(function ($phase) use ($base) {
                $pack = $phase->taskPack;
                if (!$pack) return collect();

                return $pack->items->map(fn ($item) => [
                    'id' => null,
                    'source_id' => (int) $item->id,
                    'phase_id' => (int) $phase->id,
                    'phase_name' => (string) $phase->name,
                    'phase_sequence' => (int) $phase->sequence,
                    'task_pack_id' => (int) $pack->id,
                    'task_pack_name' => (string) $pack->name,
                    'name' => (string) $item->title,
                    'description' => (string) ($item->description ?: ''),
                    'assignee_id' => $item->default_assignee_id ? (int) $item->default_assignee_id : null,
                    'setup_assignee_id' => $item->default_assignee_id ? (int) $item->default_assignee_id : null,
                    'assignee_name' => (string) ($item->defaultAssignee?->name ?: ''),
                    'due_date' => $base->copy()->addDays(max(0, (int) $item->due_offset_days))->toDateString(),
                    'requires_submission' => (bool) $item->document_category_id,
                    'submission_label' => (string) ($item->documentCategory?->name ?: ''),
                    'state' => 'future',
                ]);
            })
            ->values()
            ->all();
    }

    public function taskPackOptions(): Collection
    {
        return TaskPack::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_snapshot', false)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN LOWER(name) LIKE '%inquiry%' THEN 0 WHEN LOWER(name) LIKE '%quotation%' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name']);
    }

    public function taskPackRows(int $taskPackId, ?string $baseDate, ?int $fallbackAssigneeId): array
    {
        $pack = TaskPack::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_snapshot', false)
            ->where('is_active', true)
            ->with(['items.defaultAssignee:id,name', 'items.documentCategory:id,name'])
            ->findOrFail($taskPackId);

        $base = $baseDate ? \Carbon\Carbon::parse($baseDate) : app(WorkspaceSettingsService::class)->localToday();
        $fallbackAssigneeName = $fallbackAssigneeId
            ? (string) (User::query()->whereKey($fallbackAssigneeId)->value('name') ?: '')
            : '';

        return $pack->items->map(fn ($item) => [
            'id' => null,
            'source_id' => (int) $item->id,
            'name' => (string) $item->title,
            'description' => (string) ($item->description ?: ''),
            'assignee_id' => (int) ($item->default_assignee_id ?: $fallbackAssigneeId ?: 0) ?: null,
            'setup_assignee_id' => $item->default_assignee_id ? (int) $item->default_assignee_id : null,
            'assignee_name' => (string) ($item->defaultAssignee?->name ?: $fallbackAssigneeName),
            'due_date' => $base->copy()->addDays(max(0, (int) $item->due_offset_days))->toDateString(),
            'requires_submission' => (bool) $item->document_category_id,
            'submission_label' => (string) ($item->documentCategory?->name ?: ''),
            'state' => 'future',
        ])->values()->all();
    }

    public function updateDetailField(Inquiry $inquiry, string $field, mixed $value, User $actor): Inquiry
    {
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_if($inquiry->result, 422, 'A completed Inquiry is locked.');

        if ($field === 'status') {
            return $this->updateStatus($inquiry, (string) $value, $actor);
        }

        abort_unless(in_array($field, ['subject', 'owner_id', 'priority', 'requirement_notes'], true), 422, 'Unsupported Inquiry field.');

        $oldDisplay = '';
        $newDisplay = '';
        $update = [];

        if ($field === 'subject') {
            $subject = trim((string) $value);
            if ($subject === '' || mb_strlen($subject) > 255) {
                throw ValidationException::withMessages(['subject' => 'Inquiry title is required and must be 255 characters or fewer.']);
            }
            $oldDisplay = (string) $inquiry->subject;
            $newDisplay = $subject;
            $update['subject'] = $subject;
        } elseif ($field === 'owner_id') {
            $rawOwnerId = trim((string) $value);
            $ownerId = $rawOwnerId === '' ? null : (int) $rawOwnerId;
            if ((int) ($ownerId ?? 0) !== (int) ($inquiry->owner_id ?? 0)) {
                $access = app(AccessControlService::class);
                abort_unless($access->can($actor, 'inquiries', 'assign') || $access->isInquiryCreator($actor, $inquiry), 403);
            }
            $owner = $ownerId ? User::query()->where('is_active', true)->find($ownerId) : null;
            if ($ownerId && ! $owner) {
                throw ValidationException::withMessages(['owner_id' => 'Select an active user.']);
            }
            $oldDisplay = (string) ($inquiry->owner?->name ?: 'Unassigned');
            $newDisplay = (string) ($owner?->name ?: 'Unassigned');
            $update['owner_id'] = $ownerId;
        } elseif ($field === 'priority') {
            $priority = trim((string) $value);
            $allowed = app(MasterDataService::class)->active('priority')->pluck('name')->map(fn ($name) => trim((string) $name));
            if ($priority === '' || ! $allowed->contains($priority)) {
                throw ValidationException::withMessages(['priority' => 'Select a valid active priority.']);
            }
            $oldDisplay = (string) $inquiry->priority;
            $newDisplay = $priority;
            $update['priority'] = $priority;
        } else {
            $description = app(RichTextService::class)->normalize((string) $value, 10000, 'requirement_notes');
            $oldDisplay = (string) ($inquiry->requirement_notes ?? '');
            $newDisplay = (string) ($description ?? '');
            $update['requirement_notes'] = $description;
        }

        if ($oldDisplay === $newDisplay) return $inquiry->refresh();

        $inquiry->update($update);
        $label = match ($field) {
            'subject' => 'title',
            'owner_id' => 'assignee',
            'priority' => 'priority',
            'requirement_notes' => 'description',
            default => 'field',
        };
        if ($field === 'requirement_notes') {
            $this->activity($inquiry, $actor, 'inquiry.field_updated', 'Inquiry description updated.');
            $this->notifyMentions($inquiry->refresh(), null, $newDisplay, $actor);
        } else {
            $oldActivityDisplay = $oldDisplay !== '' ? $oldDisplay : 'empty';
            $newActivityDisplay = $newDisplay !== '' ? $newDisplay : 'empty';
            $this->activity($inquiry, $actor, 'inquiry.field_updated', 'Inquiry '.$label.' changed from '.$oldActivityDisplay.' to '.$newActivityDisplay.'.');
        }

        return $inquiry->refresh();
    }

    public function updateStartedAt(Inquiry $inquiry, ?string $value, User $actor): Inquiry
    {
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_if($inquiry->result, 422, 'A completed Inquiry is locked.');

        $raw = trim((string) $value);
        $next = null;

        if ($raw === '') {
            $hasStartedTask = $inquiry->tasks()
                ->where(fn (Builder $query) => $query->whereNotNull('started_at')->orWhereNotNull('completed_at'))
                ->exists();

            if ($hasStartedTask) {
                throw ValidationException::withMessages([
                    'started_at' => 'The Inquiry start date cannot be cleared after task work has started.',
                ]);
            }
        } else {
            try {
                $local = \Illuminate\Support\Carbon::createFromFormat('Y-m-d\TH:i', $raw, UserLocalTime::timezone());
            } catch (\Throwable) {
                throw ValidationException::withMessages(['started_at' => 'Enter a valid Inquiry start date and time.']);
            }

            if (! $local || $local->format('Y-m-d\TH:i') !== $raw) {
                throw ValidationException::withMessages(['started_at' => 'Enter a valid Inquiry start date and time.']);
            }

            if ($local->isFuture()) {
                throw ValidationException::withMessages(['started_at' => 'The Inquiry start date cannot be in the future.']);
            }

            $next = $local->setTimezone(config('app.timezone', 'UTC'));
            if ($inquiry->created_at && $next->lt($inquiry->created_at)) {
                throw ValidationException::withMessages(['started_at' => 'The Inquiry start date cannot be earlier than its created time.']);
            }
            if ($inquiry->completed_at && $next->gt($inquiry->completed_at)) {
                throw ValidationException::withMessages(['started_at' => 'The Inquiry start date must be before its completion time.']);
            }
        }

        $old = $inquiry->started_at;
        $oldKey = $old?->format('Y-m-d H:i');
        $newKey = $next?->format('Y-m-d H:i');
        if ($oldKey === $newKey) return $inquiry->refresh();

        $inquiry->update(['started_at' => $next]);

        $oldDisplay = $old ? UserLocalTime::format($old, 'M j, Y g:i A') : 'not set';
        $newDisplay = $next ? UserLocalTime::format($next, 'M j, Y g:i A') : 'not set';
        $this->activity(
            $inquiry,
            $actor,
            'inquiry.start_date_changed',
            'Inquiry start date changed from '.$oldDisplay.' to '.$newDisplay.'.'
        );

        return $inquiry->refresh();
    }

    public function updateStatus(Inquiry $inquiry, string $status, User $actor): Inquiry
    {
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_if(in_array((string) $inquiry->status, self::FINAL_STATUSES, true) || $inquiry->result, 422, 'A completed Inquiry cannot change working status.');

        // Working lifecycle status is task-driven. The only legacy/manual transition
        // retained here is activating a Draft; after that, task progress owns status.
        if ((string) $inquiry->status === 'Draft') {
            $inquiry->update(['status' => self::AUTO_READY_STATUS]);
            $first = $inquiry->tasks()->whereNull('completed_at')->orderBy('sequence')->first();
            if ($first) {
                $first->update($this->defaultTaskStatusPayload($first) + ['started_at' => null]);
                $this->notifyTaskAssigned($first, $actor);
            }
            $this->activity($inquiry, $actor, 'inquiry.status_changed', 'Inquiry activated and is To do.');
        }

        return $this->syncAutomaticStatus($inquiry, $actor);
    }

    public function updateTask(InquiryTask $task, array $data, User $actor): InquiryTask
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->completed_at, 422, 'Completed tasks are locked.');

        // Inquiry task status choices are workspace Master Data. Completed is
        // handled by updateTaskStatus()/completeTask() because it also updates
        // completed_at and the parent Inquiry lifecycle.
        $requestedStatus = trim((string) ($data['status'] ?? ''));
        $oldStatus = (string) $task->status;
        $allowedStatuses = $this->openTaskStatusOptions((string) $task->status);
        $nextStatus = $allowedStatuses->contains(fn (string $name) => strcasecmp($name, $requestedStatus) === 0)
            ? (string) $allowedStatuses->first(fn (string $name) => strcasecmp($name, $requestedStatus) === 0)
            : ((string) $task->status ?: $this->defaultTaskStatus());
        $oldAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;
        $nextAssigneeId = ($data['assignee_id'] ?? null) ? (int) $data['assignee_id'] : null;
        $oldDueDate = $task->due_date?->toDateString();
        $nextDueDate = ($data['due_date'] ?? null) ?: null;
        $hasTaskActivity = strcasecmp((string) $task->status, $nextStatus) !== 0
            || (string) ($oldDueDate ?? '') !== (string) ($nextDueDate ?? '');

        // Match Order tasks: changing task work/status claims the task for the
        // person doing the work. A pure manual assignee edit remains explicit.
        if ($hasTaskActivity) {
            $task = $this->claimTaskForAction($task, $actor, 'updated the task');
            $nextAssigneeId = (int) $actor->id;
        } elseif ((int) ($oldAssigneeId ?? 0) !== (int) ($nextAssigneeId ?? 0)) {
            abort_unless(app(AccessControlService::class)->canAssignInquiryTask($actor, $task), 403);
        }

        $taskUpdate = [
            'assignee_id' => $nextAssigneeId ?: null,
            'due_date' => $nextDueDate,
        ] + $this->taskStatusPayload($nextStatus, $task);
        $taskStartAt = null;
        if ($this->isWorkingTaskStatus($nextStatus) && !$task->started_at) {
            $taskStartAt = now();
            $taskUpdate['started_at'] = $taskStartAt;
        }

        // The Inquiry starts the first time any of its tasks is explicitly taken
        // In Progress. Use an atomic WHERE NULL update so simultaneous task
        // changes cannot overwrite the original Inquiry start timestamp.
        if ($this->isWorkingTaskStatus($nextStatus) && !$task->inquiry->started_at) {
            $inquiryStartAt = $taskStartAt ?: now();
            Inquiry::query()
                ->whereKey($task->inquiry_id)
                ->whereNull('started_at')
                ->update(['started_at' => $inquiryStartAt]);
            $task->inquiry->started_at = $inquiryStartAt;
        }

        $task->update($taskUpdate);
        // Taskflow changes drive the Inquiry list's Current Task and Progress.
        // Touch the parent even when its lifecycle status stays In Progress so
        // list ordering/realtime refreshes observe the taskflow advancement.
        $task->inquiry->touch();
        if ((int) ($task->assignee_id ?? 0) !== (int) ($oldAssigneeId ?? 0)) {
            $this->forgetMyTaskShell($oldAssigneeId);
            $this->notifyTaskAssigned($task, $actor);
        }
        $this->activity($task->inquiry, $actor, 'inquiry.task_updated', $task->title.' updated — '.$task->status.'.', [
            'inquiry_task_id' => $task->id,
            'old_status' => $oldStatus,
            'to_status' => (string) $task->status,
        ]);
        $this->syncAutomaticStatus($task->inquiry, $actor);
        return $task->refresh();
    }

    /**
     * A required Inquiry task submission may be either a stored/linked file or
     * an external task link. Keep this business rule in one service method so
     * completion, reopening, and the UI all agree on the same evidence.
     */
    public function taskHasSubmissionEvidence(InquiryTask $task): bool
    {
        return $task->documents()->exists() || $task->links()->exists();
    }

    public function updateTaskStatus(InquiryTask $task, string $status, User $actor): InquiryTask
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot change status.');

        $status = trim($status);
        $activeStatus = $this->taskStatusOptions()->first(fn (string $name) => strcasecmp($name, $status) === 0);
        $isSystemCompletion = $this->isCompletionTaskStatus($status);
        $isExistingHistoricalStatus = strcasecmp($status, trim((string) $task->status)) === 0;
        abort_unless($activeStatus || $isSystemCompletion || $isExistingHistoricalStatus, 422, 'Invalid or inactive Inquiry task status.');
        if ($activeStatus) $status = (string) $activeStatus;

        return DB::transaction(function () use ($task, $status, $actor): InquiryTask {
            $task->refresh();
            $task->loadMissing('inquiry');

            $oldStatus = (string) $task->status;
            $wasCompleted = $task->completed_at !== null || $this->isCompletionTaskStatus($oldStatus);
            $willComplete = $this->isCompletionTaskStatus($status);

            if ($oldStatus === $status && (($willComplete && $task->completed_at) || (!$willComplete && !$task->completed_at))) {
                return $task;
            }

            if ($willComplete && $task->requires_submission && ! $this->taskHasSubmissionEvidence($task)) {
                throw ValidationException::withMessages(['task' => 'Add the required file or link before completion.']);
            }

            $task = $this->claimTaskForAction(
                $task,
                $actor,
                $willComplete ? 'completed the task' : ($wasCompleted ? 'reopened the task' : 'changed the task status'),
            );

            $updates = $this->taskStatusPayload($status, $task) + [
                'completed_at' => $willComplete ? ($task->completed_at ?: now()) : null,
            ];

            // Keep the first time the task was actually started. A task completed
            // directly from its initial Master Data status still receives a start
            // timestamp, matching the order-task ability to move directly to Completed.
            if (($willComplete || $this->isWorkingTaskStatus($status)) && !$task->started_at) {
                $updates['started_at'] = now();
            }

            $task->update($updates);
            $task->refresh();

            // The Inquiry start date is established the first time any task enters
            // In Progress or Completed. Reopening a task never overwrites it.
            if (($this->isWorkingTaskStatus($status) || $willComplete) && !$task->inquiry->started_at) {
                $inquiryStartAt = $task->started_at ?: now();
                Inquiry::query()
                    ->whereKey($task->inquiry_id)
                    ->whereNull('started_at')
                    ->update(['started_at' => $inquiryStartAt]);
                $task->inquiry->started_at = $inquiryStartAt;
            }

            $task->inquiry->touch();
            $this->forgetMyTaskShell($task->assignee_id ? (int) $task->assignee_id : null);

            if ($willComplete && !$wasCompleted) {
                $this->activity($task->inquiry, $actor, 'inquiry.task_completed', $task->title.' completed.', [
                    'inquiry_task_id' => $task->id,
                    'old_status' => $oldStatus,
                    'to_status' => $status,
                ]);
            } elseif (!$willComplete && $wasCompleted) {
                $this->activity($task->inquiry, $actor, 'inquiry.task_reopened', $task->title.' reopened — status changed to '.$status.'.', [
                    'inquiry_task_id' => $task->id,
                    'old_status' => $oldStatus,
                    'to_status' => $status,
                ]);
            } else {
                $this->activity($task->inquiry, $actor, 'inquiry.task_status_changed', $task->title.' status changed from '.$oldStatus.' to '.$status.'.', [
                    'inquiry_task_id' => $task->id,
                    'old_status' => $oldStatus,
                    'to_status' => $status,
                ]);
            }

            $remaining = $task->inquiry->tasks()->whereNull('completed_at')->exists();
            if ($willComplete && !$remaining) {
                $this->activity($task->inquiry, $actor, 'inquiry.ready_for_decision', 'All Inquiry taskflow tasks are complete. The Inquiry is now Completed and the final decision is available.');
            }

            $this->syncAutomaticStatus($task->inquiry, $actor);
            return $task->refresh();
        });
    }

    public function updateTaskDueDate(InquiryTask $task, ?string $date, User $actor): InquiryTask
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot be edited.');

        // Due date remains editable after task completion. Updating it must not
        // reopen the task or alter completed_at/status.
        $task = $this->claimTaskForAction($task, $actor, 'changed the due date');
        $task->update(['due_date' => $date ?: null]);
        $task->inquiry->touch();
        $this->forgetMyTaskShell($task->assignee_id ? (int) $task->assignee_id : null);
        $this->activity($task->inquiry, $actor, 'inquiry.task_due_changed', $task->title.' due date changed'.($date ? ' to '.$date : '').'.', ['inquiry_task_id' => $task->id]);
        return $task->refresh();
    }

    public function updateTaskAssignee(InquiryTask $task, ?int $assigneeId, User $actor): InquiryTask
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_unless(app(AccessControlService::class)->canAssignInquiryTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot be edited.');

        $oldAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;
        $task->update(['assignee_id' => $assigneeId ?: null]);
        $task->refresh();
        $task->loadMissing('inquiry', 'assignee');
        $task->inquiry->touch();

        if ((int) ($task->assignee_id ?? 0) !== (int) ($oldAssigneeId ?? 0)) {
            $this->forgetMyTaskShell($oldAssigneeId);
            $this->notifyTaskAssigned($task, $actor);
            $this->activity($task->inquiry, $actor, 'inquiry.task_assignee_changed', $task->title.' assignee changed to '.($task->assignee?->name ?: 'Unassigned').'.', ['inquiry_task_id' => $task->id]);
        }

        return $task->refresh();
    }

    private function completionTaskStatus(): string
    {
        $record = $this->taskStatusRecords()->first(
            fn (MasterRecord $status): bool => strcasecmp($status->inquiryAutoStatus(), self::AUTO_COMPLETED_STATUS) === 0
        );

        return (string) ($record?->name ?: self::AUTO_COMPLETED_STATUS);
    }

    public function completeTask(InquiryTask $task, User $actor): InquiryTask
    {
        return $this->updateTaskStatus($task, $this->completionTaskStatus(), $actor);
    }

    /** Supplier bridge task completion: no FlowTracker user is impersonated. */
    public function completeSupplierInquiryTask(InquiryTask $task, string $supplier, int $invitationId): InquiryTask
    {
        abort_if($task->inquiry->result, 409, 'Inquiry is closed.');
        if ($task->completed_at) return $task;
        if ($task->requires_submission && ! $this->taskHasSubmissionEvidence($task)) {
            throw ValidationException::withMessages(['file' => 'Upload the required costing file before completing this task.']);
        }
        $status = $this->completionTaskStatus();
        $oldStatus = (string) $task->status;
        $task->update($this->taskStatusPayload($status, $task) + [
            'completed_at' => now(), 'started_at' => $task->started_at ?: now(),
        ]);
        Inquiry::query()->whereKey($task->inquiry_id)->whereNull('started_at')
            ->update(['started_at' => $task->started_at]);
        $task->inquiry->activities()->create([
            'user_id' => null, 'event' => 'inquiry.task_completed',
            'description' => $task->title.' completed by supplier '.$supplier.'.',
            'meta' => ['inquiry_task_id' => $task->id, 'supplier_invitation_id' => $invitationId,
                'old_status' => $oldStatus, 'to_status' => $status],
        ]);
        $this->forgetMyTaskShell($task->assignee_id ? (int) $task->assignee_id : null);
        $this->syncAutomaticStatus($task->inquiry);
        return $task->refresh();
    }

    public function setInquiryAttentionReason(Inquiry $inquiry, string $reason, User $actor): Inquiry
    {
        abort_unless($this->visibleQuery($actor)->whereKey($inquiry->id)->exists(), 403);
        abort_if($inquiry->result, 422, 'A completed Inquiry cannot be flagged for attention.');

        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            throw ValidationException::withMessages(['inquiryAttentionReason' => 'Write why this Inquiry needs attention.']);
        }
        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['inquiryAttentionReason' => 'The attention reason may not be greater than 2000 characters.']);
        }

        return DB::transaction(function () use ($inquiry, $reason, $actor): Inquiry {
            $locked = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'needs_attention' => true,
                'attention_reason' => $reason,
                'attention_by' => $actor->id,
                'attention_at' => now(),
            ]);

            $commentBody = 'Attention requested: '.$reason;
            $this->activity($locked, $actor, 'inquiry.comment', $commentBody, [
                'comment' => true,
                'attention_reason' => true,
                'attention_scope' => 'inquiry',
            ]);
            $this->notifyMentions($locked, null, $reason, $actor);
            $this->notifyAttentionRecipients($locked, null, $commentBody, $actor);

            return $locked->refresh();
        });
    }

    public function clearInquiryAttention(Inquiry $inquiry, User $actor): Inquiry
    {
        abort_unless($this->visibleQuery($actor)->whereKey($inquiry->id)->exists(), 403);
        abort_if($inquiry->result, 422, 'A completed Inquiry cannot be changed.');

        $inquiry->update([
            'needs_attention' => false,
            'attention_reason' => null,
            'attention_by' => null,
            'attention_at' => null,
        ]);
        $this->activity($inquiry, $actor, 'inquiry.attention_cleared', 'Inquiry attention flag cleared.');
        return $inquiry->refresh();
    }

    public function setTaskAttentionReason(InquiryTask $task, string $reason, User $actor): InquiryTask
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot be edited.');
        abort_unless($task->needs_attention || $this->taskStatusNeedsAttention((string) $task->status), 422, 'This task status does not require attention.');

        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            throw ValidationException::withMessages(['taskAttentionReason' => 'Write the reason why this task requires attention.']);
        }
        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['taskAttentionReason' => 'The attention reason may not be greater than 2000 characters.']);
        }

        return DB::transaction(function () use ($task, $reason, $actor): InquiryTask {
            $task = $this->claimTaskForAction($task, $actor, 'updated the task attention reason');
            $task->update([
                'needs_attention' => true,
                'attention_reason' => $reason,
            ]);

            $commentBody = 'Attention required: '.$reason;
            InquiryTaskComment::create([
                'inquiry_task_id' => $task->id,
                'user_id' => $actor->id,
                'body' => $commentBody,
            ]);

            $this->activity(
                $task->inquiry,
                $actor,
                'inquiry.comment',
                $task->title.' — '.$commentBody,
                [
                    'comment' => true,
                    'inquiry_task_id' => $task->id,
                    'attention_reason' => true,
                ],
            );
            $this->notifyMentions($task->inquiry, $task, $reason, $actor);
            $this->notifyAttentionRecipients($task->inquiry, $task, $task->title.' — '.$commentBody, $actor);

            $task->inquiry->touch();
            $this->forgetMyTaskShell($task->assignee_id ? (int) $task->assignee_id : null);

            return $task->refresh();
        });
    }

    public function appendTask(Inquiry $inquiry, array $data, User $actor): InquiryTask
    {
        $access = app(AccessControlService::class);
        abort_unless($access->canCreateInquiryTask($actor, $inquiry), 403);
        abort_if($inquiry->result, 422, 'A completed Inquiry cannot receive another task.');
        $requestedAssigneeId = (int) (($data['assignee_id'] ?? null) ?: 0);
        if (!$access->can($actor, 'tasks', 'assign') && !$access->isInquiryCreator($actor, $inquiry)) {
            $data['assignee_id'] = $actor->id;
        } elseif ($requestedAssigneeId > 0) {
            abort_unless(User::query()->where('is_active', true)->whereKey($requestedAssigneeId)->exists(), 422, 'Select an active assignee.');
        }

        return DB::transaction(function () use ($inquiry, $data, $actor): InquiryTask {
            $lockedInquiry = Inquiry::query()->whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            abort_if($lockedInquiry->result, 422, 'A completed Inquiry cannot receive another task.');

            $lastSequence = (int) InquiryTask::query()
                ->where('inquiry_id', $lockedInquiry->id)
                ->max('sequence');
            $hasOpenTask = InquiryTask::query()
                ->where('inquiry_id', $lockedInquiry->id)
                ->whereNull('completed_at')
                ->exists();

            $task = InquiryTask::create([
                'inquiry_id' => $lockedInquiry->id,
                'source_task_pack_item_id' => null,
                'assignee_id' => ($data['assignee_id'] ?? null) ?: null,
                'title' => trim((string) ($data['name'] ?? '')),
                'description' => app(RichTextService::class)->normalize($data['description'] ?? null, 10000, 'description'),
                'sequence' => $lastSequence + 1,
                'due_date' => ($data['due_date'] ?? null) ?: null,
                ...$this->defaultTaskStatusPayload(),
                'started_at' => null,
                'requires_submission' => (bool) ($data['requires_submission'] ?? false),
                'submission_label' => blank($data['submission_label'] ?? null) ? null : trim((string) $data['submission_label']),
            ]);

            if (!$hasOpenTask) {
                $this->notifyTaskAssigned($task, $actor);
            }

            $this->activity(
                $lockedInquiry,
                $actor,
                'inquiry.task_added',
                $task->title.' added to the Inquiry taskflow.',
                ['inquiry_task_id' => $task->id],
            );
            if (filled($task->description)) {
                $this->notifyMentions($lockedInquiry, $task, (string) $task->description, $actor);
            }
            $this->syncAutomaticStatus($lockedInquiry, $actor);

            return $task->refresh();
        });
    }

    public function saveWorkflow(Inquiry $inquiry, array $rows, User $actor): void
    {
        abort_unless($this->visibleQuery($actor)->whereKey($inquiry->id)->exists(), 403);
        abort_if($inquiry->result, 422, 'A completed Inquiry taskflow cannot be changed.');
        if ($rows === []) throw ValidationException::withMessages(['workflow' => 'Inquiry taskflow needs at least one task.']);

        DB::transaction(function () use ($inquiry, $rows, $actor): void {
            $existing = $inquiry->tasks()->get()->keyBy('id');
            $access = app(AccessControlService::class);
            foreach ($existing->filter(fn (InquiryTask $task) => $task->completed_at === null) as $openTask) {
                abort_unless($this->canEditTask($actor, $openTask), 403, 'You do not have permission to manage the full Inquiry taskflow.');
            }
            $incomingIds = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id);
            $hasNewTasks = collect($rows)->contains(fn (array $row) => empty($row['id']));
            if ($hasNewTasks) abort_unless($access->can($actor, 'tasks', 'create'), 403);
            foreach ($existing->filter(fn (InquiryTask $task) => $task->completed_at === null && !$incomingIds->contains((int) $task->id)) as $removedTask) {
                abort_unless($access->canDeleteInquiryTask($actor, $removedTask), 403);
            }
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if (!$id || !($task = $existing->get($id)) || $task->completed_at) continue;
                if ((int) ($task->assignee_id ?? 0) !== (int) (($row['assignee_id'] ?? null) ?: 0)) {
                    abort_unless($access->canAssignInquiryTask($actor, $task), 403);
                }
            }
            $completedIds = $existing->filter(fn (InquiryTask $task) => $task->completed_at !== null)->keys()->map(fn ($id) => (int) $id)->all();
            $rowIds = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $active = $existing->filter(fn (InquiryTask $task) => $task->completed_at === null && !$task->trashed())->sortBy('sequence')->first();
            $activeBeforeId = $active?->id ? (int) $active->id : null;
            $activeBeforeAssigneeId = $active?->assignee_id ? (int) $active->assignee_id : null;

            foreach ($completedIds as $completedId) {
                abort_unless(in_array($completedId, $rowIds, true), 422, 'Completed tasks are locked and cannot be removed.');
            }
            if ($active) {
                abort_unless(in_array((int) $active->id, $rowIds, true), 422, 'The active task cannot be removed.');
            }

            // Completed history and the active task cannot be reordered. Only
            // future tasks may move around each other, matching the workflow UI.
            foreach ($existing->filter(fn (InquiryTask $task) => $task->completed_at !== null || ($active && (int) $task->id === (int) $active->id)) as $lockedTask) {
                $submittedIndex = collect($rows)->search(fn (array $row) => (int) ($row['id'] ?? 0) === (int) $lockedTask->id);
                abort_unless($submittedIndex !== false && ((int) $submittedIndex + 1) === (int) $lockedTask->sequence, 422, 'Completed and active tasks cannot be reordered.');
            }

            // Move current sequences away first so the unique constraint never
            // collides while future tasks are reordered.
            $inquiry->tasks()->update(['sequence' => DB::raw('sequence + 10000')]);

            foreach (array_values($rows) as $index => $row) {
                $id = (int) ($row['id'] ?? 0);
                $task = $id ? $existing->get($id) : null;
                if ($task && $task->completed_at) {
                    $task->restore();
                    $task->update(['sequence' => $index + 1]);
                    continue;
                }

                $payload = [
                    'source_task_pack_item_id' => ($row['source_id'] ?? null) ?: null,
                    // Preserve the source Workflow phase for existing rows. Rows
                    // originating from workflowRows() carry phase_id explicitly;
                    // manually appended tasks remain unassigned to a setup phase.
                    'source_workflow_phase_id' => ($row['phase_id'] ?? $task?->source_workflow_phase_id ?? null) ?: null,
                    'setup_assignee_id' => $task?->setup_assignee_id ?: (($row['setup_assignee_id'] ?? null) ?: null),
                    'assignee_id' => ($row['assignee_id'] ?? null) ?: null,
                    'title' => trim((string) $row['name']),
                    'description' => app(RichTextService::class)->normalize($row['description'] ?? null, 10000, 'description'),
                    'sequence' => $index + 1,
                    'due_date' => ($row['due_date'] ?? null) ?: null,
                    'requires_submission' => (bool) ($row['requires_submission'] ?? false),
                    'submission_label' => blank($row['submission_label'] ?? null) ? null : trim((string) $row['submission_label']),
                ];

                if ($task) {
                    $task->restore();
                    $task->update($payload);
                } else {
                    InquiryTask::create($payload + ['inquiry_id' => $inquiry->id] + $this->defaultTaskStatusPayload());
                }
            }

            $kept = collect($rows)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
            $inquiry->tasks()->whereNotIn('id', $kept ?: [0])->whereNull('completed_at')->get()->each->forceDelete();
            $this->normalizeTaskStates($inquiry);
            $activeAfter = $inquiry->tasks()->whereNull('completed_at')->orderBy('sequence')->first();
            if ($activeAfter && ((int) $activeAfter->id !== (int) $activeBeforeId || (int) $activeAfter->assignee_id !== (int) $activeBeforeAssigneeId)) {
                $this->forgetMyTaskShell($activeBeforeAssigneeId);
                $this->notifyTaskAssigned($activeAfter, $actor);
            }
            $this->activity($inquiry, $actor, 'inquiry.workflow_updated', 'Inquiry taskflow updated. It now contains '.count($rows).' tasks.');
        });
    }

    public function upload(
        Inquiry $inquiry,
        UploadedFile $file,
        User $actor,
        ?InquiryTask $task = null,
        ?string $note = null,
        bool $completeRequiredTask = true,
    ): InquiryDocument {
        abort_unless(app(AccessControlService::class)->can($actor, 'documents', 'create'), 403);
        abort_unless($this->canEdit($actor, $inquiry) || ($task && $this->canEditTask($actor, $task)), 403);
        if ($task) {
            abort_unless((int) $task->inquiry_id === (int) $inquiry->id, 422);
            abort_if($task->inquiry?->result, 422, 'Tasks on a closed Inquiry cannot receive documents.');
            abort_if(
                $task->documents()->count() >= InquiryTask::MAX_DOCUMENTS,
                422,
                'A maximum of '.InquiryTask::MAX_DOCUMENTS.' documents can be attached to one Inquiry task.'
            );
            $task = $this->claimTaskForAction($task, $actor, 'uploaded a task document');
            // Documents added to an already-completed task remain evidence only.
            // Batch uploads may defer completion until every selected document
            // has been stored successfully, preventing partial batches from
            // completing a required-document task after only the first file.
        }

        $stored = app(SecureDocumentStorage::class)->store($file, 'flowtrack/inquiries/'.$inquiry->id);
        $path = $stored['path'];

        $document = InquiryDocument::create([
            'inquiry_id' => $inquiry->id,
            'inquiry_task_id' => $task?->id,
            'uploaded_by' => $actor->id,
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => StoredFileResponse::mimeType($file->getClientOriginalName(), $stored['mime']),
            'size' => $stored['size'],
            'note' => filled($note) ? trim((string) $note) : null,
        ]);

        $this->activity(
            $inquiry,
            $actor,
            $task ? 'inquiry.task_document_uploaded' : 'inquiry.document_uploaded',
            $document->name.' uploaded'.($task ? ' to '.$task->title : ' directly to the Inquiry').'.',
            ['inquiry_task_id' => $task?->id, 'inquiry_document_id' => $document->id],
        );

        if ($completeRequiredTask
            && $task
            && (bool) $task->requires_submission
            && ! $task->completed_at) {
            // The document record now satisfies the service-level completion
            // guard. Complete through the normal task transition so timestamps,
            // activity, metrics inputs and the parent Inquiry status stay aligned.
            $this->completeTask($task->fresh(), $actor);
        }

        return $document;
    }

    public function linkExistingDocument(Inquiry $inquiry, Document $source, User $actor): InquiryDocument
    {
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_unless(app(AccessControlService::class)->can($actor, 'documents', 'link'), 403);
        app(AccessControlService::class)->applyDocumentScope(Document::query()->whereKey($source->id), $actor)->firstOrFail();
        abort_unless((int) ($source->client_id ?? 0) === (int) $inquiry->client_id, 403, 'The selected document does not belong to this client.');

        $document = InquiryDocument::create([
            'inquiry_id' => $inquiry->id,
            'inquiry_task_id' => null,
            'uploaded_by' => $actor->id,
            'name' => $source->name,
            'path' => $source->path,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
        ]);

        $this->activity($inquiry, $actor, 'inquiry.document_linked', $document->name.' linked from Documents.', [
            'inquiry_document_id' => $document->id,
            'source_document_id' => $source->id,
        ]);

        return $document;
    }

    public function linkExistingDocumentToTask(InquiryTask $task, Document $source, User $actor, ?string $note = null): InquiryDocument
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot receive documents.');
        abort_unless(app(AccessControlService::class)->can($actor, 'documents', 'link'), 403);
        app(AccessControlService::class)->applyDocumentScope(Document::query()->whereKey($source->id), $actor)->firstOrFail();
        abort_unless((int) ($source->client_id ?? 0) === (int) $task->inquiry->client_id, 403, 'The selected document does not belong to this client.');

        abort_if(
            $task->documents()->count() >= InquiryTask::MAX_DOCUMENTS,
            422,
            'A maximum of '.InquiryTask::MAX_DOCUMENTS.' documents can be attached to one Inquiry task.'
        );

        $task = $this->claimTaskForAction($task, $actor, 'linked a task document');

        $document = InquiryDocument::create([
            'inquiry_id' => $task->inquiry_id,
            'inquiry_task_id' => $task->id,
            'uploaded_by' => $actor->id,
            'name' => $source->name,
            'path' => $source->path,
            'mime_type' => $source->mime_type,
            'size' => $source->size,
            'note' => filled($note) ? trim((string) $note) : null,
        ]);

        $this->activity(
            $task->inquiry,
            $actor,
            'inquiry.task_document_linked',
            $document->name.' linked to '.$task->title.' from Documents.',
            [
                'inquiry_task_id' => $task->id,
                'inquiry_document_id' => $document->id,
                'source_document_id' => $source->id,
            ],
        );

        return $document;
    }

    public function addTaskLink(InquiryTask $task, string $url, User $actor): InquiryTaskLink
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot receive links.');

        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw ValidationException::withMessages(['taskLinkUrl' => 'Enter a valid http:// or https:// link.']);
        }

        $task = $this->claimTaskForAction($task, $actor, 'added an external link');

        $link = $task->links()->create([
            'created_by' => $actor->id,
            'url' => $url,
        ]);

        $this->activity(
            $task->inquiry,
            $actor,
            'inquiry.task_link_added',
            'External link added to '.$task->title.'.',
            ['inquiry_task_id' => $task->id, 'inquiry_task_link_id' => $link->id, 'url' => $url],
        );

        return $link->refresh();
    }

    public function removeTaskLink(InquiryTask $task, int $linkId, User $actor): bool
    {
        $task->loadMissing('inquiry');
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot change links.');

        return DB::transaction(function () use ($task, $linkId, $actor): bool {
            $lockedTask = InquiryTask::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->with('inquiry')
                ->firstOrFail();

            abort_unless($this->canEditTask($actor, $lockedTask), 403);
            abort_if($lockedTask->inquiry->result, 422, 'Tasks on a closed Inquiry cannot change links.');

            $lockedTask = $this->claimTaskForAction($lockedTask, $actor, 'removed an external link');
            $link = $lockedTask->links()->whereKey($linkId)->firstOrFail();
            $url = (string) $link->url;
            $wasCompleted = $lockedTask->completed_at !== null
                || $this->isCompletionTaskStatus((string) $lockedTask->status);

            $link->delete();

            $mustReopen = $wasCompleted
                && (bool) $lockedTask->requires_submission
                && ! $this->taskHasSubmissionEvidence($lockedTask);

            if ($mustReopen) {
                $lockedTask->update([
                    'status' => $this->resumeTaskStatus(),
                    'completed_at' => null,
                ]);
                $this->forgetMyTaskShell($lockedTask->assignee_id ? (int) $lockedTask->assignee_id : null);
                $this->activity(
                    $lockedTask->inquiry,
                    $actor,
                    'inquiry.task_reopened',
                    $lockedTask->title.' reopened because its final required file/link was removed.',
                    [
                        'inquiry_task_id' => $lockedTask->id,
                        'removed_inquiry_task_link_id' => $linkId,
                        'old_status' => self::AUTO_COMPLETED_STATUS,
                        'to_status' => (string) $lockedTask->status,
                    ],
                );
            }

            $lockedTask->inquiry->touch();
            $this->activity(
                $lockedTask->inquiry,
                $actor,
                'inquiry.task_link_removed',
                'External link removed from '.$lockedTask->title.'.',
                [
                    'inquiry_task_id' => $lockedTask->id,
                    'inquiry_task_link_id' => $linkId,
                    'url' => $url,
                    'task_reopened' => $mustReopen,
                ],
            );

            if ($mustReopen) {
                $this->syncAutomaticStatus($lockedTask->inquiry, $actor);
            }

            return $mustReopen;
        });
    }

    public function removeDocument(Inquiry $inquiry, int $documentId, User $actor): void
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'documents', 'delete'), 403);
        abort_unless($this->canEdit($actor, $inquiry), 403);

        $document = $inquiry->documents()->whereKey($documentId)->firstOrFail();
        $path = (string) $document->path;
        $name = (string) $document->name;
        $document->delete();

        if ($path !== ''
            && ! Document::query()->where('path', $path)->exists()
            && ! InquiryDocument::query()->where('path', $path)->exists()) {
            app(SecureDocumentStorage::class)->delete($path);
        }

        $this->activity($inquiry, $actor, 'inquiry.document_removed', $name.' removed from the Inquiry.');
    }

    public function removeTaskDocument(InquiryTask $task, int $documentId, User $actor): bool
    {
        $task->loadMissing('inquiry');
        abort_unless(app(AccessControlService::class)->can($actor, 'documents', 'delete'), 403);
        abort_unless($this->canEditTask($actor, $task), 403);
        abort_if($task->inquiry->result, 422, 'Tasks on a closed Inquiry cannot change documents.');

        $path = '';
        $name = '';
        $reopened = DB::transaction(function () use ($task, $documentId, $actor, &$path, &$name): bool {
            // Lock the task so a simultaneous completion/document change cannot leave
            // a required-file task completed without its required submission.
            $lockedTask = InquiryTask::query()
                ->whereKey($task->id)
                ->lockForUpdate()
                ->with('inquiry')
                ->firstOrFail();

            abort_unless($this->canEditTask($actor, $lockedTask), 403);
            abort_if($lockedTask->inquiry->result, 422, 'Tasks on a closed Inquiry cannot change documents.');

            $lockedTask = $this->claimTaskForAction($lockedTask, $actor, 'removed a task document');
            $document = $lockedTask->documents()->whereKey($documentId)->firstOrFail();
            $path = (string) $document->path;
            $name = (string) $document->name;
            $wasCompleted = $lockedTask->completed_at !== null
                || strcasecmp(trim((string) $lockedTask->status), self::AUTO_COMPLETED_STATUS) === 0;

            $document->delete();

            $mustReopen = $wasCompleted
                && (bool) $lockedTask->requires_submission
                && ! $this->taskHasSubmissionEvidence($lockedTask);

            if ($mustReopen) {
                // Required submission evidence is a completion invariant. Users may remove files
                // after completion, but removing the final file/link evidence reopens the
                // task so the UI and business state never disagree.
                $lockedTask->update([
                    'status' => $this->resumeTaskStatus(),
                    'completed_at' => null,
                ]);
                $this->forgetMyTaskShell($lockedTask->assignee_id ? (int) $lockedTask->assignee_id : null);
                $this->activity(
                    $lockedTask->inquiry,
                    $actor,
                    'inquiry.task_reopened',
                    $lockedTask->title.' reopened because its final required file/link evidence was removed.',
                    [
                        'inquiry_task_id' => $lockedTask->id,
                        'removed_inquiry_document_id' => $documentId,
                        'old_status' => self::AUTO_COMPLETED_STATUS,
                        'to_status' => (string) $lockedTask->status,
                    ],
                );
            }

            // Touch the Inquiry for realtime/dashboard invalidation even when the
            // task remains completed (for example, deleting one of several files).
            $lockedTask->inquiry->touch();

            $this->activity(
                $lockedTask->inquiry,
                $actor,
                'inquiry.task_document_removed',
                $name.' removed from '.$lockedTask->title.'.',
                [
                    'inquiry_task_id' => $lockedTask->id,
                    'inquiry_document_id' => $documentId,
                    'task_reopened' => $mustReopen,
                ],
            );

            if ($mustReopen) {
                $this->syncAutomaticStatus($lockedTask->inquiry, $actor);
            }

            return $mustReopen;
        });

        // Delete the physical file only when no other FlowTrack document points
        // to the same path. Database state has already committed successfully.
        if ($path !== ''
            && ! Document::query()->where('path', $path)->exists()
            && ! InquiryDocument::query()->where('path', $path)->exists()) {
            app(SecureDocumentStorage::class)->delete($path);
        }

        return $reopened;
    }

    public function addInquiryComment(Inquiry $inquiry, string $body, User $actor): Activity
    {
        abort_unless($this->visibleQuery($actor)->whereKey($inquiry->id)->exists(), 403);
        $body = app(RichTextService::class)->normalize($body, 5000, 'inquiryComment');
        abort_if(!$body, 422, 'Comment cannot be empty.');
        $activity = $this->activity($inquiry, $actor, 'inquiry.comment', $body, ['comment' => true]);
        $this->notifyMentions($inquiry, null, $body, $actor);
        return $activity;
    }

    public function addTaskComment(InquiryTask $task, string $body, User $actor): InquiryTaskComment
    {
        $body = app(RichTextService::class)->normalize($body, 5000, 'taskComment');
        abort_if(!$body, 422, 'Comment cannot be empty.');
        $task->loadMissing('inquiry');
        abort_unless($this->visibleQuery($actor)->whereKey($task->inquiry_id)->exists(), 403);
        abort_if(!$task->completed_at && !$this->isActiveTask($task), 422, 'Future task comments stay locked until the task starts.');

        $task = $this->claimTaskForAction($task, $actor, 'added a comment');

        $comment = InquiryTaskComment::create([
            'inquiry_task_id' => $task->id,
            'user_id' => $actor->id,
            'body' => $body,
        ]);
        $this->activity($task->inquiry, $actor, 'inquiry.task_comment', $body, ['inquiry_task_id' => $task->id, 'inquiry_task_comment_id' => $comment->id]);
        $this->notifyMentions($task->inquiry, $task, $body, $actor);
        return $comment;
    }

    public function documentsPage(User $user, Inquiry $inquiry, int $perPage = 50): LengthAwarePaginator
    {
        abort_unless(app(AccessControlService::class)->can($user, 'documents', 'view'), 403);
        abort_unless($this->visibleQuery($user)->whereKey($inquiry->id)->exists(), 403);
        return InquiryDocument::query()
            ->where('inquiry_id', $inquiry->id)
            ->with(['task:id,title', 'uploader:id,name'])
            ->latest('id')
            ->paginate(max(1, min(100, $perPage)), ['*'], 'inquiryDocumentsPage');
    }

    public function activityPage(User $user, Inquiry $inquiry, int $perPage = 30, string $tab = 'all'): LengthAwarePaginator
    {
        abort_unless($this->visibleQuery($user)->whereKey($inquiry->id)->exists(), 403);
        $query = Activity::query()
            ->where('subject_type', Inquiry::class)
            ->where('subject_id', $inquiry->id)
            ->with('user:id,name,profile_image_path');

        if ($tab === 'comments') $query->where('event', 'inquiry.comment');
        if ($tab === 'history') $query->where('event', '!=', 'inquiry.comment');

        return $query->latest('id')
            ->paginate(max(1, min(60, $perPage)), ['*'], 'inquiryActivityPage');
    }

    public function findVisibleTask(User $user, int $taskId, array $with = []): InquiryTask
    {
        return app(AccessControlService::class)
            ->applyInquiryTaskScope(InquiryTask::query(), $user)
            ->with($with)
            ->findOrFail($taskId);
    }

    public function taskDetail(User $user, int $taskId): InquiryTask
    {
        return $this->findVisibleTask($user, $taskId, [
            'inquiry:id,inquiry_number,owner_id,status,result',
            'assignee:id,name,profile_image_path',
            'documents' => fn ($q) => $q->with('uploader:id,name')->limit(50),
            'links:id,inquiry_task_id,created_by,url,created_at',
            'comments' => fn ($q) => $q->with('user:id,name,profile_image_path')->limit(50),
        ]);
    }

    public function convertToOrder(Inquiry $inquiry, User $actor): FlowJob
    {
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_unless(app(AccessControlService::class)->can($actor, 'jobs', 'create'), 403, 'You need Order create access to convert this Inquiry.');
        abort_if($inquiry->result, 422, 'This Inquiry already has a final result.');
        abort_if($inquiry->converted_job_id || $inquiry->sourceOrder()->exists() || $inquiry->linkedOrders()->exists(), 422, 'This Inquiry is already linked to an Order. Unlink it before creating another Order from it.');
        abort_if($inquiry->tasks()->whereNull('completed_at')->exists(), 422, 'Complete every Inquiry taskflow task first.');

        $template = WorkflowTemplate::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_active', true)
            ->availableFor('orders', (int) $inquiry->client_id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
        abort_unless($template, 422, 'No active Order workflow is available for this client.');

        $workflow = Workflow::query()->whereKey($template->id)->where('is_snapshot', false)->where('is_active', true)->firstOrFail();
        $phase = $workflow->phases()->where('is_active', true)->where('allow_job_start', true)->orderBy('sequence')->first();
        abort_unless($phase, 422, 'The default Order workflow has no phase that allows a Job start.');

        $inquiry->loadMissing(['items', 'client']);
        $first = $inquiry->items->first();
        $canAssign = app(AccessControlService::class)->can($actor, 'jobs', 'assign');
        $ownerId = $canAssign && $inquiry->owner_id ? $inquiry->owner_id : $actor->id;

        return DB::transaction(function () use ($inquiry, $actor, $workflow, $phase, $first, $ownerId): FlowJob {
            $job = app(JobService::class)->create([
                'title' => $inquiry->subject,
                'product' => $first?->item_name ?: $inquiry->subject,
                'category' => $first?->category,
                'quantity' => (int) round((float) $inquiry->items->sum('quantity')),
                'items' => $inquiry->items->map(fn (InquiryItem $item) => [
                    'product' => $item->item_name,
                    'category' => $item->category,
                    'quantity' => max(1, (int) round((float) $item->quantity)),
                    'unit_price' => $item->unit_price !== null ? round((float) $item->unit_price, 2) : 0,
                ])->all(),
                'priority' => $inquiry->priority,
                'client_id' => $inquiry->client_id,
                'workflow_id' => $workflow->id,
                'workflow_phase_id' => $phase->id,
                'owner_id' => $ownerId,
                'coordinator_id' => $ownerId,
                'delivery_date' => $inquiry->required_delivery_date?->toDateString(),
                'description' => app(RichTextService::class)->prependText(
                    $inquiry->reference_number ? 'Source Inquiry: '.$inquiry->inquiry_number.' · Reference '.$inquiry->reference_number : 'Source Inquiry: '.$inquiry->inquiry_number,
                    $inquiry->requirement_notes,
                ),
                'draft' => false,
            ], $actor);

            $job->update(['source_inquiry_id' => $inquiry->id, 'currency' => $inquiry->currency ?: 'USD']);
            $job->linkedInquiries()->syncWithoutDetaching([
                $inquiry->id => [
                    'linked_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
            $inquiry->update([
                'result' => 'converted',
                'status' => 'Converted',
                'converted_job_id' => $job->id,
                'completed_at' => now(),
            ]);
            $this->activity($inquiry, $actor, 'inquiry.converted', $job->displayOrderNumber().' created from this Inquiry.');
            return $job->refresh();
        });
    }

    public function markDead(Inquiry $inquiry, string $reason, ?string $note, User $actor): Inquiry
    {
        abort_unless($this->canEdit($actor, $inquiry), 403);
        abort_if($inquiry->result, 422, 'This Inquiry already has a final result.');
        abort_if($inquiry->tasks()->whereNull('completed_at')->exists(), 422, 'Complete every Inquiry taskflow task first.');

        $inquiry->update([
            'result' => 'dead',
            'status' => 'Dead',
            'dead_reason' => $reason,
            'dead_note' => blank($note) ? null : trim((string) $note),
            'completed_at' => now(),
        ]);
        $this->activity($inquiry, $actor, 'inquiry.dead', 'Inquiry closed. Reason: '.$reason.'.'.(filled($note) ? ' '.trim((string) $note) : ''));
        return $inquiry->refresh();
    }

    public function isActiveTask(InquiryTask $task): bool
    {
        if ($task->completed_at) return false;
        $firstOpenId = InquiryTask::query()
            ->where('inquiry_id', $task->inquiry_id)
            ->whereNull('completed_at')
            ->orderBy('sequence')
            ->value('id');
        return (int) $firstOpenId === (int) $task->id;
    }

    public function canEditTask(User $user, InquiryTask $task): bool
    {
        return app(AccessControlService::class)->canEditInquiryTask($user, $task);
    }


    /**
     * Personal Inquiry-task scope used only by My Tasks.
     *
     * Keep the normal Inquiry/task permission scope, then always narrow it to
     * the authenticated assignee. This prevents Admin/Super Admin, Inquiry
     * creators, department access, or all-record scopes from turning My Tasks
     * into a global Inquiry task list.
     */
    private function assignedInquiryTaskQueryForMyWork(User $user): Builder
    {
        return app(AccessControlService::class)
            ->applyInquiryTaskScope(InquiryTask::query(), $user)
            ->where('inquiry_tasks.assignee_id', $user->id);
    }

    public function myTaskGroups(User $user, array $filters, int $limit = 3): Collection
    {
        $access = app(AccessControlService::class);
        if (!$access->can($user, 'inquiries', 'view') || !$access->can($user, 'tasks', 'view')) return collect();

        $today = app(WorkspaceSettingsService::class)->localToday();
        $todayDate = $today->toDateString();
        $tomorrow = $today->copy()->addDay()->toDateString();
        $weekEnd = $today->copy()->addDays(7)->toDateString();
        $quick = (string) ($filters['quick'] ?? 'all');
        $search = trim((string) ($filters['search'] ?? ''));
        $statusFilter = trim((string) ($filters['status'] ?? ''));

        $visibleInquiries = $this->visibleQuery($user)->where('inquiries.status', '!=', 'Draft');
        if ($statusFilter === '') {
            $visibleInquiries->whereNull('result');
        }

        $query = $this->assignedInquiryTaskQueryForMyWork($user)
            ->whereIn('inquiry_tasks.inquiry_id', $visibleInquiries->select('inquiries.id'));

        if ($statusFilter !== '') {
            $query->whereRaw('LOWER(TRIM(inquiry_tasks.status)) = ?', [mb_strtolower($statusFilter)]);
        } else {
            $query
                ->whereNull('inquiry_tasks.completed_at')
                ->whereNotExists(function ($earlier): void {
                    $earlier->selectRaw('1')
                        ->from('inquiry_tasks as earlier_inquiry_tasks')
                        ->whereColumn('earlier_inquiry_tasks.inquiry_id', 'inquiry_tasks.inquiry_id')
                        ->whereColumn('earlier_inquiry_tasks.sequence', '<', 'inquiry_tasks.sequence')
                        ->whereNull('earlier_inquiry_tasks.completed_at')
                        ->whereNull('earlier_inquiry_tasks.deleted_at');
                });
        }


        if ($search !== '' && mb_strlen($search) >= 2) {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $match) => $match
                ->whereLike('inquiry_tasks.title', $like)
                ->orWhereHas('assignee', fn (Builder $assignee) => $assignee->whereLike('name', $like))
                ->orWhereHas('inquiry', fn (Builder $inquiry) => $inquiry
                    ->whereLike('inquiry_number', $like)
                    ->orWhereLike('subject', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like))));
        }

        match ($quick) {
            'attention' => $query->where('inquiry_tasks.needs_attention', true),
            'overdue' => $query->where('inquiry_tasks.due_date', '<', $todayDate),
            'today' => $query->whereDate('inquiry_tasks.due_date', $todayDate),
            'upcoming' => $query->whereBetween('inquiry_tasks.due_date', [$tomorrow, $weekEnd])->whereRaw("LOWER(inquiry_tasks.status) NOT LIKE 'waiting%'"),
            'waiting' => $query->whereRaw("LOWER(inquiry_tasks.status) LIKE 'waiting%'"),
            'mentions' => $query->whereExists(fn ($notification) => $notification
                ->selectRaw('1')->from('flow_notifications')
                ->whereColumn('flow_notifications.inquiry_task_id', 'inquiry_tasks.id')
                ->where('flow_notifications.user_id', $user->id)
                ->where('flow_notifications.type', 'mention')),
            default => null,
        };

        match ((string) ($filters['sort'] ?? 'action')) {
            'due' => $query->orderByRaw('inquiry_tasks.due_date is null')->orderBy('inquiry_tasks.due_date')->orderBy('inquiry_tasks.id'),
            'job' => $query->orderBy('inquiry_tasks.inquiry_id')->orderBy('inquiry_tasks.sequence'),
            default => $query
                ->orderByRaw("CASE WHEN inquiry_tasks.due_date < ? THEN 0 WHEN inquiry_tasks.due_date = ? THEN 1 ELSE 2 END", [$todayDate, $todayDate])
                ->orderByRaw('inquiry_tasks.due_date is null')->orderBy('inquiry_tasks.due_date')->orderBy('inquiry_tasks.id'),
        };

        $tasks = $query
            ->with([
                'assignee:id,name,profile_image_path',
                'inquiry:id,inquiry_number,client_id,subject,status,priority,updated_at',
                'inquiry.client:id,name,logo_path',
            ])
            ->limit(max(1, min($statusFilter !== '' ? 80 : 6, $limit)))
            ->get(['id', 'inquiry_id', 'assignee_id', 'title', 'status', 'needs_attention', 'attention_reason', 'due_date', 'sequence', 'updated_at']);

        $inquiryIds = $tasks->pluck('inquiry_id')->unique()->values();
        $counts = $inquiryIds->isEmpty() ? collect() : InquiryTask::query()
            ->whereIn('inquiry_id', $inquiryIds)
            ->select('inquiry_id')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) AS completed_count')
            ->groupBy('inquiry_id')
            ->get()->keyBy('inquiry_id');
        $displayTimezone = app(WorkspaceSettingsService::class)->displayTimezone();

        $groups = $tasks->map(function (InquiryTask $task) use ($counts, $user, $displayTimezone, $todayDate): array {
            $inquiry = $task->inquiry;
            $count = $counts->get($task->inquiry_id);
            $total = (int) ($count?->total_count ?? 0);
            $done = (int) ($count?->completed_count ?? 0);
            $dueDate = $task->due_date?->toDateString();
            $dueTone = 'normal';
            $due = $task->due_date?->format('M j') ?: 'No due date';
            if ($dueDate && $dueDate < $todayDate) { $dueTone = 'overdue'; $due = 'Overdue'; }
            elseif ($dueDate === $todayDate) { $dueTone = 'today'; $due = 'Today'; }
            $flag = $task->needs_attention ? 'Requires attention' : ($dueTone === 'overdue' ? 'Overdue' : ($dueTone === 'today' ? 'Due Today' : 'No flag'));
            $updatedAt = $task->updated_at?->copy()->setTimezone($displayTimezone);

            return [
                'id' => (int) $inquiry->id,
                'number' => (string) $inquiry->inquiry_number,
                'title' => (string) $inquiry->subject,
                'client' => (string) ($inquiry->client?->name ?: 'No client'),
                'stage' => 'Inquiry',
                'health' => (string) ($inquiry->status ?: 'In Progress'),
                'healthTone' => $this->statusTone((string) $inquiry->status),
                'healthColor' => $this->inquiryStatusColor((string) $inquiry->status, (string) $task->status),
                'progress' => $total ? (int) round($done / $total * 100) : 0,
                'taskCount' => 1,
                'route' => route('inquiries.index', ['open' => $inquiry->id]),
                'tasks' => collect([[
                    'id' => (int) $task->id,
                    'kind' => 'inquiry',
                    'number' => 'INQ-TASK-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT),
                    'title' => (string) $task->title,
                    'phase' => 'Inquiry',
                    'assignee' => (string) ($task->assignee?->name ?: 'Unassigned'),
                    'assigneeId' => $task->assignee_id ? (int) $task->assignee_id : null,
                    'assigneeAvatar' => $task->assignee?->profileImageUrl(),
                    'due' => $due,
                    'dueValue' => $dueDate ?: '',
                    'dueDisplay' => $task->due_date?->format('M j, Y') ?? 'Set due date',
                    'dueTone' => $dueTone,
                    'status' => (string) $task->status,
                    'statusColor' => app(MasterDataService::class)->colorFor('inquiry_task_status', (string) $task->status),
                    'flag' => $flag,
                    'flagReason' => $task->needs_attention ? (string) ($task->attention_reason ?: '') : '',
                    'flagTone' => in_array($flag, ['Overdue', 'Requires attention'], true) ? 'red' : ($flag === 'Due Today' ? 'amber' : 'green'),
                    'flagColor' => null,
                    'updated' => $updatedAt?->diffForHumans() ?: '—',
                    'version' => (string) $task->getRawOriginal('updated_at'),
                    'canEdit' => $this->canEditTask($user, $task),
                    'route' => route('inquiries.index', ['open' => $inquiry->id, 'task' => $task->id]),
                ]]),
            ];
        })->values();

        if ($statusFilter === '') return $groups;

        return $groups
            ->groupBy('id')
            ->map(function (Collection $rows): array {
                $group = $rows->first();
                $group['tasks'] = $rows->flatMap(fn (array $row) => $row['tasks'])->values();
                $group['taskCount'] = $group['tasks']->count();
                return $group;
            })
            ->values();
    }

    public function myTaskMetrics(User $user): array
    {
        $access = app(AccessControlService::class);
        if (!$access->can($user, 'inquiries', 'view') || !$access->can($user, 'tasks', 'view')) return ['attention'=>0,'overdue'=>0,'today'=>0,'upcoming'=>0,'waiting'=>0,'mentions'=>0];
        $today = app(WorkspaceSettingsService::class)->localToday();
        $todayDate = $today->toDateString();
        $tomorrow = $today->copy()->addDay()->toDateString();
        $weekEnd = $today->copy()->addDays(7)->toDateString();
        $base = $this->assignedInquiryTaskQueryForMyWork($user)
            ->whereNull('inquiry_tasks.completed_at')
            ->whereIn('inquiry_tasks.inquiry_id', $this->visibleQuery($user)->whereNull('result')->where('inquiries.status', '!=', 'Draft')->select('inquiries.id'))
            ->whereNotExists(function ($earlier): void {
                $earlier->selectRaw('1')->from('inquiry_tasks as earlier_inquiry_tasks')
                    ->whereColumn('earlier_inquiry_tasks.inquiry_id', 'inquiry_tasks.inquiry_id')
                    ->whereColumn('earlier_inquiry_tasks.sequence', '<', 'inquiry_tasks.sequence')
                    ->whereNull('earlier_inquiry_tasks.completed_at')->whereNull('earlier_inquiry_tasks.deleted_at');
            });
        $row = (clone $base)->selectRaw('SUM(CASE WHEN needs_attention = 1 THEN 1 ELSE 0 END) attention_count')
            ->selectRaw('SUM(CASE WHEN due_date < ? THEN 1 ELSE 0 END) overdue_count', [$todayDate])
            ->selectRaw('SUM(CASE WHEN due_date = ? THEN 1 ELSE 0 END) today_count', [$todayDate])
            ->selectRaw("SUM(CASE WHEN due_date BETWEEN ? AND ? AND LOWER(status) NOT LIKE 'waiting%' THEN 1 ELSE 0 END) upcoming_count", [$tomorrow, $weekEnd])
            ->selectRaw("SUM(CASE WHEN LOWER(status) LIKE 'waiting%' THEN 1 ELSE 0 END) waiting_count")
            ->first();
        $mentions = (clone $base)->whereExists(fn ($notification) => $notification
            ->selectRaw('1')->from('flow_notifications')
            ->whereColumn('flow_notifications.inquiry_task_id', 'inquiry_tasks.id')
            ->where('flow_notifications.user_id', $user->id)->where('flow_notifications.type', 'mention'))->count();
        return [
            'attention'=>(int)($row?->attention_count??0), 'overdue'=>(int)($row?->overdue_count??0),
            'today'=>(int)($row?->today_count??0), 'upcoming'=>(int)($row?->upcoming_count??0),
            'waiting'=>(int)($row?->waiting_count??0), 'mentions'=>(int)$mentions,
        ];
    }

    public function openMyTaskCount(User $user): int
    {
        $access = app(AccessControlService::class);
        if (!$access->can($user, 'inquiries', 'view') || !$access->can($user, 'tasks', 'view')) return 0;
        $query = $this->assignedInquiryTaskQueryForMyWork($user)
            ->whereNull('inquiry_tasks.completed_at')
            ->whereIn('inquiry_tasks.inquiry_id', $this->visibleQuery($user)->whereNull('result')->where('inquiries.status', '!=', 'Draft')->select('inquiries.id'))
            ->whereNotExists(function ($earlier): void {
                $earlier->selectRaw('1')->from('inquiry_tasks as earlier_inquiry_tasks')
                    ->whereColumn('earlier_inquiry_tasks.inquiry_id', 'inquiry_tasks.inquiry_id')
                    ->whereColumn('earlier_inquiry_tasks.sequence', '<', 'inquiry_tasks.sequence')
                    ->whereNull('earlier_inquiry_tasks.completed_at')->whereNull('earlier_inquiry_tasks.deleted_at');
            });
        return $query->count();
    }

    private function statusTone(string $value): string
    {
        $value = strtolower($value);
        if (str_contains($value, 'dead') || str_contains($value, 'blocked')) return 'red';
        if (str_contains($value, 'waiting') || str_contains($value, 'hold') || str_contains($value, 'ready')) return 'amber';
        if (str_contains($value, 'converted') || str_contains($value, 'complete')) return 'green';
        return 'blue';
    }

    private function normalizeTaskStates(Inquiry $inquiry): void
    {
        $open = $inquiry->tasks()->whereNull('completed_at')->orderBy('sequence')->get();
        foreach ($open as $index => $task) {
            if ($index === 0) {
                // Preserve a status already chosen on a started task even when
                // that Master Data value is later deactivated/deleted.
                $payload = $task->started_at && trim((string) $task->status) !== ''
                    ? $this->taskStatusPayload((string) $task->status, $task)
                    : $this->defaultTaskStatusPayload($task);
                $task->update($payload);
                continue;
            }

            // Future tasks use the workspace's current Master Data default.
            if (!$task->started_at) $task->update($this->defaultTaskStatusPayload($task));
        }

        $this->syncAutomaticStatus($inquiry);
    }

    public function syncAutomaticStatus(Inquiry $inquiry, ?User $actor = null): Inquiry
    {
        $inquiry->refresh();
        if ($inquiry->result || (string) $inquiry->status === 'Draft') return $inquiry;

        $tasks = $inquiry->tasks()
            ->orderBy('sequence')
            ->get(['id', 'status', 'sequence', 'started_at', 'completed_at']);

        $total = $tasks->count();
        $isCompleted = fn (InquiryTask $task): bool => $task->completed_at !== null
            || $this->isCompletionTaskStatus((string) $task->status);
        $completed = $tasks->filter($isCompleted)->count();
        $openTasks = $tasks->reject($isCompleted)->values();
        $workingTask = $openTasks->first(
            fn (InquiryTask $task): bool => $this->isWorkingTaskStatus((string) $task->status)
        );

        // Parent Inquiry progress must follow the task statuses that users can see,
        // not historical started_at timestamps. A task keeps its first started_at
        // timestamp for audit/history even after it is moved back to Not Started;
        // therefore started_at alone must never keep the parent Inquiry In Progress.
        // Completed tasks still count as real workflow progress so advancing to the
        // next Not Started task does not incorrectly regress the parent to To do.
        $hasProgress = $completed > 0 || $workingTask !== null;
        $currentTask = $workingTask ?: $openTasks->sortBy('sequence')->first();

        if ($total > 0 && $completed === $total) {
            $lastTask = $tasks->sortByDesc('sequence')->first();
            $nextStatus = $lastTask
                ? $this->autoInquiryStatusForTaskStatus((string) $lastTask->status)
                : self::AUTO_COMPLETED_STATUS;
            if (strcasecmp($nextStatus, self::AUTO_COMPLETED_STATUS) !== 0) {
                $nextStatus = self::AUTO_COMPLETED_STATUS;
            }
        } elseif ($currentTask) {
            $nextStatus = $this->autoInquiryStatusForTaskStatus((string) $currentTask->status);

            // Parent Inquiry lifecycle is based on the whole taskflow, not only the
            // next open task. After any task is working or completed, advancing to
            // a new Not Started/Ready task must not send the Inquiry back to To do.
            // Keep special configured states (for example Blocked/Cancelled), but
            // apply an In Progress floor once the workflow has made real progress.
            if ($hasProgress && strcasecmp($nextStatus, self::AUTO_READY_STATUS) === 0) {
                $nextStatus = self::AUTO_IN_PROGRESS_STATUS;
            }
        } else {
            $nextStatus = self::AUTO_READY_STATUS;
        }

        $update = ['status' => $nextStatus];
        if ($total > 0 && $completed === $total && strcasecmp($nextStatus, self::AUTO_COMPLETED_STATUS) === 0) {
            $update['completed_at'] = $inquiry->completed_at ?: now();
        } elseif ($inquiry->completed_at && !$inquiry->result) {
            $update['completed_at'] = null;
        }

        $statusChanged = (string) $inquiry->status !== $nextStatus;
        $completedChanged = array_key_exists('completed_at', $update)
            && $update['completed_at'] != $inquiry->completed_at;

        if ($statusChanged || $completedChanged) {
            $inquiry->update($update);
            if ($statusChanged && $actor) {
                $this->activity(
                    $inquiry,
                    $actor,
                    'inquiry.status_auto_changed',
                    'Inquiry status automatically changed to '.$nextStatus.' based on overall Inquiry taskflow progress.',
                );
            }
        }

        return $inquiry->refresh();
    }

    /**
     * Make the user who performs an Inquiry task action the task's current
     * assignee, matching the automatic ownership behavior used by Order tasks.
     * Manual assignee edits intentionally bypass this helper so the selected
     * user remains the assignee until somebody actually works on the task.
     */
    private function claimTaskForAction(InquiryTask $task, User $actor, string $action = 'acted on the task'): InquiryTask
    {
        $task->loadMissing('inquiry');
        $previousAssigneeId = $task->assignee_id ? (int) $task->assignee_id : null;

        if ($previousAssigneeId === (int) $actor->id) {
            return $task;
        }

        $previousAssigneeName = $previousAssigneeId
            ? (User::query()->find($previousAssigneeId)?->name ?? 'Unassigned')
            : 'Unassigned';

        $task->update(['assignee_id' => $actor->id]);
        $task->refresh();
        $task->loadMissing('inquiry');
        $task->inquiry->touch();

        // Invalidate both users' My Tasks shells. notifyTaskAssigned() handles
        // the new assignee shell and intentionally sends no self-notification.
        $this->forgetMyTaskShell($previousAssigneeId);
        $this->notifyTaskAssigned($task, $actor);

        $this->activity(
            $task->inquiry,
            $actor,
            'inquiry.task_assignee_auto_assigned',
            'Assignee automatically changed from '.$previousAssigneeName.' to '.$actor->name.' after '.$actor->name.' '.$action.'.',
            [
                'inquiry_task_id' => $task->id,
                'field' => 'assignee_id',
                'old' => $previousAssigneeName,
                'new' => $actor->name,
                'old_assignee_id' => $previousAssigneeId,
                'new_assignee_id' => (int) $actor->id,
                'automatic' => true,
                'trigger' => $action,
            ],
        );

        return $task->refresh();
    }

    private function activity(Inquiry $inquiry, User $actor, string $event, string $description, array $meta = []): Activity
    {
        return $inquiry->activities()->create([
            'user_id' => $actor->id,
            'event' => $event,
            'description' => $description,
            'meta' => $meta ?: null,
        ]);
    }

    private function notifyTaskAssigned(InquiryTask $task, User $actor): void
    {
        if (!$task->assignee_id) return;
        $assigneeId = (int) $task->assignee_id;
        $this->forgetMyTaskShell($assigneeId);
        if ($assigneeId === (int) $actor->id) return;

        $recipient = User::query()->where('is_active', true)->find($assigneeId);
        if (!$recipient) return;

        app(NotificationService::class)->notifyInquiryUser(
            $recipient,
            $task->inquiry,
            $task,
            'Task assigned: '.$task->title,
            $task->inquiry->inquiry_number.' · '.($task->due_date?->format('M j, Y') ?: 'No due date'),
            'assignment',
            $actor,
        );
    }

    private function forgetMyTaskShell(?int $userId): void
    {
        if (!$userId) return;
        app(ShellDataService::class)->forget($userId);
    }

    private function notifyAttentionRecipients(Inquiry $inquiry, ?InquiryTask $task, string $message, User $actor): void
    {
        $recipientIds = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($inquiry): void {
                $query->where('is_super_admin', true)
                    ->orWhereHas('roles', fn ($role) => $role->where('is_active', true)->whereIn('slug', ['super-admin', 'admin', 'administrator']))
                    ->orWhereHas('role', fn ($role) => $role->whereIn('slug', ['super-admin', 'admin', 'administrator']));
                if ($inquiry->created_by) $query->orWhere('id', (int) $inquiry->created_by);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn ($id) => $id === (int) $actor->id)
            ->unique()
            ->values()
            ->all();

        if ($recipientIds === []) return;

        app(NotificationService::class)->notifyInquiryAttentionUsers(
            $recipientIds,
            'Attention requested: '.$inquiry->inquiry_number,
            $message,
            $inquiry,
            $task,
            $actor,
        );
    }

    private function notifyMentions(Inquiry $inquiry, ?InquiryTask $task, string $body, User $actor): void
    {
        $ids = app(MentionService::class)->userIdsFromText($body);
        if ($ids === []) return;

        app(NotificationService::class)->notifyInquiryMentionedUsers(
            $ids,
            $actor->name.' mentioned you in '.$inquiry->inquiry_number,
            $body,
            $inquiry,
            $task,
            $actor,
        );
    }


    private function nextNumber(): string
    {
        $year = app(WorkspaceSettingsService::class)->localNow()->format('Y');
        $last = Inquiry::withTrashed()
            ->where('workspace_id', $this->workspaceId())
            ->where('inquiry_number', 'like', 'INQ-'.$year.'-%')
            ->orderByDesc('id')
            ->value('inquiry_number');
        $next = 1;
        if ($last && preg_match('/(\d+)$/', $last, $match)) $next = ((int) $match[1]) + 1;
        return 'INQ-'.$year.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
