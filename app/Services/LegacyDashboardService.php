<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Client;
use App\Models\Department;
use App\Models\FlowJob;
use App\Models\FlowNotification;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Models\TaskPackItem;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Support\MasterColor;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class LegacyDashboardService
{
    private const CACHE_VERSION = 'v19-shipping-phase-compat';

    private ?int $clientLifecycleVersion = null;

    private const SECTIONS = [
        'summary',
        'mentions',
        'mention-count',
        'inquiries',
        'assignees',
        'attention-tasks',
        'ongoing-jobs',
        'ongoing-tasks',
        'activity',
        'clients',
        'flow-distribution',
        'task-status-distribution',
        'catalogue-readiness',
    ];

    public function primaryData(User $user, int $clientId = 0, int $departmentId = 0, int $rangeDays = 7): array
    {
        // The management dashboard has one global period control (Today / 7 days / 30 days).
        // Team Performance and Client Portfolio must use the same period instead of keeping
        // their own independent reporting window.
        $dashboardPeriod = $this->dashboardReportingPeriod($rangeDays);

        return [
            'metrics' => $this->summaryForFilters($user, $clientId, $departmentId, $rangeDays),
            'flowDistribution' => $this->flowDistribution($user, $clientId, $departmentId, $rangeDays),
            'taskStatusDistribution' => $this->taskStatusDistribution($user, $clientId, $departmentId, $rangeDays),
            'attentionTasks' => $this->attentionTasks($user),
            'attentionOrders' => $this->attentionOrders($user, $clientId, $departmentId, $rangeDays),
            'attentionInquiries' => $this->attentionInquiries($user, $clientId, $departmentId, $rangeDays),
            'clientPortfolio' => $this->clientPortfolio($user, $clientId, $departmentId, $rangeDays),
            'assigneePerformance' => $user->canAccess('reports.view')
                ? $this->assigneePerformance(
                    $user,
                    $clientId,
                    $departmentId,
                    'custom',
                    $dashboardPeriod['from'],
                    $dashboardPeriod['to'],
                )
                : collect(),
            'teamReportingPeriod' => $dashboardPeriod,
            'priorityJobs' => $this->priorityJobs($user, $clientId, $departmentId, $rangeDays),
            'priorityInquiries' => $this->priorityInquiries($user, $clientId, $departmentId, $rangeDays),
            'priorityTasks' => $this->priorityTasks($user, $clientId, $departmentId, $rangeDays),
            'recentActivity' => $this->recentOperationalActivity($user, $clientId, $departmentId),
            'catalogueReadiness' => $this->catalogueReadiness($user),
            'dashboardClients' => $this->dashboardClients($user),
            'dashboardDepartments' => $this->dashboardDepartments($user),
        ];
    }

    public function secondaryData(User $user): array
    {
        return [
            'assigneePerformance' => $user->canAccess('reports.view') ? $this->assigneePerformance($user) : collect(),
            'attentionTasks' => $this->attentionTasks($user),
            'ongoingJobs' => $this->ongoingJobs($user),
            'ongoingTasks' => $this->ongoingTasks($user),
            'recentActivity' => $this->recentActivity($user),
            'clientPortfolio' => $this->clientPortfolio($user),
        ];
    }


    /** Backwards-compatible aggregate for reports/tests and non-Livewire callers. */
    public function data(User $user): array
    {
        return $this->primaryData($user) + $this->secondaryData($user);
    }

    public function metrics(User $user): array
    {
        return $this->summary($user);
    }

    public function attentionJobs(User $user): Collection
    {
        return app(JobService::class)->activeQuery($user)
            ->select(['flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.client_id', 'flow_jobs.workflow_phase_id', 'flow_jobs.owner_id', 'flow_jobs.title', 'flow_jobs.needs_attention', 'flow_jobs.attention_requested'])
            ->with(['client:id,name,logo_path', 'phase:id,short_name,color'])
            ->where(fn ($query) => $query->where('flow_jobs.attention_requested', true)->orWhere('flow_jobs.needs_attention', true))
            ->latest('flow_jobs.id')
            ->limit(6)
            ->get();
    }

    public function forget(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        foreach (self::SECTIONS as $section) {
            Cache::forget($this->cacheKey($section, $userId));
        }
    }

    public function forgetMentions(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        Cache::forget($this->cacheKey('mentions', $userId));
        Cache::forget($this->cacheKey('mention-count', $userId));
        Cache::forget($this->cacheKey('summary', $userId));
    }

    public function summary(User $user): array
    {
        return $this->remember($user, 'summary', function () use ($user): array {
            $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
            $jobs = app(JobService::class)->activeQuery($user)->reorder();
            $tasks = $this->activeTaskQuery($user)->reorder();

            $jobRow = (clone $jobs)
                ->selectRaw('count(*) as active_jobs')
                ->selectRaw("sum(case when flow_jobs.attention_requested = 1 or flow_jobs.needs_attention = 1  then 1 else 0 end) as attention_jobs")
                ->selectRaw("sum(case when exists (select 1 from workflow_phases where (workflow_phases.id = flow_jobs.workflow_phase_id or workflow_phases.id = flow_jobs.source_workflow_phase_id) and (lower(workflow_phases.name) like '%ship%' or lower(workflow_phases.short_name) like '%ship%')) then 1 else 0 end) as shipping_jobs")
                ->first();

            $taskRow = (clone $tasks)
                ->selectRaw('sum(case when tasks.due_date < ? then 1 else 0 end) as overdue_tasks', [$today])
                ->first();

            $activeClients = app(ClientService::class)->visibleQuery($user)
                ->where('clients.is_active', true)
                ->count();

            return [
                'activeJobs' => (int) ($jobRow?->active_jobs ?? 0),
                'needsAttention' => (int) ($jobRow?->attention_jobs ?? 0),
                'overdueTasks' => (int) ($taskRow?->overdue_tasks ?? 0),
                'activeClients' => (int) $activeClients,
                'openInquiries' => app(InquiryService::class)->visibleQuery($user)
                    ->whereNull('result')
                    ->where('status', '!=', 'Draft')
                    ->count(),
                'taggedComments' => $this->unreadMentionCount($user),
                'activeProducts' => app(ProductCatalogService::class)->activeCount(),
                'shipping' => (int) ($jobRow?->shipping_jobs ?? 0),
            ];
        });
    }


    public function summaryForFilters(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        int $rangeDays = 7,
    ): array {
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        $rangeDays = in_array($rangeDays, [1, 7, 30], true) ? $rangeDays : 7;
        [$rangeFrom, $rangeTo] = $this->dashboardRangeUtcBounds($rangeDays);

        return $this->remember(
            $user,
            'summary-range-'.$rangeDays.'-client-'.$clientId.'-department-'.$departmentId,
            function () use ($user, $clientId, $departmentId, $rangeFrom, $rangeTo): array {
                $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

                // The dashboard range represents records that actually moved during
                // the selected local-calendar window. This makes Today / 7 days /
                // 30 days a real dashboard filter instead of changing only one panel.
                $jobs = app(JobService::class)->activeQuery($user)->reorder()
                    ->whereBetween('flow_jobs.updated_at', [$rangeFrom, $rangeTo])
                    ->when($clientId > 0, fn (Builder $query) => $query->where('flow_jobs.client_id', $clientId))
                    ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId)));

                $tasks = $this->activeTaskQuery($user)->reorder()
                    ->whereBetween('tasks.updated_at', [$rangeFrom, $rangeTo])
                    ->when($clientId > 0, fn (Builder $query) => $query->whereHas('job', fn (Builder $job) => $job->where('client_id', $clientId)))
                    ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId)));

                $inquiries = app(InquiryService::class)->visibleQuery($user)
                    ->whereNull('inquiries.result')
                    ->where('inquiries.status', '!=', 'Draft')
                    ->whereBetween('inquiries.updated_at', [$rangeFrom, $rangeTo])
                    ->when($clientId > 0, fn (Builder $query) => $query->where('inquiries.client_id', $clientId))
                    ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId)));

                $jobRow = (clone $jobs)
                    ->selectRaw('count(*) as active_jobs')
                    ->selectRaw("sum(case when flow_jobs.attention_requested = 1 or flow_jobs.needs_attention = 1  then 1 else 0 end) as attention_jobs")
                    ->selectRaw("sum(case when exists (select 1 from workflow_phases where (workflow_phases.id = flow_jobs.workflow_phase_id or workflow_phases.id = flow_jobs.source_workflow_phase_id) and (lower(workflow_phases.name) like '%ship%' or lower(workflow_phases.short_name) like '%ship%')) then 1 else 0 end) as shipping_jobs")
                    ->first();

                $taskRow = (clone $tasks)
                    ->selectRaw('sum(case when tasks.due_date < ? then 1 else 0 end) as overdue_tasks', [$today])
                    ->first();

                // Client and product master totals are point-in-time catalogue
                // values, so they remain stable while operational KPIs follow the
                // selected range.
                $activeClients = app(ClientService::class)->visibleQuery($user)
                    ->where('clients.is_active', true)
                    ->when($clientId > 0, fn (Builder $query) => $query->where('clients.id', $clientId))
                    ->count();

                return [
                    'activeJobs' => (int) ($jobRow?->active_jobs ?? 0),
                    'needsAttention' => (int) ($jobRow?->attention_jobs ?? 0),
                    'overdueTasks' => (int) ($taskRow?->overdue_tasks ?? 0),
                    'activeClients' => (int) $activeClients,
                    'openInquiries' => (int) $inquiries->count(),
                    'taggedComments' => $this->unreadMentionCount($user),
                    'activeProducts' => app(ProductCatalogService::class)->activeCount(),
                    'shipping' => (int) ($jobRow?->shipping_jobs ?? 0),
                ];
            },
        );
    }


    public function recentInquiries(User $user): Collection
    {
        $canViewTasks = app(AccessControlService::class)->can($user, 'tasks', 'view');
        $rows = app(InquiryService::class)->visibleQuery($user)
            ->whereNull('inquiries.result')
            ->where('inquiries.status', '!=', 'Draft')
            ->select([
                'inquiries.id', 'inquiries.inquiry_number', 'inquiries.client_id',
                'inquiries.owner_id', 'inquiries.subject', 'inquiries.status',
                'inquiries.updated_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'owner:id,name,profile_image_path,department_id',
                // Current task is still needed for due-date/flag calculation, but its assignee is not
                // the Inquiry assignee shown on the dashboard.
                'currentTask:id,inquiry_id,title,status,needs_attention,attention_reason,due_date,completed_at',
            ])
            ->latest('inquiries.updated_at')
            ->latest('inquiries.id')
            ->limit(30)
            ->get();

        if (!$canViewTasks) $rows->each(fn ($inquiry) => $inquiry->setRelation('currentTask', null));
        return $rows;
    }

    public function mentions(
        User $user,
        string $filter = 'all',
        int $limit = 12,
        int $clientId = 0,
        int $departmentId = 0,
        ?int $rangeDays = null,
        string $search = '',
    ): Collection {
        // A mention is a first-class notification. Do not try to rediscover its
        // source by comparing the notification message with a comment body: rich
        // text normalization, description mentions, and Inquiry mentions make that
        // brittle. The foreign-key context is the durable source of truth.
        $query = $this->applyDashboardMentionFilters(
            $this->dashboardMentionQuery($user),
            $clientId,
            $departmentId,
            $rangeDays,
            $search,
        );

        match ($filter) {
            'unread' => $query->whereNull('flow_notifications.read_at'),
            'orders' => $query
                ->where(function (Builder $orderMentions): void {
                    $orderMentions
                        ->whereNotNull('flow_notifications.flow_job_id')
                        ->orWhereNotNull('flow_notifications.flow_task_id');
                })
                ->whereNull('flow_notifications.inquiry_id')
                ->whereNull('flow_notifications.inquiry_task_id'),
            'inquiries' => $query->where(function (Builder $inquiryMentions): void {
                $inquiryMentions
                    ->whereNotNull('flow_notifications.inquiry_id')
                    ->orWhereNotNull('flow_notifications.inquiry_task_id');
            }),
            default => null,
        };

        $columns = [
            'id', 'user_id', 'flow_job_id', 'flow_task_id', 'inquiry_id',
            'inquiry_task_id', 'type', 'title', 'message', 'read_at', 'created_at',
        ];
        $relations = [
            'job:id,job_number,title,client_id,workflow_phase_id',
            'job.client:id,name,logo_path',
            'job.phase:id,name,short_name',
            'task:id,task_number,title,flow_job_id,workflow_phase_id',
            'task.phase:id,name,short_name',
            'inquiry:id,inquiry_number,subject',
            'inquiryTask:id,inquiry_id,title',
        ];

        if (FlowNotification::supportsActorIdentity()) {
            $columns[] = 'actor_id';
            array_unshift($relations, 'actor:id,name,profile_image_path');
        }

        $mentions = $query
            ->select($columns)
            ->with($relations)
            ->latest('created_at')
            ->latest('id')
            ->limit(max(1, min(50, $limit)))
            ->get();

        // actor_id did not exist in older installations, and some historic rows
        // may remain null even after the migration. The actor name is already
        // encoded in mention titles, so resolve those rows in one query and attach
        // the real User model. This keeps avatars/names correct during rolling
        // deployments and for legacy data without creating an N+1 query.
        $this->hydrateLegacyMentionActors($mentions);

        return $mentions;
    }

    private function hydrateLegacyMentionActors(Collection $mentions): void
    {
        $nameByNotificationId = $mentions
            ->filter(fn (FlowNotification $notification) => ! $notification->relationLoaded('actor') || ! $notification->getRelation('actor'))
            ->mapWithKeys(function (FlowNotification $notification): array {
                if (! preg_match('/^(.*?) mentioned (?:you|a user) in /u', (string) $notification->title, $match)) {
                    return [];
                }

                $name = trim((string) ($match[1] ?? ''));
                return $name !== '' ? [(int) $notification->id => $name] : [];
            });

        if ($nameByNotificationId->isEmpty()) {
            return;
        }

        $names = $nameByNotificationId->values()->unique()->values();
        $usersByName = User::query()
            ->whereIn('name', $names->all())
            ->get(['id', 'name', 'profile_image_path'])
            ->groupBy(fn (User $candidate) => mb_strtolower(trim((string) $candidate->name)))
            ->filter(fn (Collection $matches) => $matches->count() === 1)
            ->map(fn (Collection $matches) => $matches->first());

        foreach ($mentions as $notification) {
            if ($notification->relationLoaded('actor') && $notification->getRelation('actor')) {
                continue;
            }

            $name = $nameByNotificationId->get((int) $notification->id);
            if (! $name) {
                $notification->setRelation('actor', null);
                continue;
            }

            $notification->setRelation('actor', $usersByName->get(mb_strtolower($name)));
        }
    }

    public function unreadMentionCount(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        ?int $rangeDays = null,
        string $search = '',
    ): int {
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        $search = trim($search);

        // Preserve the existing short-lived cache for the global notification
        // badge. Dashboard-filtered counts are intentionally uncached because the
        // search text can change on every keystroke and must never return a count
        // from a different client/team/range combination.
        if ($clientId === 0 && $departmentId === 0 && $rangeDays === null && $search === '') {
            return (int) $this->remember(
                $user,
                'mention-count',
                fn () => $this->dashboardMentionQuery($user)->whereNull('read_at')->count(),
            );
        }

        return (int) $this->applyDashboardMentionFilters(
            $this->dashboardMentionQuery($user),
            $clientId,
            $departmentId,
            $rangeDays,
            $search,
        )->whereNull('flow_notifications.read_at')->count();
    }

    public function markAllMentionsRead(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        ?int $rangeDays = null,
        string $search = '',
    ): void {
        $this->applyDashboardMentionFilters(
            $this->dashboardMentionQuery($user),
            $clientId,
            $departmentId,
            $rangeDays,
            $search,
        )
            ->whereNull('flow_notifications.read_at')
            ->update(['read_at' => now()]);

        $this->forgetMentions($user);
        app(NotificationService::class)->broadcastRealtimeState($user);
    }

    /** Backwards-compatible alias for older callers. */
    public function markAllCommentMentionsRead(User $user): void
    {
        $this->markAllMentionsRead($user);
    }

    /**
     * Personal dashboard mentions created from comments/descriptions in Orders
     * and Inquiries. Orphaned or soft-deleted records are excluded by the
     * notification visibility query without content-matching queries.
     */
    private function dashboardMentionQuery(User $user): Builder
    {
        $query = app(NotificationService::class)->visibleQuery($user);

        // Admin/Super Admin receive mention_admin audit copies for mentions made
        // anywhere they are allowed to see. Include those copies in the dashboard
        // feed so the management view is workspace-wide. Normal users must only
        // ever see direct mentions addressed to their own notification user_id.
        if (app(AccessControlService::class)->isAdministrator($user)) {
            return $query->whereIn('flow_notifications.type', ['mention', 'mention_admin']);
        }

        return $query->where('flow_notifications.type', 'mention');
    }

    /**
     * Apply the management dashboard's global controls to the mention feed before
     * ORDER BY/LIMIT. Nested Livewire components are independent islands, so these
     * constraints are passed in explicitly by Dashboard\TaggedComments.
     */
    private function applyDashboardMentionFilters(
        Builder $query,
        int $clientId = 0,
        int $departmentId = 0,
        ?int $rangeDays = null,
        string $search = '',
    ): Builder {
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        $search = mb_strtolower(trim($search));

        if ($rangeDays !== null) {
            $query->whereBetween('flow_notifications.created_at', $this->dashboardRangeUtcBounds($rangeDays));
        }

        if ($clientId > 0) {
            $query->where(function (Builder $contexts) use ($clientId): void {
                $contexts
                    ->where(function (Builder $task) use ($clientId): void {
                        $task->whereNotNull('flow_notifications.flow_task_id')
                            ->whereHas('task.job', fn (Builder $job) => $job->where('flow_jobs.client_id', $clientId));
                    })
                    ->orWhere(function (Builder $inquiryTask) use ($clientId): void {
                        $inquiryTask->whereNull('flow_notifications.flow_task_id')
                            ->whereNotNull('flow_notifications.inquiry_task_id')
                            ->whereHas('inquiryTask.inquiry', fn (Builder $inquiry) => $inquiry->where('inquiries.client_id', $clientId));
                    })
                    ->orWhere(function (Builder $job) use ($clientId): void {
                        $job->whereNull('flow_notifications.flow_task_id')
                            ->whereNull('flow_notifications.inquiry_task_id')
                            ->whereNull('flow_notifications.inquiry_id')
                            ->whereNotNull('flow_notifications.flow_job_id')
                            ->whereHas('job', fn (Builder $record) => $record->where('flow_jobs.client_id', $clientId));
                    })
                    ->orWhere(function (Builder $inquiry) use ($clientId): void {
                        $inquiry->whereNull('flow_notifications.flow_task_id')
                            ->whereNull('flow_notifications.inquiry_task_id')
                            ->whereNull('flow_notifications.flow_job_id')
                            ->whereNotNull('flow_notifications.inquiry_id')
                            ->whereHas('inquiry', fn (Builder $record) => $record->where('inquiries.client_id', $clientId));
                    });
            });
        }

        if ($departmentId > 0) {
            $query->where(function (Builder $contexts) use ($departmentId): void {
                $contexts
                    ->where(function (Builder $task) use ($departmentId): void {
                        $task->whereNotNull('flow_notifications.flow_task_id')
                            ->where(function (Builder $team) use ($departmentId): void {
                                $team->whereHas('task.assignee', fn (Builder $assignee) => $assignee->where('users.department_id', $departmentId))
                                    ->orWhere(function (Builder $fallback) use ($departmentId): void {
                                        $fallback->whereHas('task', fn (Builder $taskRecord) => $taskRecord->whereNull('tasks.assignee_id'))
                                            ->whereHas('task.job.owner', fn (Builder $owner) => $owner->where('users.department_id', $departmentId));
                                    });
                            });
                    })
                    ->orWhere(function (Builder $inquiryTask) use ($departmentId): void {
                        $inquiryTask->whereNull('flow_notifications.flow_task_id')
                            ->whereNotNull('flow_notifications.inquiry_task_id')
                            ->where(function (Builder $team) use ($departmentId): void {
                                $team->whereHas('inquiryTask.assignee', fn (Builder $assignee) => $assignee->where('users.department_id', $departmentId))
                                    ->orWhere(function (Builder $fallback) use ($departmentId): void {
                                        $fallback->whereHas('inquiryTask', fn (Builder $taskRecord) => $taskRecord->whereNull('inquiry_tasks.assignee_id'))
                                            ->whereHas('inquiryTask.inquiry.owner', fn (Builder $owner) => $owner->where('users.department_id', $departmentId));
                                    });
                            });
                    })
                    ->orWhere(function (Builder $job) use ($departmentId): void {
                        $job->whereNull('flow_notifications.flow_task_id')
                            ->whereNull('flow_notifications.inquiry_task_id')
                            ->whereNull('flow_notifications.inquiry_id')
                            ->whereNotNull('flow_notifications.flow_job_id')
                            ->whereHas('job.owner', fn (Builder $owner) => $owner->where('users.department_id', $departmentId));
                    })
                    ->orWhere(function (Builder $inquiry) use ($departmentId): void {
                        $inquiry->whereNull('flow_notifications.flow_task_id')
                            ->whereNull('flow_notifications.inquiry_task_id')
                            ->whereNull('flow_notifications.flow_job_id')
                            ->whereNotNull('flow_notifications.inquiry_id')
                            ->whereHas('inquiry.owner', fn (Builder $owner) => $owner->where('users.department_id', $departmentId));
                    });
            });
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $match) use ($like): void {
                $match
                    ->whereRaw("LOWER(COALESCE(flow_notifications.title, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(flow_notifications.message, '')) LIKE ?", [$like])
                    ->orWhereHas('job', function (Builder $job) use ($like): void {
                        $job->whereRaw("LOWER(COALESCE(flow_jobs.job_number, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(flow_jobs.title, '')) LIKE ?", [$like])
                            ->orWhereHas('client', fn (Builder $client) => $client->whereRaw("LOWER(COALESCE(clients.name, '')) LIKE ?", [$like]))
                            ->orWhereHas('owner', fn (Builder $owner) => $owner->whereRaw("LOWER(COALESCE(users.name, '')) LIKE ?", [$like]));
                    })
                    ->orWhereHas('task', function (Builder $task) use ($like): void {
                        $task->whereRaw("LOWER(COALESCE(tasks.task_number, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(tasks.title, '')) LIKE ?", [$like])
                            ->orWhereHas('assignee', fn (Builder $assignee) => $assignee->whereRaw("LOWER(COALESCE(users.name, '')) LIKE ?", [$like]));
                    })
                    ->orWhereHas('inquiry', function (Builder $inquiry) use ($like): void {
                        $inquiry->whereRaw("LOWER(COALESCE(inquiries.inquiry_number, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(inquiries.subject, '')) LIKE ?", [$like])
                            ->orWhereHas('client', fn (Builder $client) => $client->whereRaw("LOWER(COALESCE(clients.name, '')) LIKE ?", [$like]))
                            ->orWhereHas('owner', fn (Builder $owner) => $owner->whereRaw("LOWER(COALESCE(users.name, '')) LIKE ?", [$like]));
                    })
                    ->orWhereHas('inquiryTask', function (Builder $task) use ($like): void {
                        $task->whereRaw("LOWER(COALESCE(inquiry_tasks.title, '')) LIKE ?", [$like])
                            ->orWhereHas('assignee', fn (Builder $assignee) => $assignee->whereRaw("LOWER(COALESCE(users.name, '')) LIKE ?", [$like]));
                    });
            });
        }

        return $query;
    }

    public function dashboardReportingPeriod(int $rangeDays = 7): array
    {
        $rangeDays = in_array($rangeDays, [1, 7, 30], true) ? $rangeDays : 7;
        $settings = app(WorkspaceSettingsService::class);
        $today = $settings->localToday();
        $from = $today->copy()->subDays($rangeDays - 1);
        $to = $today->copy();
        [$fromUtc, $toUtc] = $settings->localDateRangeUtcBounds(
            $from->toDateString(),
            $to->toDateString(),
        );

        return [
            'key' => 'dashboard_'.$rangeDays,
            'label' => match ($rangeDays) {
                1 => 'Today',
                30 => 'Last 30 days',
                default => 'Last 7 days',
            },
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_utc' => $fromUtc,
            'to_utc' => $toUtc,
        ];
    }

    public function teamReportingPeriod(
        string $period = 'this_week',
        ?string $customFrom = null,
        ?string $customTo = null,
    ): array {
        $period = in_array($period, ['this_week', 'this_month', 'last_30_days', 'custom'], true)
            ? $period
            : 'this_week';

        $settings = app(WorkspaceSettingsService::class);
        $today = $settings->localToday();
        $from = $today->copy()->startOfWeek();
        $to = $today->copy();
        $label = 'This week';

        if ($period === 'this_month') {
            $from = $today->copy()->startOfMonth();
            $label = 'This month';
        } elseif ($period === 'last_30_days') {
            $from = $today->copy()->subDays(29);
            $label = 'Last 30 days';
        } elseif ($period === 'custom') {
            $parsedFrom = $this->parseDashboardDate($customFrom);
            $parsedTo = $this->parseDashboardDate($customTo);

            if ($parsedFrom && $parsedTo) {
                if ($parsedFrom->gt($parsedTo)) {
                    [$parsedFrom, $parsedTo] = [$parsedTo, $parsedFrom];
                }
                $from = $parsedFrom;
                $to = $parsedTo;
                $label = $from->isSameDay($to)
                    ? $from->format('M j, Y')
                    : $from->format('M j').' – '.$to->format('M j, Y');
            } else {
                $period = 'this_week';
            }
        }

        [$fromUtc, $toUtc] = $settings->localDateRangeUtcBounds(
            $from->toDateString(),
            $to->toDateString(),
        );

        return [
            'key' => $period,
            'label' => $label,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'from_utc' => $fromUtc,
            'to_utc' => $toUtc,
        ];
    }

    public function assigneePerformance(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        string $period = 'this_week',
        ?string $customFrom = null,
        ?string $customTo = null,
    ): Collection {
        $access = app(AccessControlService::class);
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $reportingPeriod = $this->teamReportingPeriod($period, $customFrom, $customTo);
        $assignedFrom = $reportingPeriod['from_utc'];
        $assignedTo = $reportingPeriod['to_utc'];

        $administratorSlugs = ['super-admin', 'admin', 'administrator'];
        $users = User::query()
            // Keep Team Performance aligned with Administration -> Users.
            // The users table can contain legacy/demo/imported accounts that are
            // not members of the current workspace. Those accounts (and any old
            // tasks assigned to them) must never appear in performance reports.
            ->whereHas('workspaceMemberships', fn (Builder $membership) => $membership
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active'))
            ->where('users.is_active', true)
            ->where('users.is_super_admin', false)
            ->whereDoesntHave('roles', fn (Builder $role) => $role
                ->where('is_active', true)
                ->whereIn('slug', $administratorSlugs))
            ->whereDoesntHave('role', fn (Builder $role) => $role
                ->where('is_active', true)
                ->whereIn('slug', $administratorSlugs))
            ->when($departmentId > 0, fn (Builder $query) => $query->where('users.department_id', $departmentId))
            ->when(
                !$access->isAdministrator($user) && $access->scope($user, 'tasks') !== 'all_records',
                fn (Builder $query) => $query->whereKey($user->id),
            )
            ->select(['users.id', 'users.department_id', 'users.name', 'users.profile_image_path'])
            ->with('department:id,name,code')
            ->orderBy('users.name')
            ->get();

        if ($users->isEmpty()) return $users;

        $userIds = $users->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orderTaskRules = app(OrderTaskFlagService::class);
        $inquiryStatusRecords = MasterRecord::withTrashed()
            ->forWorkspace($workspaceId)
            ->ofType('inquiry_task_status')
            ->get(['name', 'metadata']);

        $cancelledStatuses = $inquiryStatusRecords
            ->filter(fn (MasterRecord $status): bool => strcasecmp($status->inquiryAutoStatus(), 'Cancelled') === 0)
            ->pluck('name')
            ->map(fn ($name): string => mb_strtolower(trim((string) $name)))
            ->merge([
                'cancelled', 'canceled',
                mb_strtolower(trim($orderTaskRules->cancelledStatus())),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();
        $orderCompletedStatuses = collect([
            'completed', 'complete', 'done',
            mb_strtolower(trim($orderTaskRules->completedStatus())),
        ])->filter()->unique()->values()->all();
        $inquiryCompletedStatuses = $inquiryStatusRecords
            ->filter(fn (MasterRecord $status): bool => strcasecmp($status->inquiryAutoStatus(), InquiryService::AUTO_COMPLETED_STATUS) === 0)
            ->pluck('name')
            ->map(fn ($name): string => mb_strtolower(trim((string) $name)))
            ->merge(['completed', 'complete', 'done'])
            ->filter()
            ->unique()
            ->values()
            ->all();

        // Team performance must reflect the real task rows currently assigned to
        // each employee. Do not require an active Client here: historical/open
        // tasks remain real workload even if the Client was later deactivated.
        $orderBase = app(TaskService::class)->visibleQuery($user)
            ->reorder()
            ->when($clientId > 0, fn (Builder $query) => $query->whereHas('job', fn (Builder $job) => $job->where('flow_jobs.client_id', $clientId)));

        $orderOpen = (clone $orderBase)
            ->whereIn('tasks.assignee_id', $userIds)
            ->whereNull('tasks.completed_at')
            ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(tasks.status, '')))"), array_merge($cancelledStatuses, $orderCompletedStatuses))
            ->whereBetween(DB::raw('COALESCE(tasks.assignee_assigned_at, tasks.created_at)'), [$assignedFrom, $assignedTo])
            ->selectRaw('tasks.assignee_id as dashboard_assignee_id, count(*) as open_count')
            ->groupBy('tasks.assignee_id')
            ->pluck('open_count', 'dashboard_assignee_id');

        $orderCompleted = (clone $orderBase)
            ->whereIn('tasks.assignee_id', $userIds)
            ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(tasks.status, '')))"), $cancelledStatuses)
            ->where(function (Builder $query) use ($orderCompletedStatuses): void {
                $query->whereNotNull('tasks.completed_at')
                    ->orWhereIn(DB::raw("LOWER(TRIM(COALESCE(tasks.status, '')))"), $orderCompletedStatuses);
            })
            ->whereBetween(DB::raw('COALESCE(tasks.assignee_assigned_at, tasks.created_at)'), [$assignedFrom, $assignedTo])
            ->selectRaw('tasks.assignee_id as dashboard_assignee_id, count(*) as completed_count')
            ->groupBy('tasks.assignee_id')
            ->pluck('completed_count', 'dashboard_assignee_id');

        $inquiryBase = $access
            ->applyInquiryTaskScope(InquiryTask::query(), $user)
            ->reorder()
            ->whereHas('inquiry', fn (Builder $inquiry) => $inquiry->where('inquiries.workspace_id', $workspaceId))
            ->when($clientId > 0, fn (Builder $query) => $query->whereHas('inquiry', fn (Builder $inquiry) => $inquiry->where('inquiries.client_id', $clientId)));

        $inquiryOpen = (clone $inquiryBase)
            ->whereIn('inquiry_tasks.assignee_id', $userIds)
            ->whereNull('inquiry_tasks.completed_at')
            ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(inquiry_tasks.status, '')))"), array_merge($cancelledStatuses, $inquiryCompletedStatuses))
            ->whereBetween(DB::raw('COALESCE(inquiry_tasks.assignee_assigned_at, inquiry_tasks.created_at)'), [$assignedFrom, $assignedTo])
            ->selectRaw('inquiry_tasks.assignee_id as dashboard_assignee_id, count(*) as open_count')
            ->groupBy('inquiry_tasks.assignee_id')
            ->pluck('open_count', 'dashboard_assignee_id');

        $inquiryCompleted = (clone $inquiryBase)
            ->whereIn('inquiry_tasks.assignee_id', $userIds)
            ->whereNotIn(DB::raw("LOWER(TRIM(COALESCE(inquiry_tasks.status, '')))"), $cancelledStatuses)
            ->where(function (Builder $query) use ($inquiryCompletedStatuses): void {
                $query->whereNotNull('inquiry_tasks.completed_at')
                    ->orWhereIn(DB::raw("LOWER(TRIM(COALESCE(inquiry_tasks.status, '')))"), $inquiryCompletedStatuses);
            })
            ->whereBetween(DB::raw('COALESCE(inquiry_tasks.assignee_assigned_at, inquiry_tasks.created_at)'), [$assignedFrom, $assignedTo])
            ->selectRaw('inquiry_tasks.assignee_id as dashboard_assignee_id, count(*) as completed_count')
            ->groupBy('inquiry_tasks.assignee_id')
            ->pluck('completed_count', 'dashboard_assignee_id');

        return $users->map(function (User $person) use ($orderOpen, $orderCompleted, $inquiryOpen, $inquiryCompleted): User {
            $id = (int) $person->id;
            $orderOpenCount = (int) ($orderOpen->get($id) ?? 0);
            $inquiryOpenCount = (int) ($inquiryOpen->get($id) ?? 0);
            $orderCompletedCount = (int) ($orderCompleted->get($id) ?? 0);
            $inquiryCompletedCount = (int) ($inquiryCompleted->get($id) ?? 0);
            $open = $orderOpenCount + $inquiryOpenCount;
            $completed = $orderCompletedCount + $inquiryCompletedCount;
            $total = $open + $completed;

            $person->setAttribute('order_task_count', $orderOpenCount + $orderCompletedCount);
            $person->setAttribute('inquiry_task_count', $inquiryOpenCount + $inquiryCompletedCount);
            $person->setAttribute('total_task_count', $total);
            $person->setAttribute('open_count', $open);
            $person->setAttribute('completed_count', $completed);
            $person->setAttribute('completion_rate', $total > 0 ? (int) round(($completed / $total) * 100) : null);

            // On-time is intentionally disabled in Team Performance cards for
            // now. Keep the attribute explicit so the UI cannot accidentally
            // show a calculated/legacy value.
            $person->setAttribute('completed_with_due_date_count', 0);
            $person->setAttribute('done_on_time_count', 0);
            $person->setAttribute('on_time_rate', null);

            // Backwards-compatible attributes used by the lazy secondary panel.
            $person->setAttribute('ongoing_count', $open);
            $person->setAttribute('done_count', $completed);

            return $person;
        })->values();
    }

    /**
     * Add workload labels used by both the dashboard preview and the full team report.
     */
    public function decorateTeamPerformance(Collection $rows): Collection
    {
        // Resolve department colors once for the whole collection. The shared
        // MasterDataService keeps an in-request color map, avoiding a query per
        // employee card while keeping Dashboard and the full report consistent.
        $masterData = app(MasterDataService::class);

        // Workload status is intentionally left blank for now. The actual open
        // task count is still available and is the source of truth in the card.
        $rows->each(function ($row) use ($masterData): void {
            $department = $row->department;
            $departmentColor = $department
                ? ($masterData->colorFor('department', (string) ($department->code ?? ''))
                    ?: $masterData->displayColorFor('department', (string) ($department->name ?? '')))
                : null;

            $row->setAttribute('department_color', $departmentColor);
            $row->setAttribute('workload_label', '');
            $row->setAttribute('workload_percent', 0);
        });

        return $rows->values();
    }

    /**
     * Consistent ranking for the dashboard preview and Team Performance Report.
     * Users with assigned tasks are ranked before empty-state users.
     */
    public function sortTeamPerformance(Collection $rows, string $sort = 'performance'): Collection
    {
        $sort = in_array($sort, ['performance', 'workload', 'name'], true) ? $sort : 'performance';

        if ($sort === 'name') {
            return $rows->sortBy(fn ($row) => mb_strtolower((string) $row->name))->values();
        }

        if ($sort === 'workload') {
            return $rows->sort(function ($left, $right): int {
                $open = (int) $right->open_count <=> (int) $left->open_count;
                if ($open !== 0) return $open;

                $completed = (int) $right->completed_count <=> (int) $left->completed_count;
                if ($completed !== 0) return $completed;

                return strcasecmp((string) $left->name, (string) $right->name);
            })->values();
        }

        return $rows->sort(function ($left, $right): int {
            $leftHasTasks = (int) $left->total_task_count > 0 ? 1 : 0;
            $rightHasTasks = (int) $right->total_task_count > 0 ? 1 : 0;
            $hasTasks = $rightHasTasks <=> $leftHasTasks;
            if ($hasTasks !== 0) return $hasTasks;

            $completion = (int) ($right->completion_rate ?? -1) <=> (int) ($left->completion_rate ?? -1);
            if ($completion !== 0) return $completion;

            $completed = (int) $right->completed_count <=> (int) $left->completed_count;
            if ($completed !== 0) return $completed;

            $total = (int) $right->total_task_count <=> (int) $left->total_task_count;
            if ($total !== 0) return $total;

            return strcasecmp((string) $left->name, (string) $right->name);
        })->values();
    }

    private function parseDashboardDate(?string $value): ?\Carbon\Carbon
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        try {
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', $value);
            return $date && $date->format('Y-m-d') === $value ? $date->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function attentionOrders(User $user, int $clientId = 0, int $departmentId = 0, ?int $rangeDays = null): Collection
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $rangeBounds = $rangeDays !== null ? $this->dashboardRangeUtcBounds($rangeDays) : null;

        return app(JobService::class)->activeQuery($user)
            ->when($rangeBounds, fn (Builder $query) => $query->whereBetween('flow_jobs.updated_at', $rangeBounds))
            ->when($clientId > 0, fn (Builder $query) => $query->where('flow_jobs.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('owner', fn (Builder $owner) => $owner->where('users.department_id', $departmentId)))
            ->select([
                'flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.client_id',
                'flow_jobs.workflow_phase_id', 'flow_jobs.owner_id', 'flow_jobs.title',
                'flow_jobs.needs_attention', 'flow_jobs.attention_requested',
                'flow_jobs.attention_reason', 'flow_jobs.order_flag_id', 'flow_jobs.priority',
                'flow_jobs.progress', 'flow_jobs.delivery_date', 'flow_jobs.updated_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'phase:id,name,short_name,color',
                'orderFlag:id,type,name,color,status,sort_order,metadata',
                'owner:id,name,profile_image_path,department_id',
                'flaggedTasks:id,flow_job_id,status,due_date,order_task_flag_id,needs_attention,attention_reason,completed_at',
                'flaggedTasks.orderTaskFlag:id,type,name,status,sort_order,color,metadata',
                'tasks' => fn ($tasks) => $tasks
                    ->select(['tasks.id', 'tasks.flow_job_id', 'tasks.title', 'tasks.status', 'tasks.due_date', 'tasks.needs_attention', 'tasks.attention_reason', 'tasks.order_task_flag_id', 'tasks.completed_at'])
                    ->whereNull('tasks.completed_at')
                    ->where(function ($query) use ($today): void {
                        $query->where('tasks.needs_attention', true)
                            ->orWhere('tasks.due_date', '<', $today)
                            ->orWhereNotNull('tasks.order_task_flag_id')
                            ->orWhereRaw("lower(trim(tasks.status)) in ('blocked','waiting for client','waiting for internal approval','revision required')");
                    })
                    ->orderByRaw('tasks.due_date is null, tasks.due_date asc')
                    ->orderBy('tasks.id'),
            ])
            ->withCount('flaggedTasks as dashboard_flagged_task_count')
            ->where(function (Builder $query) use ($today): void {
                $query->where('flow_jobs.attention_requested', true)
                    ->orWhere('flow_jobs.needs_attention', true)
                    
                    ->orWhere('flow_jobs.delivery_date', '<', $today)
                    ->orWhereHas('tasks', function (Builder $tasks) use ($today): void {
                        $tasks->whereNull('tasks.completed_at')
                            ->where(function (Builder $attention) use ($today): void {
                                $attention->where('tasks.needs_attention', true)
                                    ->orWhere('tasks.due_date', '<', $today)
                                    ->orWhereNotNull('tasks.order_task_flag_id')
                                    ->orWhereRaw("lower(trim(tasks.status)) in ('blocked','waiting for client','waiting for internal approval','revision required')");
                            });
                    });
            })
            ->orderByRaw("case when flow_jobs.delivery_date is not null and flow_jobs.delivery_date < ? then 0 when flow_jobs.attention_requested = 1 or flow_jobs.needs_attention = 1 then 1 else 2 end", [$today])
            ->orderByRaw('flow_jobs.delivery_date is null, flow_jobs.delivery_date asc')
            ->orderByDesc('flow_jobs.updated_at')
            ->get();
    }

    public function attentionInquiries(User $user, int $clientId = 0, int $departmentId = 0, ?int $rangeDays = null): Collection
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $rangeBounds = $rangeDays !== null ? $this->dashboardRangeUtcBounds($rangeDays) : null;

        return app(InquiryService::class)->visibleQuery($user)
            ->when($rangeBounds, fn (Builder $query) => $query->whereBetween('inquiries.updated_at', $rangeBounds))
            ->when($clientId > 0, fn (Builder $query) => $query->where('inquiries.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->where(function (Builder $team) use ($departmentId): void {
                $team->whereHas('currentTask.assignee', fn (Builder $assignee) => $assignee->where('users.department_id', $departmentId))
                    ->orWhere(function (Builder $fallback) use ($departmentId): void {
                        $fallback->whereDoesntHave('currentTask.assignee')
                            ->whereHas('owner', fn (Builder $owner) => $owner->where('users.department_id', $departmentId));
                    });
            }))
            ->whereNull('inquiries.result')
            ->where('inquiries.status', '!=', 'Draft')
            ->select([
                'inquiries.id', 'inquiries.inquiry_number', 'inquiries.client_id',
                'inquiries.owner_id', 'inquiries.subject', 'inquiries.status',
                'inquiries.priority', 'inquiries.required_delivery_date',
                'inquiries.needs_attention', 'inquiries.updated_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'owner:id,name,profile_image_path,department_id',
                'currentTask:id,inquiry_id,assignee_id,title,status,needs_attention,attention_reason,due_date,completed_at',
                'currentTask.assignee:id,name,profile_image_path,department_id',
            ])
            ->withCount([
                'tasks as dashboard_priority_attention_count' => fn (Builder $tasks) => $tasks
                    ->whereNull('inquiry_tasks.completed_at')
                    ->where(function (Builder $query) use ($today): void {
                        $query->where('inquiry_tasks.needs_attention', true)
                            ->orWhere('inquiry_tasks.due_date', '<', $today)
                            ->orWhereRaw("lower(trim(inquiry_tasks.status)) in ('blocked','waiting','revision required')");
                    }),
            ])
            ->where(function (Builder $query) use ($today): void {
                $query->where('inquiries.needs_attention', true)
                    ->orWhere('inquiries.required_delivery_date', '<', $today)
                    ->orWhereHas('tasks', function (Builder $tasks) use ($today): void {
                        $tasks->whereNull('inquiry_tasks.completed_at')
                            ->where(function (Builder $attention) use ($today): void {
                                $attention->where('inquiry_tasks.needs_attention', true)
                                    ->orWhere('inquiry_tasks.due_date', '<', $today)
                                    ->orWhereRaw("lower(trim(inquiry_tasks.status)) in ('blocked','waiting','revision required')");
                            });
                    });
            })
            ->orderByDesc('inquiries.needs_attention')
            ->orderByDesc('dashboard_priority_attention_count')
            ->orderByRaw('inquiries.required_delivery_date is null, inquiries.required_delivery_date asc')
            ->orderByDesc('inquiries.updated_at')
            ->get();
    }

    public function attentionTasks(User $user): Collection
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        return $this->activeTaskQuery($user)
            ->select(['tasks.id', 'tasks.task_number', 'tasks.flow_job_id', 'tasks.workflow_phase_id', 'tasks.assignee_id', 'tasks.title', 'tasks.status', 'tasks.due_date', 'tasks.needs_attention', 'tasks.order_task_flag_id', 'tasks.attention_reason', 'tasks.completed_at'])
            ->with([
                'job:id,job_number,title,client_id',
                'job.client:id,name,logo_path',
                'assignee:id,name,profile_image_path,department_id',
                'orderTaskFlag:id,type,name,status,sort_order,color,metadata',
            ])
            ->where(function ($query) use ($today) {
                $query->where('tasks.needs_attention', true)
                    ->orWhere('tasks.due_date', '<', $today)
                    ->orWhereIn('tasks.status', ['Blocked', 'Waiting for Client', 'Waiting for Internal Approval', 'Revision Required']);
            })
            ->orderByRaw('case when tasks.due_date is not null and tasks.due_date < ? then 0 when tasks.needs_attention = 1 then 1 else 2 end', [$today])
            ->orderByRaw('tasks.due_date is null, tasks.due_date asc')
            ->limit(30)
            ->get();
    }

    /**
     * Management Priority Work is intentionally different from the generic
     * "recent" lists. Attention/overdue state wins first, then the configured
     * business priority, then the nearest operational due date.
     */
    public function priorityJobs(User $user, int $clientId = 0, int $departmentId = 0, ?int $rangeDays = null): Collection
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $rangeBounds = $rangeDays !== null ? $this->dashboardRangeUtcBounds($rangeDays) : null;

        return app(JobService::class)->activeQuery($user)
            ->when($rangeBounds, fn (Builder $query) => $query->whereBetween('flow_jobs.updated_at', $rangeBounds))
            ->when($clientId > 0, fn (Builder $query) => $query->where('flow_jobs.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId)))
            ->select([
                'flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.client_id',
                'flow_jobs.workflow_phase_id', 'flow_jobs.owner_id', 'flow_jobs.title',
                'flow_jobs.needs_attention', 'flow_jobs.attention_requested',
                'flow_jobs.attention_reason', 'flow_jobs.order_flag_id', 'flow_jobs.priority',
                'flow_jobs.progress', 'flow_jobs.delivery_date', 'flow_jobs.updated_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'phase:id,name,short_name,color',
                'orderFlag:id,type,name,color,status,sort_order,metadata',
                'owner:id,name,profile_image_path,department_id',
                'flaggedTasks:id,flow_job_id,status,due_date,order_task_flag_id,needs_attention,attention_reason,completed_at',
                'flaggedTasks.orderTaskFlag:id,type,name,status,sort_order,color,metadata',
            ])
            ->withCount('flaggedTasks as dashboard_flagged_task_count')
            ->orderByRaw("case when flow_jobs.attention_requested = 1 or flow_jobs.needs_attention = 1  then 0 when flow_jobs.delivery_date is not null and flow_jobs.delivery_date < ? then 1 else 2 end", [$today])
            ->orderByDesc('dashboard_flagged_task_count')
            ->orderByRaw("case lower(trim(flow_jobs.priority)) when 'critical' then 6 when 'urgent' then 5 when 'high' then 4 when 'medium' then 3 when 'normal' then 3 when 'low' then 2 else 1 end desc")
            ->orderByRaw('flow_jobs.delivery_date is null, flow_jobs.delivery_date asc')
            ->orderByDesc('flow_jobs.updated_at')
            ->limit(40)
            ->get();
    }

    public function priorityInquiries(User $user, int $clientId = 0, int $departmentId = 0, ?int $rangeDays = null): Collection
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $rangeBounds = $rangeDays !== null ? $this->dashboardRangeUtcBounds($rangeDays) : null;

        return app(InquiryService::class)->visibleQuery($user)
            ->when($rangeBounds, fn (Builder $query) => $query->whereBetween('inquiries.updated_at', $rangeBounds))
            ->whereNull('inquiries.result')
            ->where('inquiries.status', '!=', 'Draft')
            ->when($clientId > 0, fn (Builder $query) => $query->where('inquiries.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->where(function (Builder $scope) use ($departmentId): void {
                $scope->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId))
                    ->orWhereHas('tasks.assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId));
            }))
            ->select([
                'inquiries.id', 'inquiries.inquiry_number', 'inquiries.client_id',
                'inquiries.owner_id', 'inquiries.subject', 'inquiries.status',
                'inquiries.priority', 'inquiries.required_delivery_date',
                'inquiries.needs_attention', 'inquiries.updated_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'owner:id,name,profile_image_path,department_id',
                'currentTask:id,inquiry_id,assignee_id,title,status,needs_attention,attention_reason,due_date,completed_at',
                'currentTask.assignee:id,name,profile_image_path,department_id',
            ])
            ->withCount([
                'tasks as dashboard_priority_attention_count' => fn (Builder $tasks) => $tasks
                    ->whereNull('inquiry_tasks.completed_at')
                    ->where(function (Builder $query) use ($today): void {
                        $query->where('inquiry_tasks.needs_attention', true)
                            ->orWhere('inquiry_tasks.due_date', '<', $today)
                            ->orWhereRaw("lower(trim(inquiry_tasks.status)) in ('blocked','waiting')");
                    }),
            ])
            ->orderByDesc('inquiries.needs_attention')
            ->orderByDesc('dashboard_priority_attention_count')
            ->orderByRaw("case lower(trim(inquiries.priority)) when 'critical' then 6 when 'urgent' then 5 when 'high' then 4 when 'medium' then 3 when 'normal' then 3 when 'low' then 2 else 1 end desc")
            ->orderByRaw('inquiries.required_delivery_date is null, inquiries.required_delivery_date asc')
            ->orderByDesc('inquiries.updated_at')
            ->limit(40)
            ->get();
    }

    public function priorityTasks(User $user, int $clientId = 0, int $departmentId = 0, ?int $rangeDays = null): Collection
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $rangeBounds = $rangeDays !== null ? $this->dashboardRangeUtcBounds($rangeDays) : null;

        return $this->activeTaskQuery($user)
            ->when($rangeBounds, fn (Builder $query) => $query->whereBetween('tasks.updated_at', $rangeBounds))
            ->when($clientId > 0, fn (Builder $query) => $query->whereHas('job', fn (Builder $job) => $job->where('client_id', $clientId)))
            ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId)))
            ->select([
                'tasks.id', 'tasks.task_number', 'tasks.flow_job_id', 'tasks.workflow_phase_id',
                'tasks.assignee_id', 'tasks.title', 'tasks.status', 'tasks.priority',
                'tasks.due_date', 'tasks.needs_attention', 'tasks.attention_reason',
                'tasks.order_task_flag_id', 'tasks.completed_at', 'tasks.updated_at',
            ])
            ->with([
                'job:id,job_number,title,client_id',
                'job.client:id,name,logo_path',
                'phase:id,name,short_name,color',
                'assignee:id,name,profile_image_path,department_id',
                'orderTaskFlag:id,type,name,status,sort_order,color,metadata',
            ])
            ->orderByRaw("case when tasks.due_date is not null and tasks.due_date < ? then 0 when tasks.needs_attention = 1 then 1 when lower(trim(tasks.status)) in ('blocked','waiting for client','waiting for internal approval','revision required') then 2 else 3 end", [$today])
            ->orderByRaw("case lower(trim(tasks.priority)) when 'critical' then 6 when 'urgent' then 5 when 'high' then 4 when 'medium' then 3 when 'normal' then 3 when 'low' then 2 else 1 end desc")
            ->orderByRaw('tasks.due_date is null, tasks.due_date asc')
            ->orderByDesc('tasks.updated_at')
            ->limit(40)
            ->get();
    }

    public function ongoingJobs(User $user): Collection
    {
        return app(JobService::class)->activeQuery($user)
            ->select(['flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.client_id', 'flow_jobs.workflow_phase_id', 'flow_jobs.owner_id', 'flow_jobs.title', 'flow_jobs.needs_attention', 'flow_jobs.attention_requested', 'flow_jobs.attention_reason', 'flow_jobs.order_flag_id', 'flow_jobs.progress', 'flow_jobs.delivery_date', 'flow_jobs.updated_at'])
            ->with([
                'client:id,name,logo_path',
                'phase:id,name,short_name,color',
                'orderFlag:id,type,name,color,status,sort_order,metadata',
                'owner:id,name,profile_image_path,department_id',
                'flaggedTasks:id,flow_job_id,status,due_date,order_task_flag_id,needs_attention,attention_reason,completed_at',
                'flaggedTasks.orderTaskFlag:id,type,name,status,sort_order,color,metadata',
            ])
            ->orderByDesc('flow_jobs.updated_at')
            ->limit(30)
            ->get();
    }

    public function ongoingTasks(User $user): Collection
    {
        return $this->activeTaskQuery($user)
            ->select(['tasks.id', 'tasks.task_number', 'tasks.flow_job_id', 'tasks.workflow_phase_id', 'tasks.assignee_id', 'tasks.title', 'tasks.status', 'tasks.due_date', 'tasks.needs_attention', 'tasks.attention_reason', 'tasks.order_task_flag_id', 'tasks.completed_at', 'tasks.updated_at'])
            ->with(['job:id,job_number,title,client_id', 'job.client:id,name,logo_path', 'phase:id,name,short_name,color', 'assignee:id,name,profile_image_path,department_id', 'orderTaskFlag:id,type,name,status,sort_order,color,metadata'])
            ->orderByRaw('tasks.due_date is null, tasks.due_date asc')
            ->orderByDesc('tasks.updated_at')
            ->limit(30)
            ->get();
    }

    public function recentActivity(User $user): Collection
    {
        return app(NotificationService::class)->visibleQuery($user)
            ->select(['id', 'user_id', 'flow_job_id', 'flow_task_id', 'inquiry_id', 'inquiry_task_id', 'type', 'title', 'message', 'created_at'])
            ->latest('created_at')
            ->latest('id')
            ->limit(30)
            ->get();
    }

    public function recentOperationalActivity(User $user, int $clientId = 0, int $departmentId = 0): Collection
    {
        $visibleJobIds = app(JobService::class)->visibleQuery($user)
            ->when($clientId > 0, fn (Builder $query) => $query->where('flow_jobs.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId)))
            ->select('flow_jobs.id');
        $visibleTaskIds = app(TaskService::class)->visibleQuery($user)
            ->when($clientId > 0, fn (Builder $query) => $query->whereHas('job', fn (Builder $job) => $job->where('client_id', $clientId)))
            ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId)))
            ->select('tasks.id');
        $visibleInquiryIds = app(InquiryService::class)->visibleQuery($user)
            ->when($clientId > 0, fn (Builder $query) => $query->where('inquiries.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->where(function (Builder $scope) use ($departmentId): void {
                $scope->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId))
                    ->orWhereHas('tasks.assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId));
            }))
            ->select('inquiries.id');

        $rows = Activity::query()
            ->with('user:id,name,profile_image_path')
            ->where(function (Builder $query) use ($visibleJobIds, $visibleTaskIds, $visibleInquiryIds): void {
                $query->where(function (Builder $jobs) use ($visibleJobIds): void {
                    $jobs->where('activities.subject_type', FlowJob::class)
                        ->whereIn('activities.subject_id', $visibleJobIds)
                        // TaskService writes a canonical Task activity and a mirrored
                        // Order activity. Showing both would duplicate the same change.
                        ->where('activities.event', '!=', 'job.task_activity');
                })->orWhere(function (Builder $tasks) use ($visibleTaskIds): void {
                    $tasks->where('activities.subject_type', Task::class)
                        ->whereIn('activities.subject_id', $visibleTaskIds);
                })->orWhere(function (Builder $inquiries) use ($visibleInquiryIds): void {
                    $inquiries->where('activities.subject_type', Inquiry::class)
                        ->whereIn('activities.subject_id', $visibleInquiryIds);
                });
            })
            ->latest('activities.created_at')
            ->latest('activities.id')
            ->limit(60)
            ->get();

        if ($rows->isEmpty()) return $rows;

        $rows->loadMorph('subject', [
            FlowJob::class => ['client:id,name,logo_path', 'owner:id,name,department_id'],
            Task::class => ['job:id,job_number,title,client_id', 'job.client:id,name,logo_path', 'assignee:id,name,department_id'],
            Inquiry::class => ['client:id,name,logo_path', 'owner:id,name,department_id'],
        ]);

        $inquiryTaskIds = $rows
            ->filter(fn (Activity $activity) => $activity->subject_type === Inquiry::class && str_starts_with((string) $activity->event, 'inquiry.task_'))
            ->map(fn (Activity $activity) => (int) data_get($activity->meta, 'inquiry_task_id', 0))
            ->filter()
            ->unique()
            ->values();

        $inquiryTasks = $inquiryTaskIds->isEmpty()
            ? collect()
            : InquiryTask::query()
                ->whereIn('id', $inquiryTaskIds)
                ->with('assignee:id,name,department_id')
                ->get(['id', 'inquiry_id', 'assignee_id', 'title'])
                ->keyBy('id');

        return $rows->filter(function (Activity $activity) use ($inquiryTasks): bool {
            $subject = $activity->subject;
            if (!$subject) return false;

            $actorName = trim((string) ($activity->user?->name ?: 'System'));
            $description = trim((string) $activity->description);
            $kind = 'orders';
            $clientId = 0;
            $departmentId = 0;
            $title = $description !== '' ? $description : 'Record updated';
            $detail = $actorName;
            $parentId = 0;
            $taskId = 0;

            if ($subject instanceof Task) {
                $kind = 'tasks';
                $clientId = (int) ($subject->job?->client_id ?? 0);
                $departmentId = (int) ($subject->assignee?->department_id ?? 0);
                $title = trim((string) ($subject->job?->displayOrderNumber() ?: $subject->task_number ?: 'Order task'));
                $detail = trim(implode(' · ', array_filter([
                    trim((string) $subject->title) ?: null,
                    $description,
                    $actorName !== '' ? 'by '.$actorName : null,
                ])));
                $parentId = (int) $subject->flow_job_id;
                $taskId = (int) $subject->id;
            } elseif ($subject instanceof Inquiry) {
                $inquiryTaskId = (int) data_get($activity->meta, 'inquiry_task_id', 0);
                $inquiryTask = $inquiryTaskId > 0 ? $inquiryTasks->get($inquiryTaskId) : null;
                $isTaskChange = str_starts_with((string) $activity->event, 'inquiry.task_') || $inquiryTask !== null;
                $kind = $isTaskChange ? 'tasks' : 'inquiries';
                $clientId = (int) ($subject->client_id ?? 0);
                $departmentId = (int) ($inquiryTask?->assignee?->department_id ?? $subject->owner?->department_id ?? 0);
                $title = trim((string) $subject->inquiry_number) ?: 'Inquiry';
                $detail = trim(implode(' · ', array_filter([
                    $isTaskChange ? (trim((string) ($inquiryTask?->title ?: 'Inquiry task'))) : null,
                    $description,
                    $actorName !== '' ? 'by '.$actorName : null,
                ])));
                $parentId = (int) $subject->id;
                $taskId = $inquiryTaskId;
            } elseif ($subject instanceof FlowJob) {
                $kind = 'orders';
                $clientId = (int) ($subject->client_id ?? 0);
                $departmentId = (int) ($subject->owner?->department_id ?? 0);
                $title = $subject->displayOrderNumber();
                $detail = trim(implode(' · ', array_filter([
                    $description,
                    $actorName !== '' ? 'by '.$actorName : null,
                ])));
                $parentId = (int) $subject->id;
            }

            $activity->setAttribute('dashboard_kind', $kind);
            $activity->setAttribute('dashboard_client_id', $clientId);
            $activity->setAttribute('dashboard_department_id', $departmentId);
            $activity->setAttribute('dashboard_title', $title);
            $activity->setAttribute('dashboard_detail', $detail);
            $activity->setAttribute('dashboard_parent_id', $parentId);
            $activity->setAttribute('dashboard_task_id', $taskId);

            return true;
        })->values();
    }

    public function clientPortfolio(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        ?int $rangeDays = null,
    ): Collection {
        $access = app(AccessControlService::class);
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        $rangeBounds = $rangeDays !== null ? $this->dashboardRangeUtcBounds($rangeDays) : null;

        $applyJobFilters = function ($jobs) use ($access, $user, $departmentId, $rangeBounds) {
            $jobs = $access->applyJobScope($jobs, $user);

            if ($rangeBounds) {
                $jobs->whereBetween('flow_jobs.updated_at', $rangeBounds);
            }

            if ($departmentId > 0) {
                $jobs->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId));
            }

            return $jobs;
        };

        $applyTaskFilters = function ($tasks) use ($access, $user, $departmentId, $rangeBounds) {
            $tasks = $access->applyTaskScope($tasks, $user);

            if ($rangeBounds) {
                $tasks->whereBetween('tasks.updated_at', $rangeBounds);
            }

            if ($departmentId > 0) {
                $tasks->whereHas('assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId));
            }

            return $tasks;
        };

        $applyInquiryFilters = function ($inquiries) use ($access, $user, $departmentId, $rangeBounds) {
            $inquiries = $access->applyInquiryScope($inquiries, $user);

            if ($rangeBounds) {
                $inquiries->whereBetween('inquiries.updated_at', $rangeBounds);
            }

            if ($departmentId > 0) {
                $inquiries->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId));
            }

            return $inquiries;
        };

        $clients = app(ClientService::class)->visibleQuery($user)
            ->where('clients.is_active', true)
            ->when($clientId > 0, fn (Builder $query) => $query->where('clients.id', $clientId))
            ->select(['clients.id', 'clients.name', 'clients.logo_path'])
            ->withCount([
                // Keep the legacy attributes used by the secondary dashboard, but
                // scope them to the global dashboard period/team when supplied.
                'jobs as active_jobs_count' => fn ($jobs) => $applyJobFilters($jobs)
                    ->whereNull('flow_jobs.completed_at')
                    ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES),
                'jobs as attention_jobs_count' => fn ($jobs) => $applyJobFilters($jobs)
                    ->whereNull('flow_jobs.completed_at')
                    ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)
                    ->where(fn ($query) => $query
                        ->where('flow_jobs.attention_requested', true)
                        ->orWhere('flow_jobs.needs_attention', true)),
                'tasks as open_tasks_count' => fn ($tasks) => $applyTaskFilters($tasks)
                    ->whereNull('tasks.completed_at'),
                'tasks as overdue_tasks_count' => fn ($tasks) => $applyTaskFilters($tasks)
                    ->whereNull('tasks.completed_at')
                    ->where('tasks.due_date', '<', $today),

                // Portfolio metrics are operational records touched in the selected
                // dashboard period. This makes Today / 7 days / 30 days immediately
                // change the Client Portfolio instead of showing all-time totals.
                'jobs as orders_count' => fn ($jobs) => $applyJobFilters($jobs)
                    ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES),
                'jobs as completed_orders_count' => fn ($jobs) => $applyJobFilters($jobs)
                    ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)
                    ->where(fn ($query) => $query
                        ->whereNotNull('flow_jobs.completed_at')
                        ->orWhereRaw("LOWER(TRIM(COALESCE(flow_jobs.status, ''))) = 'completed'")),
                'inquiries as portfolio_inquiries_count' => fn ($inquiries) => $applyInquiryFilters($inquiries)
                    ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'"),
            ])
            ->orderByDesc('orders_count')
            ->orderByDesc('portfolio_inquiries_count')
            ->orderBy('clients.name')
            ->limit($clientId > 0 ? 1 : 60)
            ->get();

        if ($clients->isEmpty()) {
            return $clients;
        }

        // Build Inquiry totals from the same permission/range/team scope used by
        // the dashboard. Drafts are not operational portfolio records; terminal
        // records remain in the total and are counted as completed.
        $inquiryStatsQuery = app(InquiryService::class)->visibleQuery($user)
            ->whereIn('inquiries.client_id', $clients->pluck('id'))
            ->whereRaw("LOWER(TRIM(COALESCE(inquiries.status, ''))) != 'draft'");

        if ($rangeBounds) {
            $inquiryStatsQuery->whereBetween('inquiries.updated_at', $rangeBounds);
        }

        if ($departmentId > 0) {
            $inquiryStatsQuery->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId));
        }

        $inquiryStats = $inquiryStatsQuery
            ->selectRaw('inquiries.client_id')
            ->selectRaw('COUNT(*) as inquiries_count')
            ->selectRaw("SUM(CASE WHEN inquiries.completed_at IS NOT NULL OR inquiries.result IS NOT NULL OR LOWER(TRIM(COALESCE(inquiries.status, ''))) IN ('completed','converted','closed','dead') THEN 1 ELSE 0 END) as completed_inquiries_count")
            ->selectRaw("SUM(CASE WHEN inquiries.completed_at IS NULL AND inquiries.result IS NULL AND LOWER(TRIM(COALESCE(inquiries.status, ''))) NOT IN ('completed','converted','closed','dead') THEN 1 ELSE 0 END) as open_inquiries_count")
            ->selectRaw("SUM(CASE WHEN inquiries.completed_at IS NULL AND inquiries.result IS NULL AND inquiries.needs_attention = 1 THEN 1 ELSE 0 END) as attention_inquiries_count")
            ->groupBy('inquiries.client_id')
            ->get()
            ->keyBy('client_id');

        $clients->each(function (Client $client) use ($inquiryStats): void {
            $stats = $inquiryStats->get($client->id);
            $inquiries = (int) ($stats?->inquiries_count ?? 0);
            $completedInquiries = (int) ($stats?->completed_inquiries_count ?? 0);
            $openInquiries = (int) ($stats?->open_inquiries_count ?? 0);
            $attentionInquiries = (int) ($stats?->attention_inquiries_count ?? 0);
            $orders = (int) ($client->orders_count ?? 0);
            $completedOrders = (int) ($client->completed_orders_count ?? 0);
            $attentionOrders = (int) ($client->attention_jobs_count ?? 0);

            $client->setAttribute('inquiries_count', $inquiries);
            $client->setAttribute('completed_inquiries_count', $completedInquiries);
            $client->setAttribute('open_inquiries_count', $openInquiries);
            $client->setAttribute('attention_inquiries_count', $attentionInquiries);
            $client->setAttribute('total_records_count', $inquiries + $orders);
            $client->setAttribute('completed_records_count', $completedInquiries + $completedOrders);
            $client->setAttribute('attention_items_count', $attentionInquiries + $attentionOrders);
        });

        return $clients
            ->filter(fn (Client $client) => $clientId > 0 || (int) $client->total_records_count > 0)
            ->sort(function (Client $left, Client $right): int {
                $total = (int) $right->total_records_count <=> (int) $left->total_records_count;
                if ($total !== 0) return $total;

                $attention = (int) $right->attention_items_count <=> (int) $left->attention_items_count;
                if ($attention !== 0) return $attention;

                return strcasecmp((string) $left->name, (string) $right->name);
            })
            ->take(30)
            ->values();
    }

    public function flowDistribution(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        int $rangeDays = 7,
    ): array
    {
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        $rangeDays = in_array($rangeDays, [1, 7, 30], true) ? $rangeDays : 7;
        [$rangeFrom, $rangeTo] = $this->dashboardRangeUtcBounds($rangeDays);

        $resolver = fn (): array => [
            'orders' => $this->orderWorkflowPhaseDistribution($user, $clientId, $departmentId, $rangeFrom, $rangeTo),
            'inquiries' => $this->inquiryWorkflowPhaseDistribution($user, $clientId, $departmentId, $rangeFrom, $rangeTo),
        ];

        return $this->remember(
            $user,
            'flow-distribution-range-'.$rangeDays.'-client-'.$clientId.'-department-'.$departmentId,
            $resolver,
        );
    }

    /**
     * Dashboard Order flow must be phase-driven, not grouped by a phase id or
     * by the short label. Every Order gets a private Workflow snapshot, so the
     * same configured phase can have many database ids. We group by the source
     * phase name and then merge equal names across the active Workflows.
     */
    private function orderWorkflowPhaseDistribution(
        User $user,
        int $clientId,
        int $departmentId,
        $rangeFrom,
        $rangeTo,
    ): array
    {
        $rows = app(JobService::class)->activeQuery($user)
            ->reorder()
            ->whereBetween('flow_jobs.updated_at', [$rangeFrom, $rangeTo])
            ->when($clientId > 0, fn (Builder $query) => $query->where('flow_jobs.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId)))
            ->selectRaw('coalesce(flow_jobs.source_workflow_id, flow_jobs.workflow_id) as dashboard_workflow_id')
            ->selectRaw('coalesce(flow_jobs.source_workflow_phase_id, flow_jobs.workflow_phase_id) as dashboard_phase_id')
            ->addSelect('flow_jobs.client_id')
            ->selectRaw('count(*) as aggregate')
            ->groupByRaw('coalesce(flow_jobs.source_workflow_id, flow_jobs.workflow_id), coalesce(flow_jobs.source_workflow_phase_id, flow_jobs.workflow_phase_id), flow_jobs.client_id')
            ->get();

        if ($rows->isEmpty()) return [];

        $workflowIds = $rows->pluck('dashboard_workflow_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $phaseIds = $rows->pluck('dashboard_phase_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $clientNames = $this->clientNamesForIds($rows->pluck('client_id'));
        $activeClientsByWorkflow = $this->activeClientNamesByWorkflow($rows, $clientNames, 'dashboard_workflow_id');

        $sourcePhases = WorkflowPhase::query()
            ->whereIn('id', $phaseIds)
            ->get(['id', 'workflow_id', 'workflow_template_id', 'source_workflow_phase_id', 'name', 'short_name', 'sequence', 'task_pack_id', 'color'])
            ->keyBy('id');

        $missingPhaseIds = $phaseIds->diff($sourcePhases->keys());
        $snapshotPhases = $missingPhaseIds->isEmpty()
            ? collect()
            : WorkflowPhase::query()
                ->whereIn('source_workflow_phase_id', $missingPhaseIds)
                ->orderBy('id')
                ->get(['id', 'workflow_id', 'workflow_template_id', 'source_workflow_phase_id', 'name', 'short_name', 'sequence', 'task_pack_id', 'color'])
                ->unique('source_workflow_phase_id')
                ->keyBy('source_workflow_phase_id');

        $definitions = $this->workflowPhaseDefinitions($workflowIds);
        $counts = [];
        foreach ($rows as $row) {
            $phaseId = (int) ($row->dashboard_phase_id ?? 0);
            $phase = $sourcePhases->get($phaseId) ?: $snapshotPhases->get($phaseId);
            $label = $this->dashboardPhaseLabel($phase?->name, $phase?->short_name);
            $key = $this->dashboardPhaseKey($label);
            $workflowId = (int) ($row->dashboard_workflow_id ?? 0);

            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'label' => $label,
                    'short_label' => $this->dashboardPhaseShortLabel($phase?->short_name, $phase?->name),
                    'color' => MasterColor::normalize((string) ($phase?->color ?? '')),
                    'count' => 0,
                    'sequence' => (int) ($phase?->sequence ?? 9999),
                    'workflow_ids' => [],
                ];
            }
            $counts[$key]['count'] += (int) $row->aggregate;
            $counts[$key]['sequence'] = min($counts[$key]['sequence'], (int) ($phase?->sequence ?? 9999));
            if ($workflowId > 0) $counts[$key]['workflow_ids'][$workflowId] = true;
        }

        return $this->compileWorkflowPhaseDistribution($definitions, $counts, $workflowIds, $activeClientsByWorkflow);
    }

    /**
     * Inquiry flow is phase-driven exactly like Order flow. The current Inquiry
     * phase comes from the current task's persisted source workflow phase, with
     * safe legacy fallbacks for records created before that source was stored.
     */
    private function inquiryWorkflowPhaseDistribution(
        User $user,
        int $clientId,
        int $departmentId,
        $rangeFrom,
        $rangeTo,
    ): array
    {
        // Orders have a persisted workflow_phase_id. Inquiry work is task-driven,
        // so its equivalent is the source phase of the current Inquiry task. New
        // Inquiry tasks persist source_workflow_phase_id; the Task Pack and task
        // sequence fallbacks keep pre-migration/legacy records readable.
        $currentTaskOrder = static function ($query) {
            return $query
                ->whereColumn('dashboard_current_inquiry_task.inquiry_id', 'inquiries.id')
                ->whereNull('dashboard_current_inquiry_task.deleted_at')
                ->orderByRaw('case when dashboard_current_inquiry_task.completed_at is null then 0 else 1 end')
                ->orderByRaw('case when dashboard_current_inquiry_task.completed_at is null and dashboard_current_inquiry_task.started_at is not null then 0 when dashboard_current_inquiry_task.completed_at is null then 1 else 2 end')
                ->orderByRaw('case when dashboard_current_inquiry_task.completed_at is null and dashboard_current_inquiry_task.started_at is not null then dashboard_current_inquiry_task.sequence end desc')
                ->orderByRaw('case when dashboard_current_inquiry_task.completed_at is null and dashboard_current_inquiry_task.started_at is null then dashboard_current_inquiry_task.sequence end asc')
                ->orderByDesc('dashboard_current_inquiry_task.sequence')
                ->limit(1);
        };

        $rows = app(InquiryService::class)->visibleQuery($user)
            ->whereNull('inquiries.result')
            ->whereBetween('inquiries.updated_at', [$rangeFrom, $rangeTo])
            ->where('inquiries.status', '!=', 'Draft')
            ->when($clientId > 0, fn (Builder $query) => $query->where('inquiries.client_id', $clientId))
            ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('owner', fn (Builder $owner) => $owner->where('department_id', $departmentId)))
            ->reorder()
            ->select(['inquiries.id', 'inquiries.client_id', 'inquiries.source_workflow_template_id'])
            ->selectSub(function ($query) use ($currentTaskOrder): void {
                $currentTaskOrder($query->from('inquiry_tasks as dashboard_current_inquiry_task'))
                    ->select('dashboard_current_inquiry_task.source_workflow_phase_id');
            }, 'dashboard_source_workflow_phase_id')
            ->selectSub(function ($query) use ($currentTaskOrder): void {
                $currentTaskOrder($query->from('inquiry_tasks as dashboard_current_inquiry_task'))
                    ->select('dashboard_current_inquiry_task.source_task_pack_item_id');
            }, 'dashboard_source_task_pack_item_id')
            ->selectSub(function ($query) use ($currentTaskOrder): void {
                $currentTaskOrder($query->from('inquiry_tasks as dashboard_current_inquiry_task'))
                    ->select('dashboard_current_inquiry_task.sequence');
            }, 'dashboard_current_task_sequence')
            ->get();

        if ($rows->isEmpty()) return [];

        $workflowIds = $rows->pluck('source_workflow_template_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $clientNames = $this->clientNamesForIds($rows->pluck('client_id'));
        $activeClientsByWorkflow = $this->activeClientNamesByWorkflow($rows, $clientNames, 'source_workflow_template_id');
        $definitions = $this->workflowPhaseDefinitions($workflowIds);
        $definitionsById = $definitions->keyBy('id');

        // Direct source phase is authoritative. Include an inactive legacy source
        // phase too, so an Inquiry never silently falls back to a generic label
        // after Workflow Setup changes.
        $directPhaseIds = $rows->pluck('dashboard_source_workflow_phase_id')
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $legacyDirectPhases = $directPhaseIds->diff($definitionsById->keys())->isEmpty()
            ? collect()
            : WorkflowPhase::query()
                ->whereIn('id', $directPhaseIds->diff($definitionsById->keys()))
                ->get(['id', 'workflow_id', 'workflow_template_id', 'name', 'short_name', 'sequence', 'task_pack_id', 'color'])
                ->map(fn (WorkflowPhase $phase): array => [
                    'id' => (int) $phase->id,
                    'workflow_id' => (int) ($phase->workflow_template_id ?: $phase->workflow_id),
                    'label' => $this->dashboardPhaseLabel($phase->name, $phase->short_name),
                    'short_label' => $this->dashboardPhaseShortLabel($phase->short_name, $phase->name),
                    'color' => MasterColor::normalize((string) ($phase->color ?? '')),
                    'sequence' => (int) $phase->sequence,
                    'task_pack_id' => $phase->task_pack_id ? (int) $phase->task_pack_id : null,
                ])
                ->keyBy('id');

        $sourceItemIds = $rows->pluck('dashboard_source_task_pack_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $packBySourceItem = $sourceItemIds->isEmpty()
            ? collect()
            : TaskPackItem::query()->whereIn('id', $sourceItemIds)->pluck('task_pack_id', 'id');

        // Legacy fallback: map Workflow + Task Pack to every matching phase rather
        // than only the first one. Reused Task Packs are disambiguated by the
        // Inquiry task sequence below.
        $phasesByWorkflowPack = [];
        foreach ($definitions as $phase) {
            $workflowId = (int) $phase['workflow_id'];
            $packId = (int) ($phase['task_pack_id'] ?? 0);
            if ($workflowId <= 0 || $packId <= 0) continue;
            $phasesByWorkflowPack[$workflowId.':'.$packId][] = $phase;
        }

        // Sequence ranges mirror InquiryService::workflowRows(): phases are
        // flattened in configured order and each phase contributes all items in
        // its Task Pack. This gives old tasks a deterministic phase even when a
        // source item id is missing or the same Task Pack is reused.
        $packIds = $definitions->pluck('task_pack_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $packCounts = $packIds->isEmpty()
            ? collect()
            : TaskPackItem::query()
                ->whereIn('task_pack_id', $packIds)
                ->selectRaw('task_pack_id, count(*) as aggregate')
                ->groupBy('task_pack_id')
                ->pluck('aggregate', 'task_pack_id');
        $phaseRangesByWorkflow = [];
        foreach ($definitions->groupBy('workflow_id') as $workflowId => $workflowPhases) {
            $cursor = 0;
            foreach ($workflowPhases->sortBy(fn (array $phase) => [(int) $phase['sequence'], (int) $phase['id']]) as $phase) {
                $taskCount = max(0, (int) ($packCounts->get((int) ($phase['task_pack_id'] ?? 0)) ?? 0));
                if ($taskCount <= 0) continue;
                $phaseRangesByWorkflow[(int) $workflowId][] = [
                    'start' => $cursor + 1,
                    'end' => $cursor + $taskCount,
                    'phase' => $phase,
                ];
                $cursor += $taskCount;
            }
        }

        $counts = [];
        foreach ($rows as $row) {
            $workflowId = (int) ($row->source_workflow_template_id ?? 0);
            $sourcePhaseId = (int) ($row->dashboard_source_workflow_phase_id ?? 0);
            $sourceItemId = (int) ($row->dashboard_source_task_pack_item_id ?? 0);
            $taskSequence = (int) ($row->dashboard_current_task_sequence ?? 0);

            $phase = $sourcePhaseId > 0
                ? ($definitionsById->get($sourcePhaseId) ?: $legacyDirectPhases->get($sourcePhaseId))
                : null;

            if (! $phase && $workflowId > 0 && $sourceItemId > 0) {
                $packId = (int) ($packBySourceItem->get($sourceItemId) ?? 0);
                $matches = $phasesByWorkflowPack[$workflowId.':'.$packId] ?? [];
                if (count($matches) === 1) {
                    $phase = $matches[0];
                }
            }

            if (! $phase && $workflowId > 0 && $taskSequence > 0) {
                foreach ($phaseRangesByWorkflow[$workflowId] ?? [] as $range) {
                    if ($taskSequence >= $range['start'] && $taskSequence <= $range['end']) {
                        $phase = $range['phase'];
                        break;
                    }
                }
            }

            $label = $phase['label'] ?? 'Unassigned';
            $key = $this->dashboardPhaseKey($label);

            if (!isset($counts[$key])) {
                $counts[$key] = [
                    'label' => $label,
                    'short_label' => (string) ($phase['short_label'] ?? $label),
                    'color' => MasterColor::normalize((string) ($phase['color'] ?? '')),
                    'count' => 0,
                    'sequence' => (int) ($phase['sequence'] ?? 9999),
                    'workflow_ids' => [],
                ];
            }
            $counts[$key]['count']++;
            $counts[$key]['sequence'] = min($counts[$key]['sequence'], (int) ($phase['sequence'] ?? 9999));
            if ($workflowId > 0) $counts[$key]['workflow_ids'][$workflowId] = true;
        }

        return $this->compileWorkflowPhaseDistribution($definitions, $counts, $workflowIds, $activeClientsByWorkflow);
    }

    private function workflowPhaseDefinitions(Collection $workflowIds): Collection
    {
        if ($workflowIds->isEmpty()) return collect();

        return WorkflowPhase::query()
            ->where(function (Builder $query) use ($workflowIds): void {
                $query->whereIn('workflow_template_id', $workflowIds)
                    ->orWhere(function (Builder $legacy) use ($workflowIds): void {
                        $legacy->whereNull('workflow_template_id')->whereIn('workflow_id', $workflowIds);
                    });
            })
            ->where('is_active', true)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'workflow_id', 'workflow_template_id', 'name', 'short_name', 'sequence', 'task_pack_id', 'color'])
            ->map(fn (WorkflowPhase $phase): array => [
                'id' => (int) $phase->id,
                'workflow_id' => (int) ($phase->workflow_template_id ?: $phase->workflow_id),
                'label' => $this->dashboardPhaseLabel($phase->name, $phase->short_name),
                'short_label' => $this->dashboardPhaseShortLabel($phase->short_name, $phase->name),
                'color' => MasterColor::normalize((string) ($phase->color ?? '')),
                'sequence' => (int) $phase->sequence,
                'task_pack_id' => $phase->task_pack_id ? (int) $phase->task_pack_id : null,
            ])
            ->values();
    }

    private function compileWorkflowPhaseDistribution(Collection $definitions, array $counts, Collection $workflowIds, array $activeClientsByWorkflow): array
    {
        $workflowIds = $workflowIds->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $templates = $workflowIds->isEmpty()
            ? collect()
            : WorkflowTemplate::query()
                ->whereIn('id', $workflowIds)
                ->with('clients:id,name')
                ->get(['id', 'name', 'client_availability'])
                ->keyBy('id');

        $groups = [];
        foreach ($definitions as $phase) {
            $key = $this->dashboardPhaseKey($phase['label']);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $phase['label'],
                    'short_label' => (string) ($phase['short_label'] ?? $phase['label']),
                    'color' => MasterColor::normalize((string) ($phase['color'] ?? '')),
                    'count' => 0,
                    'sequence' => (int) $phase['sequence'],
                    'workflow_ids' => [],
                ];
            }
            $groups[$key]['sequence'] = min($groups[$key]['sequence'], (int) $phase['sequence']);
            $groups[$key]['workflow_ids'][(int) $phase['workflow_id']] = true;
        }

        foreach ($counts as $key => $countRow) {
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'label' => (string) $countRow['label'],
                    'short_label' => (string) ($countRow['short_label'] ?? $countRow['label']),
                    'color' => MasterColor::normalize((string) ($countRow['color'] ?? '')),
                    'count' => 0,
                    'sequence' => (int) $countRow['sequence'],
                    'workflow_ids' => [],
                ];
            }
            $groups[$key]['count'] += (int) $countRow['count'];
            $groups[$key]['sequence'] = min($groups[$key]['sequence'], (int) $countRow['sequence']);
            if (blank($groups[$key]['short_label'] ?? null) && filled($countRow['short_label'] ?? null)) {
                $groups[$key]['short_label'] = (string) $countRow['short_label'];
            }
            if (blank($groups[$key]['color'] ?? null) && filled($countRow['color'] ?? null)) {
                $groups[$key]['color'] = MasterColor::normalize((string) $countRow['color']);
            }
            foreach (array_keys($countRow['workflow_ids'] ?? []) as $workflowId) {
                if ((int) $workflowId > 0) $groups[$key]['workflow_ids'][(int) $workflowId] = true;
            }
        }

        $workflowCount = max(1, $workflowIds->count());
        $rows = collect($groups)->map(function (array $group) use ($workflowCount, $templates, $activeClientsByWorkflow): array {
            $phaseWorkflowIds = collect(array_keys($group['workflow_ids']))->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $isMismatch = $workflowCount > 1 && $phaseWorkflowIds->count() < $workflowCount;
            $scopeNames = collect();
            $scopeType = null;

            if ($isMismatch) {
                foreach ($phaseWorkflowIds as $workflowId) {
                    $template = $templates->get($workflowId);
                    if ($template && (string) $template->client_availability === 'specific') {
                        $scopeNames = $scopeNames->concat($template->clients->pluck('name'));
                    } else {
                        $scopeNames = $scopeNames->concat($activeClientsByWorkflow[$workflowId] ?? []);
                    }
                }
                $scopeNames = $scopeNames->filter()->map(fn ($name) => trim((string) $name))->filter()->unique()->sort()->values();
                if ($scopeNames->isNotEmpty()) {
                    $scopeType = 'client';
                } else {
                    $scopeNames = $phaseWorkflowIds
                        ->map(fn ($workflowId) => trim((string) ($templates->get($workflowId)?->name ?: '')))
                        ->filter()->unique()->values();
                    $scopeType = $scopeNames->isNotEmpty() ? 'workflow' : null;
                }
            }

            $scopeLabel = $scopeNames->implode(', ');
            return [
                'label' => (string) $group['label'],
                'short_label' => (string) ($group['short_label'] ?? $group['label']),
                'color' => MasterColor::normalize((string) ($group['color'] ?? '')),
                'count' => (int) $group['count'],
                'sequence' => (int) $group['sequence'],
                'is_mismatch' => $isMismatch,
                'scope_type' => $scopeType,
                'scope_label' => $scopeLabel,
                'scope_text' => $isMismatch && $scopeLabel !== ''
                    ? (($scopeType === 'client' ? ($scopeNames->count() > 1 ? 'Clients' : 'Client') : 'Workflow').' · '.$scopeLabel)
                    : '',
            ];
        })
            ->sortBy(fn (array $row) => [($row['label'] === 'Unassigned' ? 99999 : $row['sequence']), mb_strtolower($row['label'])])
            ->values();

        return $rows->all();
    }

    private function clientNamesForIds(Collection $clientIds): Collection
    {
        $clientIds = $clientIds->filter()->map(fn ($id) => (int) $id)->unique()->values();
        return $clientIds->isEmpty()
            ? collect()
            : Client::query()->whereIn('id', $clientIds)->pluck('name', 'id');
    }

    private function activeClientNamesByWorkflow(Collection $rows, Collection $clientNames, string $workflowField): array
    {
        $map = [];
        foreach ($rows as $row) {
            $workflowId = (int) ($row->{$workflowField} ?? 0);
            $clientId = (int) ($row->client_id ?? 0);
            $name = trim((string) ($clientNames->get($clientId) ?? ''));
            if ($workflowId <= 0 || $name === '') continue;
            $map[$workflowId][$name] = $name;
        }
        return array_map(fn (array $names) => array_values($names), $map);
    }

    private function dashboardPhaseLabel(?string $name, ?string $shortName = null): string
    {
        $label = trim((string) $name);
        if ($label === '') $label = trim((string) $shortName);
        return $label !== '' ? $label : 'Unassigned';
    }

    private function dashboardPhaseShortLabel(?string $shortName, ?string $name = null): string
    {
        $label = trim((string) $shortName);
        if ($label === '') $label = trim((string) $name);
        return $label !== '' ? $label : 'Unassigned';
    }

    private function dashboardPhaseKey(string $label): string
    {
        return mb_strtolower(trim((string) preg_replace('/\\s+/u', ' ', $label)));
    }

    public function taskStatusDistribution(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        int $rangeDays = 7,
    ): array
    {
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        $rangeDays = in_array($rangeDays, [1, 7, 30], true) ? $rangeDays : 7;
        [$rangeFrom, $rangeTo] = $this->dashboardRangeUtcBounds($rangeDays);

        $resolver = function () use ($user, $clientId, $departmentId, $rangeFrom, $rangeTo): array {
            // Distribution must represent every configured Master Data status,
            // including Completed/Cancelled. Therefore do not start from the
            // dashboard's ongoing-task query, which intentionally removes terminal
            // statuses. The date/client/team filters still apply to the underlying
            // visible task rows.
            $orderTasks = app(TaskService::class)->visibleQuery($user)
                ->reorder()
                ->whereBetween('tasks.updated_at', [$rangeFrom, $rangeTo])
                ->whereHas('job', fn (Builder $job) => $job
                    ->whereHas('client', fn (Builder $client) => $client->where('clients.is_active', true)))
                ->when($clientId > 0, fn (Builder $query) => $query->whereHas('job', fn (Builder $job) => $job->where('client_id', $clientId)))
                ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId)));

            $inquiryTasks = app(AccessControlService::class)
                ->applyInquiryTaskScope(InquiryTask::query(), $user)
                ->whereBetween('inquiry_tasks.updated_at', [$rangeFrom, $rangeTo])
                ->whereHas('inquiry', fn (Builder $inquiry) => $inquiry
                    ->where('inquiries.workspace_id', app(MasterDataService::class)->workspaceId())
                    ->whereHas('client', fn (Builder $client) => $client->where('clients.is_active', true)))
                ->when($clientId > 0, fn (Builder $query) => $query->whereHas('inquiry', fn (Builder $inquiry) => $inquiry->where('client_id', $clientId)))
                ->when($departmentId > 0, fn (Builder $query) => $query->whereHas('assignee', fn (Builder $assignee) => $assignee->where('department_id', $departmentId)));

            return [
                'orders' => $this->buildTaskStatusDistribution($orderTasks, 'order_task_status'),
                'inquiries' => $this->buildTaskStatusDistribution($inquiryTasks, 'inquiry_task_status'),
            ];
        };

        // Status changes are inline/realtime actions, so this panel intentionally
        // bypasses the short dashboard cache and always reflects the saved task rows.
        return $resolver();
    }

    /**
     * Master Data is the single source of truth for dashboard task-status rows.
     *
     * Only active statuses configured for the requested type are displayed, and
     * they stay in the exact Master Data sort_order sequence. Task rows carrying
     * an old/unconfigured status are intentionally not surfaced as extra dashboard
     * statuses; they should be corrected through the task/master-data workflow.
     */
    private function buildTaskStatusDistribution(Builder $query, string $masterType): array
    {
        $countRows = (clone $query)
            ->selectRaw("coalesce(nullif(trim(status), ''), 'Unspecified') as dashboard_status")
            ->selectRaw('count(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [];
        foreach ($countRows as $row) {
            $label = trim((string) ($row->dashboard_status ?: 'Unspecified')) ?: 'Unspecified';
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $label) ?: $label);
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->aggregate;
        }

        // active() is already ordered by Master Data sort_order, then name.
        // Do not re-sort or append task-only/legacy statuses here.
        $masterStatuses = app(MasterDataService::class)->active($masterType)->values();
        $rows = [];

        foreach ($masterStatuses as $record) {
            $label = trim((string) $record->name);
            if ($label === '') continue;

            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $label) ?: $label);
            $rows[] = [
                'label' => $label,
                'count' => (int) ($counts[$key] ?? 0),
                'color' => MasterColor::normalize((string) ($record->color ?? '')) ?: MasterColor::defaultFor($masterType, $label),
                'configured' => true,
                'sort_order' => (int) ($record->sort_order ?? 0),
            ];
        }

        return [
            'total' => (int) array_sum(array_column($rows, 'count')),
            'rows' => $rows,
        ];
    }

    public function catalogueReadiness(User $user): array
    {
        return $this->remember($user, 'catalogue-readiness', function (): array {
            $workspaceId = app(MasterDataService::class)->workspaceId();
            $products = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product')
                ->active();
            $totalProducts = (clone $products)->count();
            $percent = static fn (int $value, int $total): int => $total > 0 ? (int) round(($value / $total) * 100) : 0;

            $withImages = (clone $products)->whereNotNull('metadata->product_image_path')->count();
            $categorized = (clone $products)->whereNotNull('parent_id')->count();
            $availability = (clone $products)->where(function (Builder $query): void {
                $query->whereNotNull('metadata->client_availability')
                    ->orWhereNotNull('metadata->client_availability_labels')
                    ->orWhereNotNull('metadata->client_ids');
            })->count();
            $certificates = (clone $products)->where(function (Builder $query): void {
                $query->whereNotNull('metadata->certificate_test_report_path')
                    ->orWhereNotNull('metadata->certificate_test_report_url')
                    ->orWhereNotNull('metadata->certificate_test_report');
            })->count();

            $templates = (clone $products)->where(function (Builder $query): void {
                $query->whereNotNull('metadata->template_doc_path')
                    ->orWhereNotNull('metadata->template_doc_url')
                    ->orWhereNotNull('metadata->template_doc');
            })->count();

            $mainCategories = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('product_main_category')
                ->active()
                ->count();

            $supplierBase = MasterRecord::query()->forWorkspace($workspaceId)->ofType('supplier');
            $supplierTotal = (clone $supplierBase)->count();
            $activeSuppliers = (clone $supplierBase)->active()->count();

            $rows = [
                [
                    'label' => 'Product images',
                    'value' => $percent($withImages, $totalProducts),
                    'detail' => number_format($withImages).' of '.number_format($totalProducts),
                    'tone' => 'amber',
                ],
                [
                    'label' => 'Category mapping',
                    'value' => $percent($categorized, $totalProducts),
                    'detail' => number_format($categorized).' mapped',
                    'tone' => 'green',
                ],
                [
                    'label' => 'Client availability',
                    'value' => $percent($availability, $totalProducts),
                    'detail' => number_format($availability).' mapped',
                    'tone' => 'green',
                ],
                [
                    'label' => 'Certificates linked',
                    'value' => $percent($certificates, $totalProducts),
                    'detail' => number_format($certificates).' linked',
                    'tone' => 'red',
                ],
                [
                    'label' => 'Product templates',
                    'value' => $percent($templates, $totalProducts),
                    'detail' => number_format($templates).' linked',
                    'tone' => 'amber',
                ],
                [
                    'label' => 'Supplier mapping',
                    'value' => $percent($activeSuppliers, $supplierTotal),
                    'detail' => number_format($activeSuppliers).' active',
                    'tone' => 'blue',
                ],
            ];

            $readyPercent = $rows === []
                ? 0
                : (int) round(collect($rows)->avg(fn (array $row): int => (int) $row['value']));

            return [
                'activeProducts' => $totalProducts,
                'mainCategories' => $mainCategories,
                'activeSuppliers' => $activeSuppliers,
                'readyPercent' => $readyPercent,
                'rows' => $rows,
            ];
        });
    }

    public function dashboardClients(User $user): Collection
    {
        return app(ClientService::class)->visibleQuery($user)
            ->where('clients.is_active', true)
            ->orderBy('clients.name')
            ->limit(100)
            ->get(['clients.id', 'clients.name']);
    }

    public function dashboardDepartments(User $user): Collection
    {
        $query = Department::query()->where('is_active', true)->orderBy('name');
        if (!app(AccessControlService::class)->isAdministrator($user) && app(AccessControlService::class)->scope($user, 'tasks') !== 'all_records') {
            $query->whereKey($user->department_id ?: 0);
        }
        return $query->get(['id', 'name']);
    }

    private function activeTaskQuery(User $user): Builder
    {
        return $this->constrainOngoingTasks(
            app(TaskService::class)->visibleQuery($user)
        );
    }

    /**
     * Keep every dashboard "ongoing task" count/list aligned with the task board.
     * A task is ongoing only while both the task and its Order are operational.
     * We deliberately check both completed_at and the normalized status because
     * older/imported rows can have status=Completed without a completion timestamp.
     */
    private function constrainOngoingTasks(Builder $tasks): Builder
    {
        return $tasks
            ->whereNull('tasks.completed_at')
            ->whereRaw("LOWER(TRIM(tasks.status)) != 'completed'")
            ->whereHas('job', fn (Builder $job) => $job
                ->whereNull('flow_jobs.completed_at')
                ->whereRaw("LOWER(TRIM(flow_jobs.status)) != 'completed'")
                ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)
                ->whereHas('client', fn (Builder $client) => $client->where('clients.is_active', true)));
    }

    private function dashboardRangeUtcBounds(int $rangeDays): array
    {
        $rangeDays = in_array($rangeDays, [1, 7, 30], true) ? $rangeDays : 7;
        $settings = app(WorkspaceSettingsService::class);
        $today = $settings->localToday();

        return $settings->localDateRangeUtcBounds(
            $today->subDays($rangeDays - 1)->toDateString(),
            $today->toDateString(),
        );
    }

    private function remember(User $user, string $section, Closure $resolver): mixed
    {
        $seconds = max(
            10,
            (int) config('performance.dashboard_cache_seconds', 45)
        );

        $key = $this->cacheKey($section, (int) $user->id);

        $missing = new \stdClass();

        $cached = Cache::get($key, $missing);

        if ($cached !== $missing) {
            if ($this->isSafeCacheValue($cached)) {
                return $cached;
            }

            Cache::forget($key);
        }

        $value = $resolver();

        if (!$this->isSafeCacheValue($value)) {
            throw new \LogicException(
                'Dashboard cache values must contain only arrays, scalars, or null.'
            );
        }

        Cache::put(
            $key,
            $value,
            now()->addSeconds($seconds)
        );

        return $value;
    }

    private function isSafeCacheValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$this->isSafeCacheValue($item)) {
                return false;
            }
        }

        return true;
    }

    private function cacheKey(string $section, int $userId): string
    {
        $clientVersion = $this->clientLifecycleVersion ??=
            app(ClientService::class)->lifecycleVersion();

        $workspaceDataVersion = app(WorkspaceRefreshService::class)->version();

        return 'flowtrack:dashboard:'
            .self::CACHE_VERSION
            .':clients-'.$clientVersion
            .':data-'.$workspaceDataVersion
            .':'.$section
            .':user:'.$userId;
    }
}
