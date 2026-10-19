<?php

namespace App\Services;

use App\Models\Department;
use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\FlowJobMember;
use App\Models\FlowJobPhaseHistory;
use App\Models\FlowTaskChecklistItem;
use App\Models\FlowTaskComment;
use App\Models\Inquiry;
use App\Models\MasterRecord;
use App\Models\TaskPackItem;
use App\Models\Task;
use App\Models\TaskLink;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowPhase;
use App\Support\BoardLaneResolver;
use App\Support\JobDetailPresenter;
use App\Support\OrderDetailPresenter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LegacyJobService
{
    public const INACTIVE_STATUSES = ['Inactive', 'Cancelled'];

    public function visibleQuery(User $user): Builder
    {
        return app(AccessControlService::class)->applyJobScope(FlowJob::query(), $user, 'jobs');
    }

    public function activeQuery(User $user): Builder
    {
        return $this->visibleQuery($user)
            ->whereHas('client', fn ($client) => $client->where('is_active', true))
            ->whereNull('completed_at')
            ->whereNotIn('status', self::INACTIVE_STATUSES);
    }

    public function filteredQuery(User $user, array $filters): Builder
    {
        $quick = (string) ($filters['quick'] ?? 'all');

        return $this->visibleQuery($user)
            ->whereHas('client', fn ($client) => $client->where('is_active', true))
            ->when($quick !== 'completed', fn ($q) => $q->whereNull('completed_at'))
            ->when($quick !== 'completed' && empty($filters['status']), fn ($q) => $q->whereNotIn('status', self::INACTIVE_STATUSES))
            ->when($quick === 'completed', fn ($q) => $q->whereNotNull('completed_at'))
            ->when($quick === 'attention', fn ($q) => $q->where(fn ($x) => $x->where('attention_requested', true)->orWhere('needs_attention', true)))
            ->when($quick === 'due_week', fn ($q) => $q->whereBetween('delivery_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->addDays(7)]))
            ->when($quick === 'waiting', fn ($q) => $q->whereHas('tasks', fn ($t) => $t->where('status', 'like', 'Waiting%')->whereNull('completed_at')))
            ->when($quick === 'invoice', fn ($q) => $q->where(fn ($x) => $x->where('commercial_value', '<=', 0)->orWhereHas('phase', fn ($p) => $p->where('short_name', 'Invoice'))))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($x) use ($search) {
                    $x->whereLike('job_number', "%{$search}%")
                        ->orWhereLike('order_number', "%{$search}%")
                        ->orWhereLike('title', "%{$search}%")
                        ->orWhereLike('product', "%{$search}%")
                        ->orWhereHas('client', fn ($c) => $c->whereLike('name', "%{$search}%"));
                });
            })
            ->when($filters['phase'] ?? null, fn ($q, $v) => $q->where(function ($phaseQuery) use ($v) {
                $phaseQuery->where('source_workflow_phase_id', $v)
                    ->orWhere(function ($legacy) use ($v) {
                        $legacy->whereNull('source_workflow_phase_id')->where('workflow_phase_id', $v);
                    });
            }))
            ->when($filters['client'] ?? null, fn ($q, $v) => $q->where('client_id', $v))
            ->when($filters['owner'] ?? null, fn ($q, $v) => $q->where('owner_id', $v))
            ->when($filters['assignee'] ?? null, function ($q, $v) use ($user) {
                $q->whereHas('tasks', function ($tasks) use ($user, $v) {
                    app(AccessControlService::class)->applyTaskScope($tasks, $user)->where('tasks.assignee_id', $v);
                });
            })
            ->when($filters['priority'] ?? null, fn ($q, $v) => $q->where('priority', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(($filters['delivery'] ?? null) === 'week', fn ($q) => $q->whereBetween('delivery_date', [app(WorkspaceSettingsService::class)->localToday(), app(WorkspaceSettingsService::class)->localToday()->addDays(7)]))
            ->when(($filters['delivery'] ?? null) === 'overdue', fn ($q) => $q->where('delivery_date', '<', app(WorkspaceSettingsService::class)->localToday()->toDateString())->whereNull('completed_at'))
            ->when(($filters['delivery'] ?? null) === 'none', fn ($q) => $q->whereNull('delivery_date'))
            ->when(($filters['invoice'] ?? null) === 'pending', fn ($q) => $q->where('commercial_value', '<=', 0))
            ->when(($filters['invoice'] ?? null) === 'draft', fn ($q) => $q->where('commercial_value', '>', 0))
            ->latest('id');
    }

    public function filteredIds(User $user, array $filters): Collection
    {
        return $this->filteredQuery($user, $filters)
            ->reorder('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    public function paginate(User $user, array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->filteredQuery($user, $filters)->reorder();
        match ($filters['sort'] ?? 'updated_desc') {
            'due_asc' => $query->orderByRaw('delivery_date is null, delivery_date asc')->orderByDesc('id'),
            'priority_desc' => $query->orderByRaw("case priority when 'Critical' then 5 when 'Urgent' then 4 when 'High' then 3 when 'Medium' then 2 when 'Normal' then 2 when 'Low' then 1 else 0 end desc")->orderByDesc('updated_at'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };

        return $query
            ->select([
                'flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.order_number', 'flow_jobs.client_id',
                'flow_jobs.workflow_phase_id', 'flow_jobs.owner_id', 'flow_jobs.coordinator_id', 'flow_jobs.created_by',
                'flow_jobs.title', 'flow_jobs.product', 'flow_jobs.quantity', 'flow_jobs.next_action',
                'flow_jobs.status', 'flow_jobs.priority', 'flow_jobs.progress',
                'flow_jobs.delivery_date', 'flow_jobs.commercial_value', 'flow_jobs.currency',
                'flow_jobs.needs_attention', 'flow_jobs.attention_requested', 'flow_jobs.attention_reason', 'flow_jobs.order_flag_id', 'flow_jobs.completed_at', 'flow_jobs.updated_at',
            ])
            ->with([
                'client:id,code,name,logo_path',
                'orderFlag:id,type,name,color,status,sort_order,metadata',
                'phase:id,name,short_name,sequence,color',
                'owner:id,name,profile_image_path',
                'coordinator:id,name,profile_image_path',
                'members:id,flow_job_id,user_id',
                'tasks' => fn ($query) => app(AccessControlService::class)
                    ->applyTaskScope($query, $user)
                    ->select(['tasks.id', 'tasks.flow_job_id', 'tasks.workflow_phase_id', 'tasks.assignee_id', 'tasks.title', 'tasks.status', 'tasks.due_date', 'tasks.completed_at'])
                    ->whereNull('completed_at')
                    ->where('status', '!=', 'Completed')
                    ->whereHas('job', fn ($job) => $job->whereColumn('flow_jobs.workflow_phase_id', 'tasks.workflow_phase_id'))
                    ->with(['assignee:id,name,profile_image_path', 'phase:id,name,sequence,color'])
                    ->orderByRaw('due_date is null, due_date asc'),
            ])
            ->withCount('items')
            ->paginate($perPage);
    }


    /**
     * Canonical query for the Orders list. Export uses this same builder so
     * downloaded records always match the user's visible scope and active
     * list filters instead of exporting a broader data set.
     */
    public function ordersListQuery(
        User $user,
        string $search = '',
        ?int $clientId = null,
        ?int $phaseId = null,
        ?int $assigneeId = null,
        ?int $ownerId = null,
        string $metricFilter = '',
        string $dateFrom = '',
        string $dateTo = '',
        ?int $bulkImportId = null,
    ): Builder
    {
        $search = trim($search);
        [$dateFromUtc, $dateToUtc] = app(WorkspaceSettingsService::class)->localDateRangeUtcBounds($dateFrom, $dateTo);
        $searchLength = mb_strlen($search);
        if (!in_array($metricFilter, ['', 'createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completed', 'completedThisWeek', 'attention', 'dashboardActive', 'dashboardAttention', 'dashboardOverdueTasks'], true)) {
            $metricFilter = '';
        }

        // Avoid fan-out searches across Orders, clients, owners, items,
        // inquiries and creator activity for inputs that are too short to
        // narrow the result set meaningfully.
        if ($searchLength > 0 && $searchLength < 3) {
            $search = '';
        }

        $tokens = collect(preg_split('/\s+/', $search) ?: [])
            ->filter()
            ->take(6)
            ->values();

        $query = $this->visibleQuery($user)
            ->when($metricFilter === '', fn (Builder $query) => $query->whereNotIn('flow_jobs.status', self::INACTIVE_STATUSES))
            ->when($clientId, fn (Builder $query) => $query->where('flow_jobs.client_id', $clientId))
            ->when($phaseId, fn (Builder $query) => $query->where(function (Builder $phaseQuery) use ($phaseId): void {
                $phaseQuery->where('flow_jobs.source_workflow_phase_id', $phaseId)
                    ->orWhere(function (Builder $legacy) use ($phaseId): void {
                        $legacy->whereNull('flow_jobs.source_workflow_phase_id')
                            ->where('flow_jobs.workflow_phase_id', $phaseId);
                    });
            }))
            ->when($assigneeId, function (Builder $query) use ($user, $assigneeId): void {
                $query->whereHas('tasks', function (Builder $tasks) use ($user, $assigneeId): void {
                    app(AccessControlService::class)
                        ->applyTaskScope($tasks, $user)
                        ->where('tasks.assignee_id', $assigneeId);
                });
            })
            ->when($ownerId, fn (Builder $query) => $query->where('flow_jobs.owner_id', $ownerId))
            ->when($dateFromUtc, fn (Builder $query) => $query->where('flow_jobs.created_at', '>=', $dateFromUtc))
            ->when($dateToUtc, fn (Builder $query) => $query->where('flow_jobs.created_at', '<=', $dateToUtc))
            ->when($bulkImportId, function (Builder $query) use ($bulkImportId): void {
                // Filter by the immutable import-row audit trail instead of the
                // flow_jobs.bulk_import_id convenience field. An existing Order
                // may be updated by another import later, but this link must keep
                // representing exactly the batch the user just completed.
                $query->whereExists(function ($importRows) use ($bulkImportId): void {
                    $importRows->selectRaw('1')
                        ->from('bulk_order_import_rows as imported_order_rows')
                        ->whereColumn('imported_order_rows.flow_job_id', 'flow_jobs.id')
                        ->where('imported_order_rows.bulk_order_import_id', $bulkImportId)
                        ->whereIn('imported_order_rows.status', ['created', 'updated']);
                });
            });

        if ($metricFilter !== '') {
            $query = $this->applySummaryMetricScope($query, $metricFilter, $user);
        }

        foreach ($tokens as $token) {
            $token = (string) $token;
            $legacyToken = preg_replace('/^ORDER-/i', 'JOB-', $token) ?: $token;
            $looksLikeReference = preg_match('/^(ORDER|JOB|ORD)[-0-9]/i', $token) === 1;
            $like = $looksLikeReference ? $token.'%' : '%'.$token.'%';
            $legacyLike = $looksLikeReference ? $legacyToken.'%' : '%'.$legacyToken.'%';

            $query->where(function (Builder $match) use ($like, $legacyLike) {
                $match->whereLike('flow_jobs.job_number', $like)
                    ->orWhereLike('flow_jobs.job_number', $legacyLike)
                    ->orWhereLike('flow_jobs.order_number', $like)
                    ->orWhereLike('flow_jobs.title', $like)
                    ->orWhereLike('flow_jobs.product', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like))
                    ->orWhereHas('owner', fn (Builder $owner) => $owner->whereLike('name', $like))
                    ->orWhereHas('items', fn (Builder $item) => $item
                        ->whereLike('product_name', $like)
                        ->orWhereLike('category_name', $like))
                    ->orWhereHas('linkedInquiries', fn (Builder $inquiry) => $inquiry
                        ->whereLike('inquiry_number', $like)
                        ->orWhereLike('reference_number', $like)
                        ->orWhereLike('subject', $like))
                    // Legacy fallback for an Order not yet backfilled into the
                    // many-link table during a rolling deployment.
                    ->orWhereHas('sourceInquiry', fn (Builder $inquiry) => $inquiry
                        ->whereLike('inquiry_number', $like)
                        ->orWhereLike('reference_number', $like)
                        ->orWhereLike('subject', $like))
                    ->orWhereHas('createdActivity.user', fn (Builder $creator) => $creator->whereLike('name', $like));
            });
        }

        return $query
            ->reorder()
            ->orderByDesc('flow_jobs.created_at')
            ->orderByDesc('flow_jobs.id');
    }

    /**
     * Performance-oriented Orders list query used by the prototype-faithful
     * list page. It renders the first page on the server, selects only fields
     * visible in the list and eager-loads only the compact relations needed by
     * each row. Completed Orders remain visible; cancelled/inactive records do
     * not appear in the operational Orders list.
     */
    public function paginateOrders(
        User $user,
        string $search = '',
        int $perPage = 25,
        ?int $clientId = null,
        ?int $phaseId = null,
        ?int $assigneeId = null,
        ?int $ownerId = null,
        string $metricFilter = '',
        string $dateFrom = '',
        string $dateTo = '',
        ?int $bulkImportId = null,
    ): LengthAwarePaginator
    {
        return $this->ordersListQuery(
            $user,
            $search,
            $clientId,
            $phaseId,
            $assigneeId,
            $ownerId,
            $metricFilter,
            $dateFrom,
            $dateTo,
            $bulkImportId,
        )
            ->select([
                'flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.order_number',
                'flow_jobs.client_id', 'flow_jobs.workflow_phase_id', 'flow_jobs.source_workflow_phase_id', 'flow_jobs.owner_id', 'flow_jobs.source_inquiry_id',
                'flow_jobs.title', 'flow_jobs.product', 'flow_jobs.quantity',
                'flow_jobs.status', 'flow_jobs.priority',
                'flow_jobs.progress', 'flow_jobs.delivery_date', 'flow_jobs.needs_attention', 'flow_jobs.attention_requested', 'flow_jobs.attention_reason', 'flow_jobs.order_flag_id',
                'flow_jobs.completed_at', 'flow_jobs.created_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'orderFlag:id,type,name,color,status,sort_order,metadata',
                'sourceInquiry:id,inquiry_number,reference_number',
                'phase:id,name,short_name,sequence,color',
                'owner:id,name,profile_image_path',
                'items:id,flow_job_id,product_name,category_name,quantity,unit_price,is_removed,sort_order',
                'createdActivity' => fn ($activity) => $activity->select([
                    'activities.id',
                    'activities.subject_type',
                    'activities.subject_id',
                    'activities.user_id',
                    'activities.created_at',
                ]),
                'createdActivity.user:id,name,profile_image_path',
                'flaggedTasks' => fn ($taskQuery) => app(AccessControlService::class)
                    ->applyTaskScope($taskQuery, $user)
                    ->select(['tasks.id', 'tasks.flow_job_id', 'tasks.status', 'tasks.due_date', 'tasks.completed_at', 'tasks.needs_attention', 'tasks.order_task_flag_id', 'tasks.attention_reason'])
                    ->with('orderTaskFlag:id,type,name,color,status,sort_order,metadata'),
            ])
            ->paginate(max(1, min($perPage, 50)));
    }

    public function bulkImportNumber(int $importId): ?string
    {
        if ($importId < 1) {
            return null;
        }

        $number = DB::table('bulk_order_imports')
            ->where('id', $importId)
            ->where('workspace_id', app(SetupContext::class)->workspaceId())
            ->value('import_number');

        return filled($number) ? (string) $number : null;
    }

    public function summaryCounts(User $user): array
    {
        $base = $this->visibleQuery($user);

        return [
            'createdToday' => (int) $this->applyCreatedTodayOrderScope(clone $base)->count(),
            'notStarted' => (int) $this->applyNotStartedOrderScope(clone $base)->count(),
            'inProgress' => (int) $this->applyInProgressOrderScope(clone $base)->count(),
            'dueThisWeek' => (int) $this->applyDueThisWeekOrderScope(clone $base)->count(),
            'completed' => (int) $this->applyCompletedOrderScope(clone $base)->count(),
            'completedThisWeek' => (int) $this->applyCompletedThisWeekOrderScope(clone $base)->count(),
            'attention' => (int) $this->applyNeedsAttentionOrderScope(clone $base)->count(),
        ];
    }

    private function applySummaryMetricScope(Builder $query, string $metric, User $user): Builder
    {
        return match ($metric) {
            'createdToday' => $this->applyCreatedTodayOrderScope($query),
            'notStarted' => $this->applyNotStartedOrderScope($query),
            'inProgress' => $this->applyInProgressOrderScope($query),
            'dueThisWeek' => $this->applyDueThisWeekOrderScope($query),
            'completed' => $this->applyCompletedOrderScope($query),
            'completedThisWeek' => $this->applyCompletedThisWeekOrderScope($query),
            'attention' => $this->applyNeedsAttentionOrderScope($query),
            'dashboardActive' => $this->applyDashboardActiveOrderScope($query),
            'dashboardAttention' => $this->applyDashboardAttentionOrderScope($query),
            'dashboardOverdueTasks' => $this->applyDashboardOverdueTaskOrderScope($query, $user),
            default => $query,
        };
    }

    /** Match the Dashboard "Active orders" KPI exactly. */
    private function applyDashboardActiveOrderScope(Builder $query): Builder
    {
        return $query
            ->whereHas('client', fn (Builder $client) => $client->where('is_active', true))
            ->whereNull('flow_jobs.completed_at')
            ->whereNotIn('flow_jobs.status', self::INACTIVE_STATUSES);
    }

    /** Match the Dashboard "Needs attention" KPI exactly (Order-level attention). */
    private function applyDashboardAttentionOrderScope(Builder $query): Builder
    {
        return $this->applyDashboardActiveOrderScope($query)
            ->where(function (Builder $attention): void {
                $attention->where('flow_jobs.attention_requested', true)
                    ->orWhere('flow_jobs.needs_attention', true)
;
            });
    }

    /**
     * Dashboard overdue count is task-based; the Orders destination therefore
     * shows each visible active Order that contains at least one visible overdue task.
     */
    private function applyDashboardOverdueTaskOrderScope(Builder $query, User $user): Builder
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        return $this->applyDashboardActiveOrderScope($query)
            ->whereRaw("LOWER(TRIM(COALESCE(flow_jobs.status, ''))) != 'completed'")
            ->whereHas('tasks', function (Builder $tasks) use ($user, $today): void {
                app(AccessControlService::class)
                    ->applyTaskScope($tasks, $user)
                    ->whereNull('tasks.completed_at')
                    ->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) != 'completed'")
                    ->whereDate('tasks.due_date', '<', $today);
            });
    }

    private function applyCreatedTodayOrderScope(Builder $query): Builder
    {
        $today = app(WorkspaceSettingsService::class)->localToday();

        return $query->whereBetween('flow_jobs.created_at', [
            $today->utc(),
            $today->endOfDay()->utc(),
        ]);
    }

    private function applyNotStartedOrderScope(Builder $query): Builder
    {
        return $this->applyOperationalOrderScope($query)
            ->whereNull('flow_jobs.completed_at')
            ->whereDoesntHave('tasks', fn (Builder $task) => $this->constrainStartedOrderTask($task));
    }

    private function applyInProgressOrderScope(Builder $query): Builder
    {
        return $this->applyOperationalOrderScope($query)
            ->whereNull('flow_jobs.completed_at')
            ->whereHas('tasks', fn (Builder $task) => $this->constrainStartedOrderTask($task));
    }

    private function applyDueThisWeekOrderScope(Builder $query): Builder
    {
        $today = app(WorkspaceSettingsService::class)->localToday();
        $weekStart = $today->startOfWeek()->toDateString();
        $weekEnd = $today->endOfWeek()->toDateString();

        return $this->applyOperationalOrderScope($query)
            ->whereNull('flow_jobs.completed_at')
            ->whereBetween('flow_jobs.delivery_date', [$weekStart, $weekEnd]);
    }

    private function applyCompletedThisWeekOrderScope(Builder $query): Builder
    {
        [$weekStartUtc, $weekEndUtc] = app(WorkspaceSettingsService::class)->localWeekUtcBounds();

        return $this->applyOperationalOrderScope($query)
            ->whereNotNull('flow_jobs.completed_at')
            ->whereBetween('flow_jobs.completed_at', [$weekStartUtc, $weekEndUtc]);
    }

    private function applyCompletedOrderScope(Builder $query): Builder
    {
        return $this->applyOperationalOrderScope($query)
            ->whereNotNull('flow_jobs.completed_at');
    }

    private function applyNeedsAttentionOrderScope(Builder $query): Builder
    {
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        return $this->applyOperationalOrderScope($query)
            ->whereNull('flow_jobs.completed_at')
            ->where(function (Builder $attention) use ($today): void {
                $attention->where('flow_jobs.attention_requested', true)
                    ->orWhere('flow_jobs.needs_attention', true)
                    ->orWhereNotNull('flow_jobs.order_flag_id')
                    ->orWhereHas('tasks', function (Builder $task) use ($today): void {
                        $task->whereNull('tasks.completed_at')
                            ->where(function (Builder $taskAttention) use ($today): void {
                                $taskAttention->where('tasks.needs_attention', true)
                                    ->orWhereNotNull('tasks.order_task_flag_id')
                                    ->orWhereNull('tasks.assignee_id')
                                    ->orWhereDate('tasks.due_date', '<', $today)
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%blocked%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%revision%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%overdue%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%delayed%'")
                                    ->orWhereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) LIKE '%attention%'");
                            });
                    });
            });
    }

    private function applyOperationalOrderScope(Builder $query): Builder
    {
        return $query->whereNotIn('flow_jobs.status', self::INACTIVE_STATUSES);
    }

    private function constrainStartedOrderTask(Builder $task): Builder
    {
        $initialStatuses = ['not started', 'not start', 'ready', 'to do', 'todo'];
        $placeholders = implode(',', array_fill(0, count($initialStatuses), '?'));

        return $task->where(function (Builder $started) use ($initialStatuses, $placeholders): void {
            $started->whereNotNull('tasks.completed_at')
                ->orWhere('tasks.progress', '>', 0)
                ->orWhere(function (Builder $status) use ($initialStatuses, $placeholders): void {
                    $status->whereRaw("LOWER(TRIM(COALESCE(tasks.status, ''))) <> ''")
                        ->whereRaw(
                            "LOWER(TRIM(COALESCE(tasks.status, ''))) NOT IN ($placeholders)",
                            $initialStatuses,
                        );
                });
        });
    }

    /**
     * Load the small, always-visible Order shell without hydrating the full
     * workflow/task/document/activity graph. Tab-specific data is loaded by
     * loadVisibleDetailTab() only when that tab is rendered.
     */
    public function findVisibleBase(User $user, int $id): FlowJob
    {
        return $this->visibleQuery($user)
            ->with([
                'client:id,name,contact_name,logo_path',
                'orderFlag:id,type,name,color,status,sort_order,metadata',
                'phase:id,name,short_name,sequence,color',
                'owner:id,name,profile_image_path',
                'coordinator:id,name,profile_image_path',
                'creator:id,name,profile_image_path',
                'cancelledBy:id,name,profile_image_path',
                'activeHold.holder:id,name,profile_image_path',
                'members.user:id,name,profile_image_path',
            ])
            ->when((bool) config('flowtrack.order_tracking_enabled', false), function ($query): void {
                $query->with([
                    'artworkTrackingPdf' => static function ($relation): void {
                        $relation->select([
                            'documents.id',
                            'documents.flow_job_id',
                            'documents.task_id',
                            'documents.category',
                            'documents.name',
                            'documents.path',
                            'documents.mime_type',
                            'documents.size',
                            'documents.version',
                            'documents.is_final',
                        ]);
                    },
                ]);
            })
            ->withCount(['documents', 'linkedInquiries'])
            ->findOrFail($id);
    }

    /**
     * Search compact Inquiry candidates for the Order > Inquiry tab.
     * Results stay permission-scoped and bounded so the detail page never
     * hydrates the full Inquiry dataset.
     */
    public function inquiryLinkResults(User $user, FlowJob $job, string $search, int $limit = 8): Collection
    {
        $term = trim($search);
        if (mb_strlen($term) < 2) return collect();
        if (!app(AccessControlService::class)->can($user, 'jobs', 'link')) return collect();
        if (!app(AccessControlService::class)->can($user, 'inquiries', 'view')) return collect();

        $tokens = collect(preg_split('/\s+/', $term) ?: [])
            ->filter()
            ->take(5)
            ->values();

        $query = app(InquiryService::class)->visibleQuery($user)
            ->select([
                'inquiries.id', 'inquiries.inquiry_number', 'inquiries.client_id', 'inquiries.owner_id',
                'inquiries.subject', 'inquiries.reference_number', 'inquiries.status', 'inquiries.result',
                'inquiries.converted_job_id', 'inquiries.updated_at',
            ])
            ->with([
                'client:id,name,logo_path',
                'owner:id,name,profile_image_path',
                'linkedOrders:id,job_number,order_number',
                'sourceOrder:id,source_inquiry_id,job_number,order_number',
                'convertedJob:id,job_number,order_number',
                'items' => fn ($items) => $items
                    ->select(['id', 'inquiry_id', 'item_name', 'category'])
                    ->orderBy('sort_order')
                    ->limit(3),
            ]);

        foreach ($tokens as $token) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $token).'%';
            $query->where(function (Builder $match) use ($like): void {
                $match->whereLike('inquiries.inquiry_number', $like)
                    ->orWhereLike('inquiries.reference_number', $like)
                    ->orWhereLike('inquiries.subject', $like)
                    ->orWhereLike('inquiries.requirement_notes', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like))
                    ->orWhereHas('owner', fn (Builder $owner) => $owner->whereLike('name', $like))
                    ->orWhereHas('items', fn (Builder $item) => $item
                        ->whereLike('item_name', $like)
                        ->orWhereLike('category', $like)
                        ->orWhereLike('notes', $like));
            });
        }

        return $query
            ->reorder()
            ->orderByRaw('case when inquiries.inquiry_number = ? then 0 else 1 end', [$term])
            ->orderByRaw('case when inquiries.client_id = ? then 0 else 1 end', [(int) $job->client_id])
            ->orderByDesc('inquiries.updated_at')
            ->orderByDesc('inquiries.id')
            ->limit(max(1, min(8, $limit)))
            ->get();
    }

    /**
     * Link an Inquiry to an Order. An Order may have many linked Inquiries,
     * while each Inquiry remains attached to at most one Order. The legacy
     * source_inquiry_id column is retained as the primary/oldest link so older
     * Order and reporting code continues to work unchanged.
     */
    public function linkSourceInquiry(FlowJob $job, int $inquiryId, User $actor): FlowJob
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'jobs', 'link'), 403);
        $this->visibleQuery($actor)->whereKey($job->id)->firstOrFail();
        abort_unless(app(AccessControlService::class)->can($actor, 'inquiries', 'view'), 403);

        return DB::transaction(function () use ($job, $inquiryId, $actor): FlowJob {
            $lockedJob = $this->visibleQuery($actor)
                ->lockForUpdate()
                ->findOrFail($job->id);

            $inquiry = app(InquiryService::class)->visibleQuery($actor)
                ->lockForUpdate()
                ->findOrFail($inquiryId);

            return $this->attachInquiryToOrder($lockedJob, $inquiry, $actor);
        }, 3);
    }

    /**
     * Attach one eligible Inquiry while the caller already owns the Order
     * transaction/lock. Shared by Create Order and Order Details so both paths
     * enforce exactly the same one-Inquiry-to-one-Order integrity rules.
     */
    private function attachInquiryToOrder(FlowJob $lockedJob, Inquiry $inquiry, User $actor): FlowJob
    {
        abort_if((string) $inquiry->result === 'dead', 422, 'A closed Inquiry cannot be linked to an Order.');

        $existingLink = DB::table('flow_job_inquiries')
            ->where('inquiry_id', $inquiry->id)
            ->lockForUpdate()
            ->first(['flow_job_id']);

        if ($existingLink) {
            abort_if((int) $existingLink->flow_job_id === (int) $lockedJob->id, 409, 'This Inquiry is already linked to this Order.');
            abort(409, 'This Inquiry is already linked to another Order.');
        }

        // Protect upgraded databases that contain a legacy pointer but have
        // not yet been normalized into the pivot for any reason.
        $legacyOrderId = FlowJob::query()
            ->where('source_inquiry_id', $inquiry->id)
            ->where('id', '!=', $lockedJob->id)
            ->value('id');
        abort_if($legacyOrderId, 409, 'This Inquiry is already linked to another Order.');

        if ($inquiry->converted_job_id && (int) $inquiry->converted_job_id !== (int) $lockedJob->id) {
            abort(409, 'This Inquiry is already linked to another Order.');
        }

        $lockedJob->linkedInquiries()->attach($inquiry->id, [
            'linked_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // The first linked Inquiry remains the legacy primary/source Inquiry.
        // Additional links live in flow_job_inquiries without replacing it.
        if (!$lockedJob->source_inquiry_id) {
            $lockedJob->update(['source_inquiry_id' => $inquiry->id]);
        }

        // Keep the existing reverse reference synchronized without changing
        // the Inquiry lifecycle status/result. Multiple Inquiries may point
        // to the same Order through converted_job_id.
        if (!$inquiry->converted_job_id) {
            $inquiry->update(['converted_job_id' => $lockedJob->id]);
        }

        $lockedJob->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.inquiry_linked',
            'description' => $inquiry->inquiry_number.' linked to this Order.',
        ]);

        return $lockedJob->refresh();
    }

    /** Remove one Inquiry/Order traceability relationship. */
    public function unlinkSourceInquiry(FlowJob $job, User $actor, ?int $inquiryId = null): FlowJob
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'jobs', 'link'), 403);
        $this->visibleQuery($actor)->whereKey($job->id)->firstOrFail();

        return DB::transaction(function () use ($job, $actor, $inquiryId): FlowJob {
            $lockedJob = $this->visibleQuery($actor)
                ->lockForUpdate()
                ->findOrFail($job->id);

            $targetInquiryId = (int) ($inquiryId ?: $lockedJob->source_inquiry_id ?: 0);
            abort_unless($targetInquiryId > 0, 409, 'This Order does not have a linked Inquiry.');

            $pivotLink = DB::table('flow_job_inquiries')
                ->where('flow_job_id', $lockedJob->id)
                ->where('inquiry_id', $targetInquiryId)
                ->lockForUpdate()
                ->exists();

            // Legacy fallback keeps pre-migration data recoverable, but never
            // allows an arbitrary Inquiry to be detached from the wrong Order.
            $isLegacyPrimary = (int) ($lockedJob->source_inquiry_id ?? 0) === $targetInquiryId;
            abort_unless($pivotLink || $isLegacyPrimary, 409, 'This Inquiry is not linked to this Order.');

            $inquiry = Inquiry::query()->lockForUpdate()->find($targetInquiryId);
            $number = (string) ($inquiry?->inquiry_number ?: 'Inquiry #'.$targetInquiryId);

            DB::table('flow_job_inquiries')
                ->where('flow_job_id', $lockedJob->id)
                ->where('inquiry_id', $targetInquiryId)
                ->delete();

            if ($inquiry && (int) $inquiry->converted_job_id === (int) $lockedJob->id) {
                $inquiry->update(['converted_job_id' => null]);
            }

            if ($isLegacyPrimary) {
                $replacementInquiryId = DB::table('flow_job_inquiries')
                    ->join('inquiries', 'inquiries.id', '=', 'flow_job_inquiries.inquiry_id')
                    ->where('flow_job_inquiries.flow_job_id', $lockedJob->id)
                    ->whereNull('inquiries.deleted_at')
                    ->orderBy('flow_job_inquiries.created_at')
                    ->orderBy('flow_job_inquiries.id')
                    ->value('flow_job_inquiries.inquiry_id');

                $lockedJob->update([
                    'source_inquiry_id' => $replacementInquiryId ? (int) $replacementInquiryId : null,
                ]);
            }

            $lockedJob->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.inquiry_unlinked',
                'description' => $number.' unlinked from this Order.',
            ]);

            return $lockedJob->refresh();
        }, 3);
    }

    /**
     * Hydrate only the relations required by the active Order detail tab.
     * Keeping these relation sets explicit prevents an Order open from
     * loading comments, histories, documents and workflow setup data that the
     * user has not asked to see.
     */
    public function loadVisibleDetailTab(FlowJob $job, User $user, string $tab): FlowJob
    {
        abort_unless(in_array($tab, ['overview', 'workflow', 'documents', 'inquiry', 'finance', 'redo'], true), 422);

        if ($tab === 'overview') {
            if (! $job->shipments()->exists()) {
                app(OrderShipmentService::class)->seedPrimaryShipment($job, $user);
            }

            $relations = [
                'workflow.phases.taskPack.items.documentCategory',
                'shippingSourceAddress:id,client_id,label,recipient,address_line1,suite,city,state,zip,country,is_default,sort_order',
                'shipments.shippingMethod:id,type,name,code,sort_order,status',
                'shipments.shipmentUrgency:id,type,name,code,sort_order,status',
                'shipments.courier:id,type,name,code,sort_order,status',
                'latestShipmentInformationActivity:activities.id,activities.subject_type,activities.subject_id,activities.event,activities.meta,activities.created_at',
                'latestCourierLabelActivity:activities.id,activities.subject_type,activities.subject_id,activities.event,activities.meta,activities.created_at',
                'tasks' => fn ($query) => app(AccessControlService::class)
                    ->applyTaskScope($query, $user)
                    ->with([
                        'assignee:id,name,profile_image_path',
                        'phase:id,name,short_name,sequence,color',
                        'template',
                        'documentCategory',
                        'setupTemplate.documentCategory',
                    ]),
                // The restored Archive 10 Overview visibly renders attachments.
                // Activity itself is paginated separately so opening an Order
                // never hydrates its entire history.
                'documents.uploader:id,name,profile_image_path',
                'documents.task:id,title',
            ];

            if (app(AccessControlService::class)->can($user, 'catalog_products', 'view')) {
                $relations[] = 'items.updatedBy:id,name,profile_image_path';
                $relations[] = 'items.removedBy:id,name,profile_image_path';
                $relations[] = 'items.supplier:id,name,code,metadata,type,status';
                $relations[] = 'items.catalogProduct:id,name,code,parent_id,type,status,metadata';
                $relations[] = 'items.catalogProduct.parent:id,name,code,type,status,metadata';
            }

            $job->load($relations);
            $this->hydrateLegacyOrderItemCatalogProducts($job);
            $this->hydratePublishedOrderWorkflow($job);
            $this->hydrateLoadedTaskLinks($job);
            $this->hydrateArtworkRevisionNotes($job);
            return $job;
        }

        if ($tab === 'workflow') {
            $job->load([
                'workflow.phases.taskPack.items.documentCategory',
                'phase.taskPack.items.documentCategory',
                'phaseHistories.phase',
                'phaseHistories.actor:id,name,profile_image_path',
                'tasks' => fn ($query) => app(AccessControlService::class)
                    ->applyTaskScope($query, $user)
                    ->with([
                        'assignee:id,name,profile_image_path',
                        'phase:id,name,short_name,sequence,color',
                        'template',
                        'documentCategory',
                        'setupTemplate.documentCategory',
                    ]),
                // Workflow only needs document identity/category to calculate
                // Task Pack requirement completion; uploader/file metadata is
                // reserved for the Documents tab.
                'documents:id,flow_job_id,task_id,category',
            ]);

            $this->hydratePublishedOrderWorkflow($job);
            $this->hydrateLoadedTaskLinks($job);
            return $job;
        }

        if ($tab === 'inquiry') {
            $access = app(AccessControlService::class);
            $canViewInquiries = $access->can($user, 'inquiries', 'view');
            $canViewDocuments = $canViewInquiries && $access->can($user, 'documents', 'view');

            if (!$canViewInquiries) {
                // Do not hydrate Inquiry metadata when the role cannot view the
                // Inquiry module. The tab shell can still explain the permission.
                $job->setRelation('linkedInquiries', new \Illuminate\Database\Eloquent\Collection());
                return $job;
            }

            $relations = [
                'linkedInquiries.client:id,name,logo_path',
                'linkedInquiries.owner:id,name,profile_image_path',
            ];

            if ($canViewDocuments) {
                // Read every linked Inquiry directly. No files are copied into the
                // Order; the existing document routes remain the final authorization
                // boundary when a user opens or downloads an attachment.
                $relations[] = 'linkedInquiries.documents:id,inquiry_id,inquiry_task_id,uploaded_by,name,mime_type,size,created_at';
                $relations[] = 'linkedInquiries.documents.uploader:id,name,profile_image_path';
                $relations[] = 'linkedInquiries.documents.task:id,title';
            }

            $job->load($relations);

            // Backward-compatible recovery for a legacy source pointer that was not
            // yet copied into flow_job_inquiries. Normal deployments are backfilled
            // by the migration, so this path is rarely used.
            if ($job->linkedInquiries->isEmpty() && $job->source_inquiry_id) {
                $fallback = [
                    'sourceInquiry.client:id,name,logo_path',
                    'sourceInquiry.owner:id,name,profile_image_path',
                ];
                if ($canViewDocuments) {
                    $fallback[] = 'sourceInquiry.documents:id,inquiry_id,inquiry_task_id,uploaded_by,name,mime_type,size,created_at';
                    $fallback[] = 'sourceInquiry.documents.uploader:id,name,profile_image_path';
                    $fallback[] = 'sourceInquiry.documents.task:id,title';
                }
                $job->load($fallback);
                if ($job->sourceInquiry) {
                    $job->linkedInquiries->push($job->sourceInquiry);
                }
            }

            return $job;
        }

        if ($tab === 'redo') {
            // Redo relationship/financial data is loaded by OrderRedoService.
            // Keep the base Order shell lightweight and leave the original
            // workflow, invoices and payments untouched.
            return $job;
        }

        if ($tab === 'finance') {
            abort_unless(app(AccessControlService::class)->can($user, 'finance', 'view'), 403);
            $job->load([
                'client.contacts:id,client_id,name,email,is_primary,sort_order',
                'items:id,flow_job_id,product_name,category_name,quantity,unit_price,is_removed,sort_order',
                'invoices.items',
                'invoices.payments',
                'invoices.creator:id,name,profile_image_path',
                'payments.invoice:id,invoice_number',
                'payments.recorder:id,name,profile_image_path',
                'collection.owner:id,name,profile_image_path',
                'collection.updates.actor:id,name,profile_image_path',
            ]);

            app(\App\Services\OrderFinanceService::class)->syncStatuses($job->invoices);
            $job->load(['invoices.items', 'invoices.payments']);

            return $job;
        }

        $job->load([
            'workflow.phases.taskPack.items.documentCategory',
            'phase.taskPack.items.documentCategory',
            'tasks' => fn ($query) => app(AccessControlService::class)
                ->applyTaskScope($query, $user)
                ->with([
                    'assignee:id,name,profile_image_path',
                    'phase:id,name,short_name,sequence,color',
                    'template',
                    'documentCategory',
                    'setupTemplate.documentCategory',
                ]),
            'documents.uploader:id,name,profile_image_path',
            'documents.task:id,title',
        ]);

        $this->hydratePublishedOrderWorkflow($job);
        $this->hydrateLoadedTaskLinks($job);
        return $job;
    }


    /**
     * Fast first-paint read model for the always-visible Order Overview shell.
     *
     * Unlike loadVisibleOverviewSummary(), this method deliberately avoids
     * published-workflow reconciliation checks and Task Pack document metadata.
     * The isolated Workflow component performs that maintenance before exposing
     * task actions. The shell needs only stage labels and current-stage tasks.
     */
    public function loadVisibleOverviewShell(FlowJob $job, User $user): FlowJob
    {
        $job->load(['workflow:id,name']);

        if ($job->workflow) {
            // The summary cards use OrderDetailPresenter::currentTasks(), which
            // validates generated tasks against the current phase Task Pack.
            // Keep this shell lightweight, but hydrate the authoritative phase
            // ids + task_pack_id so configured tasks are not mistaken for stale
            // rows and filtered out of "Next required action".
            $phaseQuery = WorkflowPhase::query()
                ->select([
                    'id', 'workflow_id', 'workflow_template_id', 'task_pack_id',
                    'sequence', 'name', 'short_name', 'is_active', 'color',
                ])
                ->where('is_active', true);

            $published = ! $job->completed_at
                && ! in_array((string) $job->status, self::INACTIVE_STATUSES, true)
                && OrderWorkflowSetupService::orderWorkflowQuery()
                    ->whereKey((int) $job->workflow_id)
                    ->where('is_active', true)
                    ->exists();

            $phases = $published
                ? $phaseQuery->where('workflow_template_id', (int) $job->workflow_id)->orderBy('sequence')->get()
                : $phaseQuery->where('workflow_id', (int) $job->workflow_id)->orderBy('sequence')->get();

            $job->workflow->setRelation('phases', $phases->values());

            $currentPhase = $phases->firstWhere('id', (int) $job->workflow_phase_id);
            if ($currentPhase) {
                $job->setRelation('phase', $currentPhase);
                if ($currentPhase->task_pack_id) {
                    // Only the current pack's ids are required by the summary.
                    // Do not hydrate every phase/task-pack graph on first paint.
                    $currentPhase->load(['taskPack.items:id,task_pack_id']);
                }
            }
        }

        $job->load([
            'tasks' => fn ($query) => app(AccessControlService::class)
                ->applyTaskScope($query, $user)
                ->where('workflow_phase_id', (int) $job->workflow_phase_id)
                ->with([
                    'assignee:id,name,profile_image_path',
                    'setupTemplate',
                    'template',
                    'documents:id,task_id',
                    'links:id,task_id,url,created_at',
                ]),
        ]);

        return $job;
    }

    /**
     * Hydrate only the small Order Overview summary graph.
     *
     * This is intentionally much smaller than loadVisibleDetailTab('overview'):
     * the header/summary needs the published stage list plus tasks from the
     * current stage, but it does not need products, every workflow task,
     * attachments or activity yet.
     */
    public function loadVisibleOverviewSummary(FlowJob $job, User $user): FlowJob
    {
        $job->load(['workflow:id,name']);

        if ($job->workflow) {
            $workflowId = (int) ($job->workflow_id ?: 0);
            $binding = app(OrderWorkflowBindingService::class);

            // loadWorkflowSection() validates the published workflow immediately
            // before auto-advance. Reuse that request-scoped graph when present
            // instead of issuing a second active-workflow EXISTS query plus a
            // second phase/task-pack read. Other callers retain the previous
            // lightweight fallback and therefore do not become more expensive.
            $cachedPublishedPhases = $binding->cachedPublishedPhasesForRead($workflowId);
            $published = $cachedPublishedPhases !== null
                || (! $job->completed_at
                    && ! in_array((string) $job->status, self::INACTIVE_STATUSES, true)
                    && app(OrderWorkflowSetupService::class)->isActiveOrderWorkflow($workflowId));

            if ($cachedPublishedPhases !== null) {
                $phases = $cachedPublishedPhases;
            } else {
                $phaseQuery = WorkflowPhase::query()
                    ->select([
                        'id', 'workflow_id', 'workflow_template_id', 'task_pack_id',
                        'sequence', 'name', 'short_name', 'is_active', 'color',
                    ])
                    ->where('is_active', true);

                $phases = $published
                    ? $phaseQuery->where('workflow_template_id', $workflowId)->orderBy('sequence')->get()
                    : $phaseQuery->where('workflow_id', $workflowId)->orderBy('sequence')->get();
            }

            $job->workflow->setRelation('phases', $phases->values());

            $currentPhase = $phases->firstWhere('id', (int) $job->workflow_phase_id);
            if ($currentPhase && $currentPhase->task_pack_id) {
                $currentPhase->loadMissing(['taskPack.items:id,task_pack_id']);
            }
        }

        $job->load([
            'tasks' => fn ($query) => app(AccessControlService::class)
                ->applyTaskScope($query, $user)
                ->where('workflow_phase_id', (int) $job->workflow_phase_id)
                ->with([
                    'assignee:id,name,profile_image_path',
                    'phase:id,name,short_name,sequence,color',
                    'template',
                    'documentCategory',
                    'setupTemplate.documentCategory',
                ]),
        ]);

        return $job;
    }

    /** Load Product & Quantity data only when that Overview section is reached. */
    public function loadVisibleOverviewProducts(FlowJob $job, User $user): FlowJob
    {
        if (! app(AccessControlService::class)->can($user, 'catalog_products', 'view')) {
            $job->setRelation('items', collect());
            return $job;
        }

        $job->load([
            'items.updatedBy:id,name,profile_image_path',
            'items.removedBy:id,name,profile_image_path',
            'items.supplier:id,name,code,metadata,type,status',
            'items.catalogProduct:id,name,code,parent_id,type,status,metadata',
            'items.catalogProduct.parent:id,name,code,type,status,metadata',
        ]);

        $this->hydrateLegacyOrderItemCatalogProducts($job);

        return $job;
    }

    /**
     * Backward-compatible Product Master hydration for historical Order items.
     *
     * Older bulk imports saved product_name/category_name without catalog_product_id.
     * Resolve those rows by exact Product Master name in one bounded query so the
     * Order Details product card can use the same taxonomy hierarchy as Inquiry
     * Details without mutating historical data during a read.
     */
    private function hydrateLegacyOrderItemCatalogProducts(FlowJob $job): void
    {
        if (! $job->relationLoaded('items')) {
            return;
        }

        $missingCatalogItems = $job->items
            ->filter(fn (FlowJobItem $item): bool => ! $item->catalogProduct && filled($item->product_name));

        if ($missingCatalogItems->isEmpty()) {
            return;
        }

        $workspaceId = max(1, (int) config('flowtrack.workspace_id', 1));
        $productNames = $missingCatalogItems
            ->pluck('product_name')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();

        $catalogByName = MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->whereIn('name', $productNames)
            ->with('parent')
            ->get()
            ->keyBy(fn (MasterRecord $product) => mb_strtolower(trim((string) $product->name)));

        $missingCatalogItems->each(function (FlowJobItem $item) use ($catalogByName): void {
            $matched = $catalogByName->get(mb_strtolower(trim((string) $item->product_name)));
            if ($matched) {
                $item->setRelation('catalogProduct', $matched);
            }
        });
    }

    /** Load the full interactive workflow/task graph only near the taskflow. */
    public function loadVisibleOverviewWorkflow(FlowJob $job, User $user): FlowJob
    {
        $isPublishedOrderWorkflow = ! $job->completed_at
            && ! in_array((string) $job->status, self::INACTIVE_STATUSES, true)
            && app(OrderWorkflowSetupService::class)->isActiveOrderWorkflow((int) $job->workflow_id);

        // Active Orders always replace legacy Workflow::phases with the exact
        // published WorkflowTemplate phases below. Loading the legacy phase/task
        // pack graph first only to overwrite it caused a second copy of the same
        // definition queries on every Workflow render. Historical Orders keep
        // the original relation path unchanged.
        $workflowRelation = $isPublishedOrderWorkflow
            ? 'workflow:id,name'
            : 'workflow.phases.taskPack.items.documentCategory';

        $job->load([
            $workflowRelation,
            'shippingSourceAddress:id,client_id,label,recipient,address_line1,suite,city,state,zip,country,is_default,sort_order',
            'shipments.shippingMethod:id,type,name,code,sort_order,status',
            'shipments.shipmentUrgency:id,type,name,code,sort_order,status',
            'shipments.courier:id,type,name,code,sort_order,status',
            'latestShipmentInformationActivity:activities.id,activities.subject_type,activities.subject_id,activities.event,activities.meta,activities.created_at',
            'latestCourierLabelActivity:activities.id,activities.subject_type,activities.subject_id,activities.event,activities.meta,activities.created_at',
            'workflowEmailActivities:activities.id,activities.subject_type,activities.subject_id,activities.user_id,activities.event,activities.description,activities.meta,activities.created_at',
            'workflowInvoiceActivities:activities.id,activities.subject_type,activities.subject_id,activities.user_id,activities.event,activities.description,activities.meta,activities.created_at',
            'latestProductionMonitorActivity:activities.id,activities.subject_type,activities.subject_id,activities.user_id,activities.event,activities.description,activities.meta,activities.created_at',
            'invoices.creator:id,name',
            'tasks' => fn ($query) => app(AccessControlService::class)
                ->applyTaskScope($query, $user)
                ->with([
                    'assignee:id,name,profile_image_path',
                    'phase:id,name,short_name,sequence,color',
                    'template',
                    'documentCategory',
                    'setupTemplate.documentCategory',
                ]),
            // Task rows expose their evidence inline, so document resources are
            // part of the workflow section rather than the first page render.
            'documents.uploader:id,name,profile_image_path',
            'documents.task:id,title',
        ]);

        // The workflow render already hydrates shipments. Checking the loaded
        // collection avoids an extra shipments EXISTS query on every normal
        // Order. Only legacy Orders with no shipment pay for the repair/reload.
        if ($job->shipments->isEmpty()) {
            app(OrderShipmentService::class)->seedPrimaryShipment($job, $user);
            $job->load([
                'shipments.shippingMethod:id,type,name,code,sort_order,status',
                'shipments.shipmentUrgency:id,type,name,code,sort_order,status',
                'shipments.courier:id,type,name,code,sort_order,status',
            ]);
        }

        $this->hydratePublishedOrderWorkflow($job);
        $this->hydrateLoadedTaskLinks($job);
        $this->hydrateArtworkRevisionNotes($job);

        return $job;
    }

    /** Load general Order attachments only if the workflow has not already done so. */
    public function loadVisibleOverviewDocuments(FlowJob $job): FlowJob
    {
        $job->loadMissing([
            'documents.uploader:id,name,profile_image_path',
            'documents.task:id,title',
        ]);

        return $job;
    }

    /**
     * Replace any legacy Workflow::phases rows with the exact seven phases
     * published by the selected Order workflow for active Orders.
     *
     * Upgraded databases can still contain old five-stage rows sharing the
     * compatibility workflow_id. The runtime binding already moves the Order
     * and tasks; this final read-model normalization guarantees Order Details
     * never renders those stale rows again. Completed/cancelled Orders keep
     * their historical workflow presentation.
     */
    private function hydratePublishedOrderWorkflow(FlowJob $job): void
    {
        if ($job->completed_at || in_array((string) $job->status, self::INACTIVE_STATUSES, true)) return;
        if (! $job->relationLoaded('workflow') || ! $job->workflow) return;

        $workflowId = (int) ($job->workflow_id ?: 0);
        if ($workflowId <= 0) return;
        if (! app(OrderWorkflowSetupService::class)->isActiveOrderWorkflow($workflowId)) return;

        // syncSingleActiveOrder() loads this same published graph before the
        // initial Workflow render. OrderWorkflowBindingService is request-scoped,
        // so that graph is reused only inside this request; later Livewire
        // requests perform a fresh read and therefore see setup changes.
        $phases = app(OrderWorkflowBindingService::class)->publishedPhasesForRead($workflowId);

        if ($phases->isEmpty()) return;

        $job->workflow->setRelation('phases', $phases);
        $current = $phases->firstWhere('id', (int) $job->workflow_phase_id);
        if ($current) $job->setRelation('phase', $current);
    }

    /**
     * Attach external Order task links to the already-authorized task collection
     * with one deterministic query. This avoids relying on nested relation state
     * during Livewire DOM refreshes, so a just-saved link remains visible after
     * the inline Add link form closes and after realtime workspace refreshes.
     */
    private function hydrateLoadedTaskLinks(FlowJob $job): void
    {
        if (! $job->relationLoaded('tasks') || $job->tasks->isEmpty()) {
            return;
        }

        $taskIds = $job->tasks
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values();

        if ($taskIds->isEmpty()) {
            return;
        }

        $visibleTaskLinks = TaskLink::query()
            ->whereIn('task_id', $taskIds->all())
            ->with('creator:id,name')
            ->orderByDesc('id')
            ->get(['id', 'task_id', 'created_by', 'url', 'created_at', 'updated_at']);

        $linksByTask = $visibleTaskLinks
            ->groupBy(fn (TaskLink $link) => (int) $link->task_id);

        foreach ($job->tasks as $task) {
            $task->setRelation(
                'links',
                collect($linksByTask->get((int) $task->id, collect()))->values(),
            );
        }
    }

    /**
     * Attach artwork revision notes to the exact upload task that is reopened
     * by the revision loop. Keeping this separate from the paginated Order
     * activity feed makes the issue visible beside the task even when the
     * activity panel is filtered or showing a different page.
     */
    private function hydrateArtworkRevisionNotes(FlowJob $job): void
    {
        if (! $job->relationLoaded('tasks') || $job->tasks->isEmpty()) {
            return;
        }

        $tasksById = $job->tasks->keyBy(fn (Task $task) => (int) $task->id);
        $artworkUploadTasks = $job->tasks
            ->filter(fn (Task $task) => app(OrderWorkflowActionService::class)->automationKey($task) === 'ART_PREPARE_UPLOAD')
            ->values();

        $notes = $job->activities()
            ->where('event', 'job.artwork_revision_requested')
            ->with('user:id,name,profile_image_path')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $job->setRelation('artworkRevisionRequestActivities', $notes);

        $documents = $job->relationLoaded('documents') ? $job->documents : collect();
        $documentsById = $documents->keyBy(fn ($document) => (int) $document->id);
        $documentsByTask = $documents
            ->filter(fn ($document) => (int) ($document->task_id ?? 0) > 0)
            ->groupBy(fn ($document) => (int) $document->task_id);

        // Load applied revisions and individual cancellations in one query.
        // Both event types rebuild the current Artwork set, and preloading them
        // once keeps every Order-detail surface query-free during presentation.
        $artworkStateActivities = $job->activities()
            ->whereIn('event', ['job.artwork_revision_applied', 'job.artwork_cancelled'])
            ->latest('id')
            ->get(['id', 'user_id', 'event', 'description', 'meta', 'created_at']);
        $appliedRevisionActivities = $artworkStateActivities
            ->where('event', 'job.artwork_revision_applied')
            ->values();
        $artworkCancellationActivities = $artworkStateActivities
            ->where('event', 'job.artwork_cancelled')
            ->values();

        // Reuse the same preloaded histories in current-version reconstruction
        // and Archived Artwork presentation.
        $job->setRelation('artworkRevisionAppliedActivities', $appliedRevisionActivities);
        $job->setRelation('artworkCancellationActivities', $artworkCancellationActivities);

        $appliedRevisionActivityIds = $appliedRevisionActivities
            ->map(fn ($activity) => (int) data_get($activity->meta, 'revision_activity_id', 0))
            ->filter()
            ->unique()
            ->values();

        // The current Artwork set may contain mixed file versions after a
        // selective revision. Attach it directly to the upload task so every
        // Order-detail surface renders accepted files at their existing version
        // and only replaced files at their incremented version. Reuse the
        // already-loaded parent Job + applied activities so this loop is query-free.
        foreach ($artworkUploadTasks as $artworkUploadTask) {
            $taskDocuments = collect($documentsByTask->get((int) $artworkUploadTask->id, collect()))->values();
            $artworkUploadTask->setRelation('job', $job);
            $artworkUploadTask->setRelation(
                'currentArtworkDocuments',
                app(DocumentService::class)->currentArtworkDocuments(
                    $artworkUploadTask,
                    $taskDocuments,
                    $appliedRevisionActivities,
                    $artworkCancellationActivities,
                ),
            );
        }

        $notesByTask = [];
        foreach ($notes as $note) {
            $targetTaskId = (int) data_get($note->meta, 'target_task_id', 0);

            // Compatibility for revision events created before task metadata
            // was stored. Existing Orders generally have one artwork-upload
            // task, so older comments can still be shown in the right place.
            if ($targetTaskId <= 0) {
                $phaseId = (int) data_get($note->meta, 'workflow_phase_id', 0);
                $legacyTarget = $phaseId > 0
                    ? $artworkUploadTasks->firstWhere('workflow_phase_id', $phaseId)
                    : ($artworkUploadTasks->count() === 1 ? $artworkUploadTasks->first() : null);
                $targetTaskId = (int) ($legacyTarget?->id ?? 0);
            }

            if ($targetTaskId <= 0 || ! $tasksById->has($targetTaskId)) {
                continue;
            }

            // New revision events store the exact file id. For older events,
            // resolve the newest artwork document that existed at the moment
            // the revision was requested. No additional query is needed: the
            // Overview tab already hydrates the Order's documents.
            $referenceDocumentId = (int) data_get($note->meta, 'reference_document_id', 0);
            $referenceDocument = $referenceDocumentId > 0
                ? $documentsById->get($referenceDocumentId)
                : null;

            $taskDocuments = collect($documentsByTask->get($targetTaskId, collect()));

            if (! $referenceDocument) {
                $referenceDocument = $taskDocuments
                    ->filter(fn ($document) => ! $document->created_at || ! $note->created_at || $document->created_at->lte($note->created_at))
                    ->sortByDesc(fn ($document) => (int) $document->id)
                    ->first();
            }

            // The red "Revision required" panel is an outstanding-action
            // panel, not permanent document history. As soon as a newer
            // artwork file is uploaded after this request, the revision has
            // been answered and the panel must disappear from the task row.
            // The activity itself stays in Order history for audit purposes.
            $sourceArtworkVersion = max(0, (int) data_get($note->meta, 'source_artwork_version', 0));
            $hasReplacementArtwork = $appliedRevisionActivityIds->contains((int) $note->id)
                || ($sourceArtworkVersion > 0
                    ? (int) ($taskDocuments->max('version') ?? 0) > $sourceArtworkVersion
                    : ($referenceDocument
                        ? $taskDocuments->contains(fn ($document) => (int) $document->id > (int) $referenceDocument->id)
                        : $taskDocuments->contains(fn ($document) => $document->created_at && $note->created_at && $document->created_at->gt($note->created_at))));

            if ($hasReplacementArtwork) {
                continue;
            }

            $revisionDocumentIds = collect(data_get($note->meta, 'revision_document_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values();
            $revisionDocuments = $revisionDocumentIds
                ->map(fn ($id) => $documentsById->get($id))
                ->filter()
                ->values();
            $selectionPending = (bool) data_get($note->meta, 'revision_selection_pending', false);
            if ($revisionDocuments->isEmpty() && $referenceDocument && ! $selectionPending) {
                $revisionDocuments = collect([$referenceDocument]);
            }

            $revisionAttachments = collect(data_get($note->meta, 'revision_attachment_document_ids', []))
                ->map(fn ($id) => $documentsById->get((int) $id))
                ->filter()
                ->values();

            $note->setRelation('referenceDocument', $referenceDocument);
            $note->setRelation('revisionDocuments', $revisionDocuments);
            $note->setRelation('revisionAttachments', $revisionAttachments);

            $notesByTask[$targetTaskId] ??= collect();
            $notesByTask[$targetTaskId]->push($note);
        }

        foreach ($job->tasks as $task) {
            $task->setRelation(
                'artworkRevisionNotes',
                collect($notesByTask[(int) $task->id] ?? collect())->values(),
            );
        }
    }

    /**
     * Load only the visible page of Overview activity. The previous Order
     * detail query hydrated the complete activity history for every open.
     */
    public function loadVisibleOverviewActivity(
        FlowJob $job,
        string $activityTab = 'all',
        int $page = 1,
        int $perPage = 10,
    ): FlowJob {
        $activityTab = in_array($activityTab, ['all', 'comments', 'history'], true)
            ? $activityTab
            : 'all';
        $perPage = max(1, min($perPage, 50));

        $customerCommentEvents = [
            'job.artwork_emailed_to_order_team',
            'job.workflow_email_skipped',
        ];

        $query = $job->activities()
            ->where(fn ($events) => $events->whereNull('event')->orWhere('event', '!=', 'job.health_updated'))
            ->with('user:id,name,profile_image_path')
            ->when($activityTab === 'comments', fn ($activity) => $activity->where(function ($comments) use ($customerCommentEvents) {
                $comments->where('event', 'job.comment')
                    ->orWhere(function ($handoff) use ($customerCommentEvents) {
                        $handoff->whereIn('event', $customerCommentEvents)
                            ->whereNotNull('meta->customer_comment')
                            ->where('meta->customer_comment', '!=', '');
                    });
            }))
            ->when($activityTab === 'history', fn ($activity) => $activity
                ->where(fn ($events) => $events->whereNull('event')->orWhere('event', '!=', 'job.comment'))
                ->where(function ($events) use ($customerCommentEvents) {
                    $events->whereNull('event')
                        ->orWhereNotIn('event', $customerCommentEvents)
                        ->orWhereNull('meta->customer_comment')
                        ->orWhere('meta->customer_comment', '');
                }));

        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        $job->setRelation('activities', $rows);
        $job->setAttribute('activity_total_count', $total);
        $job->setAttribute('activity_current_page', $page);
        $job->setAttribute('activity_per_page', $perPage);

        return $job;
    }

    public function findVisible(User $user, int $id): FlowJob
    {
        $job = $this->visibleQuery($user)->with([
            'client','orderFlag',
            'workflow.phases.taskPack.items.defaultAssignee',
            'workflow.phases.taskPack.items.defaultDepartment',
            'workflow.phases.taskPack.items.priority',
            'workflow.phases.taskPack.items.documentCategory',
            'phase.taskPack.items.documentCategory',
            'phase.documentCategory',
            'startedFromPhase','owner','coordinator','items','members.user',
            'phaseHistories.phase','phaseHistories.actor',
            'tasks' => fn ($q) => app(AccessControlService::class)->applyTaskScope($q, $user)->with(['assignee','phase','orderTaskStatus','orderTaskFlag','template','documentCategory','setupTemplate.documentCategory','checklistItems','comments.user','documents','links.creator']),
            'documents.uploader','activities.user',
        ])->findOrFail($id);

        // Runtime workflow operations must use the exact same published Order
        // phase set that Order Details renders. Upgraded databases can still
        // contain historical phases sharing the compatibility workflow_id. If
        // those rows leak into completePhase()/nextPhase(), a completed stage
        // can appear to stay active or jump to an obsolete phase.
        $this->hydratePublishedOrderWorkflow($job);

        return $job;
    }

    public function create(array $data, User $actor): FlowJob
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'jobs', 'create'), 403);
        $canAssign = app(AccessControlService::class)->can($actor, 'jobs', 'assign');
        if (!$canAssign) {
            abort_unless((int) ($data['owner_id'] ?? $actor->id) === (int) $actor->id, 403);
            abort_unless((int) ($data['coordinator_id'] ?? $actor->id) === (int) $actor->id, 403);
        }

        return DB::transaction(function () use ($data, $actor) {
            $clientId = filled($data['client_id'] ?? null) ? (int) $data['client_id'] : null;
            $workflowTemplate = WorkflowTemplate::query()
                ->where('workspace_id', app(SetupContext::class)->workspaceId())
                ->where('is_active', true)
                ->where('applies_to', 'orders')
                ->availableFor('orders', $clientId)
                ->whereKey((int) $data['workflow_id'])
                ->with([
                    'phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence'),
                    'phases.taskPack.items.defaultAssignee',
                    'phases.taskPack.items.defaultDepartment',
                    'phases.taskPack.items.priority',
                    'phases.taskPack.items.documentCategory',
                ])
                ->first();
            abort_unless($workflowTemplate, 422, 'Selected Workflow is not available for this client.');
            abort_unless(
                app(OrderWorkflowSetupService::class)->isReadyForOrderCreation((int) $workflowTemplate->id),
                422,
                'The selected Order workflow is incomplete. Configure all seven stages in Workflow Setup and their compatible Task Packs in Task Pack Setup.'
            );

            app(OrderWorkflowSetupService::class)->ensureRuntimeMirror((int) $workflowTemplate->id);
            $workflow = Workflow::query()
                ->where('is_snapshot', false)
                ->where('is_active', true)
                ->findOrFail($workflowTemplate->id);
            // Use workflow_template_id as the authoritative published phase
            // source. Legacy Workflow::phases can contain historical rows from
            // an old five-stage implementation on upgraded databases.
            $workflow->setRelation('phases', $workflowTemplate->phases->values());
            $phase = $workflowTemplate->phases->firstWhere('id', (int) $data['workflow_phase_id']);
            abort_unless($phase && $phase->is_active && $phase->allow_job_start, 422, 'Selected starting phase is not allowed.');

            $next = (int) FlowJob::withTrashed()->max('id') + 126;
            $draft = (bool) ($data['draft'] ?? false);
            $job = FlowJob::create([
                'job_number' => 'ORDER-'.app(WorkspaceSettingsService::class)->localNow()->format('Y').'-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
                'order_number' => blank($data['order_number'] ?? null) ? null : trim((string) $data['order_number']),
                'is_repeat_order' => (bool) ($data['is_repeat_order'] ?? false),
                'repeat_order_number' => (bool) ($data['is_repeat_order'] ?? false) && filled($data['repeat_order_number'] ?? null)
                    ? trim((string) $data['repeat_order_number'])
                    : null,
                'client_id' => $clientId,
                'workflow_id' => $workflow->id,
                'source_workflow_id' => $workflow->id,
                'workflow_phase_id' => $phase->id,
                'source_workflow_phase_id' => $phase->id,
                'started_from_phase_id' => $phase->id,
                'owner_id' => $data['owner_id'] ?: $actor->id,
                'coordinator_id' => $data['coordinator_id'] ?: $actor->id,
                'created_by' => $actor->id,
                'title' => $data['title'],
                'product' => $data['product'],
                'category' => $data['category'] ?? null,
                'quantity' => $data['quantity'] ?? 0,
                'delivery_date' => $data['delivery_date'] ?? null,
                'estimated_delivery_date' => $data['estimated_delivery_date'] ?? null,
                'received_date' => $data['received_date'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse' => blank($data['warehouse'] ?? null) ? null : trim((string) $data['warehouse']),
                'supplier_instruction' => blank($data['supplier_instruction'] ?? null) ? null : trim((string) $data['supplier_instruction']),
                'source_row_id' => blank($data['source_row_id'] ?? null) ? null : trim((string) $data['source_row_id']),
                'import_profile' => blank($data['import_profile'] ?? null) ? null : trim((string) $data['import_profile']),
                'bulk_import_id' => blank($data['bulk_import_id'] ?? null) ? null : trim((string) $data['bulk_import_id']),
                'priority' => $data['priority'] ?? 'Medium',
                'production_urgency_ids' => array_values(array_map('intval', (array) ($data['production_urgency_ids'] ?? []))),
                'shipment_method_ids' => array_values(array_map('intval', (array) ($data['shipment_method_ids'] ?? []))),
                'shipment_urgency_ids' => array_values(array_map('intval', (array) ($data['shipment_urgency_ids'] ?? []))),
                'allow_multiple_shipments' => (bool) ($data['allow_multiple_shipments'] ?? false),
                'shipment_address_mode' => ($data['shipment_address_mode'] ?? 'same_address') === 'multiple_address'
                    ? 'multiple_address'
                    : 'same_address',
                'description' => app(RichTextService::class)->normalize($data['description'] ?? null, 10000, 'description'),
                'shipping_address' => blank($data['shipping_address'] ?? null) ? null : trim((string) $data['shipping_address']),
                'shipping_contact_type' => blank($data['shipping_contact_type'] ?? null) ? null : trim((string) $data['shipping_contact_type']),
                'shipping_contact_name' => blank($data['shipping_contact_name'] ?? null) ? null : trim((string) $data['shipping_contact_name']),
                'shipping_phone_country_code' => blank($data['shipping_phone_country_code'] ?? null) ? null : trim((string) $data['shipping_phone_country_code']),
                'shipping_phone' => blank($data['shipping_phone'] ?? null) ? null : trim((string) $data['shipping_phone']),
                'shipping_postal_code' => blank($data['shipping_postal_code'] ?? null) ? null : trim((string) $data['shipping_postal_code']),
                'shipping_source_address_id' => filled($data['shipping_source_address_id'] ?? null) ? (int) $data['shipping_source_address_id'] : null,
                'notes' => blank($data['notes'] ?? null) ? null : trim((string) $data['notes']),
                'status' => $draft ? 'Draft' : 'New',
                'health' => 'On Track',
                'progress' => 0,
                'next_action' => $draft ? 'Complete draft and activate workflow' : ($phase->entry_condition ?: $phase->entry_rule),
                'start_handling' => $data['start_handling'] ?? 'Normal start',
                'start_reason' => $data['start_reason'] ?? null,
            ]);

            // Persist the Create Order shipment plan as one aggregate. Older
            // browser snapshots and non-UI callers still use the legacy single
            // shipment seed path, so both paths intentionally remain supported.
            $initialShipments = (array) ($data['initial_shipments'] ?? []);
            if ($initialShipments !== []) {
                app(OrderShipmentService::class)->createInitialShipments($job, $actor, $initialShipments);
            } else {
                app(OrderShipmentService::class)->seedPrimaryShipment($job, $actor);
            }

            $items = collect($data['items'] ?? [])->filter(fn ($item) => filled($item['product'] ?? null))->values();
            if ($items->isEmpty() && filled($job->product)) {
                $items = collect([['product' => $job->product, 'category' => $job->category, 'quantity' => $job->quantity]]);
            }
            foreach ($items as $sort => $item) {
                FlowJobItem::create([
                    'flow_job_id' => $job->id,
                    'catalog_product_id' => filled($item['product_id'] ?? null) ? (int) $item['product_id'] : null,
                    'supplier_id' => filled($item['supplier_id'] ?? null) ? (int) $item['supplier_id'] : null,
                    'product_name' => $item['product'] ?? null,
                    'category_name' => $item['category'] ?? null,
                    'quantity' => (int) ($item['quantity'] ?? 1),
                    'unit_price' => round(max(0, (float) ($item['unit_price'] ?? 0)), 2),
                    'supplier_unit_price' => isset($item['supplier_unit_price']) ? round(max(0, (float) $item['supplier_unit_price']), 2) : null,
                    'remote_area_surcharge' => filled($item['remote_area_surcharge'] ?? null) ? round(max(0, (float) $item['remote_area_surcharge']), 2) : null,
                    'notes' => blank($item['notes'] ?? null) ? null : trim((string) $item['notes']),
                    'updated_by' => $actor->id,
                    'sort_order' => $sort,
                ]);
            }

            $this->ensureMembers($job);

            // Order workflows configured in the shared Workflow Setup remain
            // live operational definitions. Inquiry/legacy workflows keep the
            // historical snapshot behavior.
            $usesLiveOrderWorkflow = OrderWorkflowSetupService::orderWorkflowQuery()
                ->whereKey((int) $workflow->id)
                ->exists();

            if ($usesLiveOrderWorkflow) {
                $job->load(['workflow', 'phase']);
                $job->workflow->setRelation('phases', $workflowTemplate->phases->values());
                $job->setRelation('phase', $phase);
                $operationalPhase = $phase;
            } else {
                $job = app(JobWorkflowSnapshotService::class)->snapshot($job, $workflow->id);
                $operationalPhase = $job->phase()->firstOrFail();
            }

            FlowJobPhaseHistory::create([
                'flow_job_id' => $job->id,
                'workflow_phase_id' => $operationalPhase->id,
                'changed_by' => $actor->id,
                'phase_owner_id' => $job->coordinator_id,
                'target_date' => $job->delivery_date,
                'health_override' => $job->health,
                'status' => $draft ? 'planned' : 'active',
                'entered_at' => $draft ? null : now(),
            ]);

            // Create every Task from every snapshotted phase immediately,
            // including future phases. Draft Jobs keep those Tasks in a
            // Not Started state until the Job is activated.
            $this->syncWorkflowTasks($job, $draft ? null : $actor, true);
            if (!$draft) {
                $this->recalculateProgress($job);
            }

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => $draft ? 'job.draft_saved' : 'job.created',
                'description' => $draft ? 'Job saved as draft' : 'Job created at '.$operationalPhase->name,
            ]);

            $createInquiryIds = collect($data['source_inquiry_ids'] ?? [])
                ->prepend($data['source_inquiry_id'] ?? null)
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values();

            if ($createInquiryIds->isNotEmpty()) {
                $access = app(AccessControlService::class);
                abort_unless($access->can($actor, 'jobs', 'link') && $access->can($actor, 'inquiries', 'view'), 403);

                // Lock in deterministic primary-key order to reduce deadlock risk
                // when two users create Orders with overlapping Inquiry choices.
                // We still attach in the user's original selection order so the
                // first selection remains the legacy primary/source Inquiry.
                $lockedInquiries = app(InquiryService::class)
                    ->visibleQuery($actor)
                    ->whereIn('inquiries.id', $createInquiryIds->all())
                    ->orderBy('inquiries.id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                abort_unless(
                    $lockedInquiries->count() === $createInquiryIds->count(),
                    422,
                    'One or more selected Inquiries are no longer available.'
                );

                foreach ($createInquiryIds as $createInquiryId) {
                    $inquiry = $lockedInquiries->get($createInquiryId);
                    abort_unless($inquiry, 422, 'One or more selected Inquiries are no longer available.');

                    // All links are created inside this transaction. If any
                    // Inquiry becomes unavailable, every preceding attachment
                    // and the newly-created Order roll back together.
                    $job = $this->attachInquiryToOrder($job, $inquiry, $actor);
                }
            }

            $mentionIds = app(MentionService::class)->userIdsFromText((string) $job->description);
            if (!$draft || $mentionIds) {
                $jobId = $job->id;
                DB::afterCommit(function () use ($jobId, $actor, $draft, $mentionIds) {
                    $fresh = FlowJob::with(['client','phase','members','tasks'])->find($jobId);
                    if (!$fresh) return;

                    if (!$draft) app(NotificationService::class)->notifyJobAssigned($fresh, $actor, [], $mentionIds);

                    if ($mentionIds) {
                        app(NotificationService::class)->notifyMentionedUsers(
                            $mentionIds,
                            $actor->name.' mentioned you in '.$fresh->job_number,
                            (string) $fresh->description,
                            $fresh,
                            null,
                            $actor,
                        );
                    }
                });
            }

            // Record supplier-specific order projections atomically with new Orders.
            app(\App\Services\SupplierBridge\CaptureCreatedOrder::class)->capture($job);

            return $job->refresh();
        });
    }

    public function setOrderAttentionReason(FlowJob $job, string $reason, User $actor): FlowJob
    {
        abort_unless($this->visibleQuery($actor)->whereKey($job->id)->exists(), 403);
        abort_if($job->completed_at || in_array((string) $job->status, self::INACTIVE_STATUSES, true), 422, 'A completed or inactive Order cannot be flagged for attention.');

        $reason = trim(strip_tags($reason));
        if ($reason === '') {
            throw ValidationException::withMessages(['orderAttentionReason' => 'Write why this Order needs attention.']);
        }
        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['orderAttentionReason' => 'The attention reason may not be greater than 2000 characters.']);
        }

        $updated = DB::transaction(function () use ($job, $reason, $actor): FlowJob {
            $locked = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->completed_at || in_array((string) $locked->status, self::INACTIVE_STATUSES, true), 422, 'A completed or inactive Order cannot be flagged for attention.');

            $locked->update([
                'attention_requested' => true,
                'attention_reason' => $reason,
                'attention_by' => $actor->id,
                'attention_at' => now(),
            ]);

            $commentBody = 'Attention requested: '.$reason;
            $mentionIds = app(MentionService::class)->userIdsFromText($reason);
            $locked->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.comment',
                'description' => $commentBody,
                'meta' => [
                    'body' => $commentBody,
                    'comment' => true,
                    'attention_reason' => true,
                    'attention_scope' => 'order',
                    'mention_user_ids' => $mentionIds,
                ],
            ]);

            $recipientIds = User::query()
                ->where('is_active', true)
                ->where(function ($query) use ($locked): void {
                    $query->where('is_super_admin', true)
                        ->orWhereHas('roles', fn ($role) => $role->where('is_active', true)->whereIn('slug', ['super-admin', 'admin', 'administrator']))
                        ->orWhereHas('role', fn ($role) => $role->whereIn('slug', ['super-admin', 'admin', 'administrator']));
                    if ($locked->created_by) $query->orWhere('id', (int) $locked->created_by);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn ($id) => $id === (int) $actor->id)
                ->unique()
                ->values()
                ->all();

            app(NotificationService::class)->notifyOrderAttentionUsers(
                $recipientIds,
                'Attention requested: '.$locked->displayOrderNumber(),
                $commentBody,
                $locked,
                $actor,
            );

            if ($mentionIds !== []) {
                app(NotificationService::class)->notifyMentionedUsers(
                    $mentionIds,
                    $actor->name.' mentioned you in '.$locked->displayOrderNumber(),
                    $commentBody,
                    $locked,
                    null,
                    $actor,
                );
            }

            return $locked->refresh();
        });

        app(DashboardService::class)->forget($actor);
        app(ReportService::class)->forget($actor->id);
        app(ShellDataService::class)->forget($actor->id);

        return $updated;
    }

    public function clearOrderAttention(FlowJob $job, User $actor): FlowJob
    {
        abort_unless($this->visibleQuery($actor)->whereKey($job->id)->exists(), 403);
        abort_if($job->completed_at || in_array((string) $job->status, self::INACTIVE_STATUSES, true), 422, 'A completed or inactive Order cannot be changed.');

        $job->update([
            'attention_requested' => false,
            'attention_reason' => null,
            'attention_by' => null,
            'attention_at' => null,
        ]);
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.attention_cleared',
            'description' => 'Order attention flag cleared.',
        ]);

        app(DashboardService::class)->forget($actor);
        app(ReportService::class)->forget($actor->id);
        app(ShellDataService::class)->forget($actor->id);

        return $job->refresh();
    }

    public function updateDeliveryDate(FlowJob $job, ?string $deliveryDate, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);
        $job->update(['delivery_date' => $deliveryDate ?: null]);
        $job->phaseHistories()->whereNull('completed_at')->update(['target_date' => $deliveryDate ?: null]);
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.delivery_date_updated',
            'description' => $deliveryDate ? 'Delivery date changed to '.$deliveryDate : 'Delivery date cleared',
        ]);
        app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Order delivery date updated', $job->displayOrderNumber().' · '.($deliveryDate ? 'Delivery '.$deliveryDate : 'Delivery date cleared'), 'update', $actor);
        return $job->refresh();
    }

    public function updateUrgencies(FlowJob $job, string $field, array $urgencyIds, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);

        $config = match ($field) {
            'production_urgency_ids' => ['event' => 'job.production_urgency_updated', 'label' => 'Production urgency'],
            'shipment_urgency_ids' => ['event' => 'job.shipment_urgency_updated', 'label' => 'Shipment urgency'],
            default => null,
        };

        abort_unless($config, 422, 'That urgency field cannot be updated.');

        $urgencyIds = collect($urgencyIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        abort_if(count($urgencyIds) > 1, 422, $config['label'].' accepts only one selection.');

        if ($field === 'shipment_urgency_ids') {
            // Shipment urgency is the Express service-level view of the same
            // canonical shipping selection used by Task 5.1. Let the shipment
            // service update both legacy Order fields and the primary row in one
            // transaction so a failed/dispatched change can never leave them split.
            app(OrderShipmentService::class)->syncPrimaryShipmentFromOrderUrgency(
                $job,
                $actor,
                $urgencyIds[0] ?? null,
            );
        } else {
            $job->update([$field => $urgencyIds]);
        }

        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => $config['event'],
            'description' => $config['label'].' updated',
        ]);
        app(NotificationService::class)->notifyJobParticipants(
            $job->refresh(),
            'Order '.$config['label'].' updated',
            $job->displayOrderNumber().' · '.$config['label'].' updated',
            'update',
            $actor,
        );

        return $job->refresh();
    }


    public function updateShippingSelection(FlowJob $job, int $methodId, ?int $urgencyId, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);

        app(OrderShipmentService::class)->syncPrimaryShipmentFromOrderShippingSelection(
            $job,
            $actor,
            $methodId,
            $urgencyId,
        );

        $job = $job->refresh();
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.shipment_method_updated',
            'description' => 'Shipment method / urgency updated',
        ]);
        app(NotificationService::class)->notifyJobParticipants(
            $job,
            'Order shipment method updated',
            $job->displayOrderNumber().' · Shipment method / urgency updated',
            'update',
            $actor,
        );

        return $job;
    }

    public function updateOwner(FlowJob $job, ?int $ownerId, User $actor): FlowJob
    {
        // Order ownership is an administrative assignment. A task assignee may
        // gain visibility/edit access to the Order through their task, but that
        // must never allow them to replace the Order owner.
        abort_unless(app(AccessControlService::class)->isAdministrator($actor), 403);
        $job->update(['owner_id' => $ownerId]);
        $this->ensureMembers($job);
        $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.owner_updated', 'description' => 'Order owner updated']);
        app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Order owner updated', $job->job_number.' · Owner is now '.($job->owner?->name ?? 'Unassigned'), 'assignment', $actor, array_filter([$ownerId]));
        return $job->refresh();
    }

    public function updateCoordinator(FlowJob $job, ?int $coordinatorId, User $actor): FlowJob
    {
        abort_unless(app(AccessControlService::class)->canAssignJob($actor, $job), 403);
        $job->update(['coordinator_id' => $coordinatorId]);
        $this->ensureMembers($job);
        $job->phaseHistories()->whereNull('completed_at')->update(['phase_owner_id' => $coordinatorId]);
        $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.coordinator_updated', 'description' => 'Order coordinator updated']);
        app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Order coordinator updated', $job->job_number.' · Coordinator is now '.($job->coordinator?->name ?? 'Unassigned'), 'assignment', $actor, array_filter([$coordinatorId]));
        return $job->refresh();
    }

    public function updatePriority(FlowJob $job, string $priority, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);
        $job->update(['priority' => $priority]);
        $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.priority_updated', 'description' => 'Job priority changed to '.$priority]);
        app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Order priority updated', $job->job_number.' · Priority changed to '.$priority, 'update', $actor);
        return $job->refresh();
    }

    /**
     * @deprecated Order health is retained only as legacy storage compatibility and is no longer user-facing.
     */
    public function updateHealth(FlowJob $job, string $health, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);
        $job->update(['health' => $health]);
        $job->phaseHistories()->whereNull('completed_at')->update(['health_override' => $health]);

        return $job->refresh();
    }

    public function deactivate(FlowJob $job, User $actor): FlowJob
    {
        $this->assertStatusEditable($job, $actor);
        $old = $job->status;
        $job->update([
            'status' => 'Inactive',
            'attention_requested' => false,
            'attention_reason' => null,
            'attention_by' => null,
            'attention_at' => null,
        ]);
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.deactivated',
            'description' => 'Order deactivated'.($old && $old !== 'Inactive' ? ' from '.$old : ''),
        ]);
        app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Order deactivated', $job->displayOrderNumber().' · '.$job->title, 'update', $actor);
        return $job->refresh();
    }

    public function cancel(
        FlowJob $job,
        User $actor,
        ?string $reason = null,
        bool $enforcePrototypeStageGate = false
    ): FlowJob {
        $this->assertStatusEditable($job, $actor);

        // Cancellation reasons use the same sanitized rich-text pipeline as
        // Order comments/descriptions. This keeps pasted images safe, enforces
        // the 2,000-character text limit without counting HTML/image URLs, and
        // preserves durable @mention tokens.
        $reason = app(RichTextService::class)->normalize(
            $reason,
            2000,
            'orderCancellationReason',
        ) ?? '';

        $mentionIds = app(MentionService::class)
            ->userIdsFromText($reason);

        $activityDescription = $reason !== ''
            ? app(RichTextService::class)
                ->prependText('Order cancelled', $reason)
            : 'Order cancelled';

        if ($enforcePrototypeStageGate) {
            $job->loadMissing('phase');

            abort_if(
                (int) ($job->phase?->sequence ?? 999) > 4,
                422,
                'Orders can only be cancelled through the QC stage.'
            );
        }

        $cancelled = DB::transaction(
            function () use (
                $job,
                $actor,
                $reason,
                $mentionIds,
                $activityDescription,
                $enforcePrototypeStageGate
            ): FlowJob {
                $locked = FlowJob::query()
                    ->whereKey($job->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $locked->loadMissing('phase');

                if ($enforcePrototypeStageGate) {
                    abort_if(
                        (int) ($locked->phase?->sequence ?? 999) > 4,
                        422,
                        'Orders can only be cancelled through the QC stage.'
                    );
                }

                abort_if(
                    $locked->completed_at
                    || in_array(
                        (string) $locked->status,
                        self::INACTIVE_STATUSES,
                        true
                    ),
                    422,
                    'This Order can no longer be cancelled.'
                );

                $cancelStatus = app(
                    OrderTaskFlagService::class
                )->cancelledStatus();

                $cancelStatusId = app(
                    OrderTaskFlagService::class
                )->statusRecord(
                    $cancelStatus,
                    false
                )?->id;

                $locked->tasks()
                    ->whereNull('completed_at')
                    ->update([
                        'status' => $cancelStatus,
                        'order_task_status_id' => $cancelStatusId,
                        'order_task_flag_id' => null,
                        'needs_attention' => false,
                        'attention_reason' => null,
                    ]);

                $locked->phaseHistories()
                    ->whereNull('completed_at')
                    ->update([
                        'status' => 'cancelled',
                        'completed_at' => now(),
                    ]);

                $locked->update([
                    'status' => 'Cancelled',
                    'order_flag_id' => null,
                    'needs_attention' => false,
                    'attention_requested' => false,
                    'attention_reason' => null,
                    'attention_by' => null,
                    'attention_at' => null,

                    'cancellation_reason' =>
                        $reason !== '' ? $reason : null,

                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                ]);

                $locked->activities()->create([
                    'user_id' => $actor->id,

                    'event' => 'job.cancelled',

                    'description' => $activityDescription,

                    'meta' => [
                        'cancellation_reason' => true,
                        'mention_user_ids' => $mentionIds,
                    ],
                ]);

                return $locked->refresh();
            },
            3
        );

        app(NotificationService::class)
            ->notifyJobParticipants(
                $cancelled,
                'Order cancelled',
                $cancelled->displayOrderNumber()
                    .' · '
                    .$cancelled->title,
                'update',
                $actor,
            );

        /*
        * Explicit @mentions receive the same FlowTrack mention notification
        * behavior already used by task and Order comments.
        */
        if ($mentionIds !== []) {
            app(NotificationService::class)
                ->notifyMentionedUsers(
                    $mentionIds,
                    $actor->name
                        .' mentioned you in '
                        .$cancelled->displayOrderNumber(),
                    $reason,
                    $cancelled,
                    null,
                    $actor,
                );
        }

        return $cancelled->refresh();
    }

    public function delete(FlowJob $job, User $actor): void
    {
        $access = app(AccessControlService::class);
        abort_unless($access->can($actor, 'jobs', 'delete'), 403);
        abort_unless($this->visibleQuery($actor)->whereKey($job->id)->exists(), 404);

        // Notify participants before the soft delete so record-scope checks can
        // still resolve the Job for each recipient.
        app(NotificationService::class)->notifyJobParticipants($job->fresh(), 'Order deleted', $job->displayOrderNumber().' · '.$job->title, 'update', $actor);

        DB::transaction(function () use ($job, $actor): void {
            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.deleted',
                'description' => 'Order deleted',
            ]);

            // Release every Inquiry relationship before the soft delete so
            // each Inquiry can be linked/converted again. Only the legacy primary
            // source can represent a true Inquiry -> Order conversion; additional
            // manually-linked Inquiries keep their lifecycle result/status intact.
            $primaryInquiryId = (int) ($job->source_inquiry_id ?? 0);
            $linkedInquiries = $job->linkedInquiries()->get();
            if ($linkedInquiries->isEmpty() && $primaryInquiryId > 0) {
                $legacySource = $job->sourceInquiry()->first();
                if ($legacySource) $linkedInquiries->push($legacySource);
            }

            foreach ($linkedInquiries as $linkedInquiry) {
                if ((int) $linkedInquiry->converted_job_id !== (int) $job->id) {
                    continue;
                }

                if ((int) $linkedInquiry->id === $primaryInquiryId && (string) $linkedInquiry->result === 'converted') {
                    $linkedInquiry->update([
                        'result' => null,
                        'converted_job_id' => null,
                        'completed_at' => null,
                    ]);
                    app(InquiryService::class)->syncAutomaticStatus($linkedInquiry, $actor);
                } else {
                    $linkedInquiry->update(['converted_job_id' => null]);
                }
            }

            DB::table('flow_job_inquiries')->where('flow_job_id', $job->id)->delete();
            if ($job->source_inquiry_id) {
                $job->update(['source_inquiry_id' => null]);
            }

            $job->delete();
        });

        app(DashboardService::class)->forget($actor->id);
        app(ReportService::class)->forget($actor->id);
    }

    public function updateShippingDetails(FlowJob $job, array $changes, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);

        $allowed = [
            'shipping_address',
            'shipping_phone_country_code',
            'shipping_phone',
            'shipping_postal_code',
        ];
        $unknown = array_diff(array_keys($changes), $allowed);
        abort_if($unknown !== [], 422, 'This shipping field cannot be edited inline.');
        abort_if($changes === [], 422, 'No shipping details were provided.');

        $updates = [];
        foreach ($changes as $field => $value) {
            $updates[$field] = trim((string) ($value ?? ''));
        }

        validator($updates, [
            'shipping_address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'shipping_phone_country_code' => ['sometimes', 'nullable', 'string', 'max:12', 'regex:/^\\+[0-9]{1,4}$/'],
            'shipping_phone' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[0-9()\\s.\\-]{5,40}$/'],
            'shipping_postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
        ], [
            'shipping_phone_country_code.regex' => 'Choose a valid international phone code.',
            'shipping_phone.regex' => 'Enter a valid shipping contact phone number.',
        ])->validate();

        if (array_key_exists('shipping_phone_country_code', $updates) && $updates['shipping_phone_country_code'] !== '') {
            $validPhoneCode = MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('phone_country_code')
                ->active()
                ->where('name', $updates['shipping_phone_country_code'])
                ->exists();

            if (! $validPhoneCode) {
                throw ValidationException::withMessages([
                    'shipping_phone_country_code' => 'Choose an active phone country code from Master Data.',
                ]);
            }
        }

        foreach ($updates as $field => $value) {
            $updates[$field] = $value === '' ? null : $value;
        }

        // A manually changed address/postal code is now an Order-specific snapshot,
        // so it should no longer claim to be identical to the saved client address.
        if (array_key_exists('shipping_address', $updates) || array_key_exists('shipping_postal_code', $updates)) {
            $updates['shipping_source_address_id'] = null;
        }

        $job->update($updates);
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.shipping_updated',
            'description' => 'Order shipping details updated',
            'meta' => ['fields' => array_values(array_intersect(array_keys($updates), $allowed))],
        ]);

        $fresh = $job->refresh();
        app(NotificationService::class)->notifyJobParticipants(
            $fresh,
            'Order shipping details updated',
            $fresh->job_number.' · Shipping details changed',
            'update',
            $actor,
        );

        return $fresh;
    }

    /** Update the two fields exposed together by the prototype Order overview modal. */
    public function updateOverviewDetails(FlowJob $job, string $title, string $description, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);
        $title = trim($title);
        abort_if($title === '', 422, 'Order title is required.');
        abort_if(mb_strlen($title) > 255, 422, 'Order title is too long.');

        $storedDescription = app(RichTextService::class)->normalize(trim($description), 10000, 'description');
        $mentionIds = app(MentionService::class)->userIdsFromText($storedDescription);

        $fresh = DB::transaction(function () use ($job, $actor, $title, $storedDescription, $mentionIds): FlowJob {
            $locked = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($locked, $actor);
            $locked->update(['title' => $title, 'description' => $storedDescription]);
            $locked->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.overview_updated',
                'description' => 'Order overview updated',
                'meta' => $mentionIds ? ['mention_user_ids' => $mentionIds] : null,
            ]);
            return $locked->refresh();
        }, 3);

        app(NotificationService::class)->notifyJobParticipants(
            $fresh,
            'Order overview updated',
            $fresh->displayOrderNumber().' · Order title or brief changed',
            'update',
            $actor,
            [],
            $mentionIds,
        );
        if ($mentionIds) {
            app(NotificationService::class)->notifyMentionedUsers(
                $mentionIds,
                $actor->name.' mentioned you in '.$fresh->displayOrderNumber(),
                (string) $fresh->description,
                $fresh,
                null,
                $actor,
            );
        }

        return $fresh;
    }

    public function updateTextField(FlowJob $job, string $field, ?string $value, User $actor): FlowJob
    {
        $this->assertEditable($job, $actor);
        abort_unless(in_array($field, ['title', 'description'], true), 422, 'This Job field cannot be edited inline.');

        $value = trim((string) $value);
        if ($field === 'title') {
            abort_if($value === '', 422, 'Job title is required.');
            abort_if(mb_strlen($value) > 255, 422, 'Job title is too long.');
        }

        $storedValue = $field === 'description'
            ? app(RichTextService::class)->normalize($value, 10000, 'description')
            : $value;
        $mentionIds = $field === 'description'
            ? app(MentionService::class)->userIdsFromText($storedValue)
            : [];

        $job->update([$field => $storedValue]);
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.'.$field.'_updated',
            'description' => $field === 'title' ? 'Job title updated' : 'Job description updated',
            'meta' => $mentionIds ? ['mention_user_ids' => $mentionIds] : null,
        ]);

        $fresh = $job->refresh();
        app(NotificationService::class)->notifyJobParticipants(
            $fresh,
            'Job details updated',
            $fresh->job_number.' · '.($field === 'title' ? 'Job name changed' : 'Description updated'),
            'update',
            $actor,
            [],
            $mentionIds,
        );

        if ($mentionIds) {
            app(NotificationService::class)->notifyMentionedUsers(
                $mentionIds,
                $actor->name.' mentioned you in '.$fresh->job_number,
                (string) $fresh->description,
                $fresh,
                null,
                $actor,
            );
        }

        return $fresh;
    }

    public function updateFinanceField(FlowJob $job, string $field, mixed $value, User $actor): FlowJob
    {
        abort_unless(app(AccessControlService::class)->canEditParentRecordModule($actor, 'finance', $job), 403);
        $this->assertEditable($job, $actor);
        abort_unless(in_array($field, ['commercial_value', 'currency'], true), 422, 'This Order finance field cannot be edited inline.');

        if ($field === 'commercial_value') {
            abort_unless(is_numeric($value), 422, 'Commercial value must be a number.');
            $value = round((float) $value, 2);
            abort_if($value < 0 || $value > 999999999999.99, 422, 'Commercial value is outside the allowed range.');
        } else {
            $value = strtoupper(trim((string) $value));
            abort_unless((bool) preg_match('/^[A-Z]{3}$/', $value), 422, 'Currency must be a 3-letter code.');
            $currentCurrency = strtoupper((string) ($job->currency ?? ''));
            $validCurrency = $value === $currentCurrency
                || app(MasterDataService::class)->active('currency')->contains(fn ($currency) => strtoupper((string) $currency->code) === $value);
            abort_unless($validCurrency, 422, 'Select a valid active currency.');
        }

        $job->update([$field => $value]);
        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => 'job.finance_updated',
            'description' => $field === 'commercial_value' ? 'Order commercial value updated' : 'Order currency updated',
        ]);

        return $job->refresh();
    }

    /**
     * Update the editable fields exposed by the prototype's single product modal.
     * One transaction, one audit event, and one notification prevents partially
     * saved rows when several product fields are changed together.
     */
    public function updateItemDetails(FlowJob $job, FlowJobItem $item, array $data, User $actor): FlowJobItem
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'edit'), 403);
        $this->assertEditable($job, $actor);
        abort_unless((int) $item->flow_job_id === (int) $job->id, 404);
        abort_if((bool) ($item->is_removed ?? false), 422, 'Restore this product before editing it.');

        $productId = (int) ($data['catalog_product_id'] ?? 0);
        abort_unless($productId > 0, 422, 'Search for and select a product first.');
        $product = app(ProductCatalogService::class)->findActiveProductOrFail($productId);

        $categoryName = trim((string) ($product->parent?->name ?? ''));
        if ($categoryName === '') {
            $legacyCategory = trim((string) $product->description);
            $categoryName = trim(explode(' ·', $legacyCategory, 2)[0]);
        }
        $categoryName = $categoryName !== '' ? $categoryName : 'Uncategorized';

        $duplicateExists = FlowJobItem::query()
            ->where('flow_job_id', $job->id)
            ->where('id', '!=', $item->id)
            ->where('is_removed', false)
            ->where(function (Builder $query) use ($product): void {
                $query->where('catalog_product_id', (int) $product->id)
                    ->orWhereRaw('LOWER(product_name) = ?', [mb_strtolower((string) $product->name)]);
            })
            ->exists();
        abort_if($duplicateExists, 422, 'This product is already added to the Order.');

        $supplierId = (int) ($data['supplier_id'] ?? 0);
        abort_unless($supplierId > 0, 422, 'Select a supplier for this product.');
        $validSupplier = MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('supplier')
            ->active()
            ->whereKey($supplierId)
            ->exists();
        abort_unless($validSupplier, 422, 'Select an active supplier for this product.');

        $quantity = max(1, (int) ($data['quantity'] ?? 1));
        $basePrice = $product->productPriceForQuantity($quantity, (int) $job->client_id);
        $fallbackPrice = $data['unit_price'] ?? 0;
        abort_unless(is_numeric($fallbackPrice), 422, 'Unit price must be a number.');
        $unitPrice = round(max(0, (float) ($basePrice ?? $fallbackPrice)), 2);
        abort_if($unitPrice > 999999999999.99, 422, 'Unit price is outside the allowed range.');
        $priceSnapshot = app(\App\Services\Orders\OrderProductPricing::class)
            ->supplierUnitPrices([[
                'product_id' => (int) $product->id, 'supplier_id' => $supplierId,
                'quantity' => $quantity,
            ]], collect([(int) $product->id => $product]));
        $supplierUnitPrice = $priceSnapshot[$product->id.':'.$supplierId] ?? null;

        $notes = trim((string) ($data['notes'] ?? ''));
        abort_if(mb_strlen($notes) > 2000, 422, 'Product notes may not exceed 2000 characters.');

        $updated = DB::transaction(function () use ($job, $item, $actor, $product, $categoryName, $supplierId, $quantity, $unitPrice, $supplierUnitPrice, $notes): FlowJobItem {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            $this->assertEditable($lockedJob, $actor);
            $lockedItem = FlowJobItem::query()
                ->where('flow_job_id', $lockedJob->id)
                ->whereKey($item->id)
                ->lockForUpdate()
                ->firstOrFail();
            abort_if((bool) ($lockedItem->is_removed ?? false), 422, 'Restore this product before editing it.');

            $duplicateExists = FlowJobItem::query()
                ->where('flow_job_id', $lockedJob->id)
                ->where('id', '!=', $lockedItem->id)
                ->where('is_removed', false)
                ->where(function (Builder $query) use ($product): void {
                    $query->where('catalog_product_id', (int) $product->id)
                        ->orWhereRaw('LOWER(product_name) = ?', [mb_strtolower((string) $product->name)]);
                })
                ->exists();
            abort_if($duplicateExists, 422, 'This product is already added to the Order.');

            $lockedItem->update([
                'catalog_product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'category_name' => $categoryName,
                'supplier_id' => $supplierId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'supplier_unit_price' => $supplierUnitPrice,
                'notes' => $notes !== '' ? $notes : null,
                'updated_by' => $actor->id,
            ]);
            $this->syncItemSummary($lockedJob);
            $lockedJob->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.product_updated',
                'description' => 'Product, supplier, quantity and price updated',
            ]);

            return $lockedItem->refresh();
        }, 3);

        app(NotificationService::class)->notifyJobParticipants(
            $job->refresh(),
            'Order product updated',
            $job->displayOrderNumber().' · Product/supplier/quantity details updated',
            'update',
            $actor,
        );

        return $updated;
    }

    public function updateItem(FlowJob $job, FlowJobItem $item, string $field, mixed $value, User $actor): FlowJobItem
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'edit'), 403);
        $this->assertEditable($job, $actor);
        abort_unless((int) $item->flow_job_id === (int) $job->id, 404);
        abort_unless(in_array($field, ['category_name', 'product_name', 'supplier_id', 'quantity', 'unit_price', 'notes'], true), 422, 'This Job item field cannot be edited inline.');
        abort_if($item->is_removed, 422, 'Restore this product before editing it.');

        $wasDraft = blank($item->product_name);
        $originalCategory = (string) ($item->category_name ?? '');

        if ($field === 'supplier_id') {
            $value = filled($value) ? (int) $value : null;
            abort_unless($value, 422, 'Select a supplier for this product.');
            $validSupplier = MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('supplier')
                ->active()
                ->whereKey($value)
                ->exists();
            abort_unless($validSupplier, 422, 'Select an active supplier for this product.');
        } elseif ($field === 'quantity') {
            $value = max(1, (int) $value);
        } elseif ($field === 'unit_price') {
            abort_unless(is_numeric($value), 422, 'Unit price must be a number.');
            $value = round(max(0, (float) $value), 2);
            abort_if($value > 999999999999.99, 422, 'Unit price is outside the allowed range.');
        } elseif ($field === 'notes') {
            $value = trim((string) $value);
            abort_if(mb_strlen($value) > 2000, 422, 'Product notes may not exceed 2000 characters.');
            $value = $value === '' ? null : $value;
        } else {
            $value = trim((string) $value);
            abort_if($value === '', 422, $field === 'category_name' ? 'Product category is required.' : 'Product name is required.');
        }

        $item->update([$field => $value, 'updated_by' => $actor->id]);
        if (in_array($field, ['supplier_id', 'quantity'], true) && $item->catalog_product_id) {
            $product = MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('product')->active()->find($item->catalog_product_id);
            $id = (int) $item->supplier_id;
            $cost = $product && $id > 0
                ? app(\App\Services\Orders\OrderProductPricing::class)
                    ->supplierUnitPrices([[
                        'product_id' => (int) $product->id, 'supplier_id' => $id,
                        'quantity' => (int) $item->quantity,
                    ]], collect([(int) $product->id => $product]))
                : [];
            $item->update(['supplier_unit_price' => $cost[$item->catalog_product_id.':'.$id] ?? null]);
            if ($field === 'quantity' && $product) {
                $sellingPrice = $product->productPriceForQuantity((int) $item->quantity, (int) $job->client_id);
                if ($sellingPrice !== null) $item->update(['unit_price' => round($sellingPrice, 2)]);
            }
        }

        // Category and product are a dependent pair. A real category change
        // always clears the previous product so the user explicitly chooses a
        // product from the newly selected category. This keeps the inline UI
        // deterministic and prevents stale category/product combinations.
        if ($field === 'category_name' && (string) $value !== $originalCategory && filled($item->product_name)) {
            $item->update(['product_name' => null]);
        }

        $this->syncItemSummary($job);
        $item = $item->refresh();

        // A newly inserted blank row is only a UI draft. Do not generate a
        // product-update activity/notification until the user actually chooses
        // a product; the Notifications page still receives the normal event
        // once the new row becomes a real product line.
        if ($wasDraft && blank($item->product_name)) {
            return $item;
        }

        if ($wasDraft && $field === 'product_name') {
            $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.product_added', 'description' => 'Product added to Order']);
            app(NotificationService::class)->notifyJobParticipants(
                $job->refresh(),
                'Product added to Order',
                $job->displayOrderNumber().' · '.$item->product_name.' · '.number_format($item->quantity).' units',
                'update',
                $actor,
            );
            return $item;
        }

        $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.product_updated', 'description' => 'Product and quantity details updated']);
        app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Order product updated', $job->job_number.' · Product/category/quantity changed', 'update', $actor);
        return $item;
    }

    public function addItem(FlowJob $job, string $category, string $product, int $quantity, User $actor, float $unitPrice = 0, ?int $catalogProductId = null, ?int $supplierId = null): FlowJobItem
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'view') && app(AccessControlService::class)->can($actor, 'catalog_products', 'create'), 403);
        $this->assertEditable($job, $actor);
        $category = trim($category);
        $product = trim($product);

        // Older Jobs can still rely on the summary columns without a persisted
        // flow_job_items row. Preserve that existing line before inserting the
        // new blank draft so Add product never erases legacy product data.
        if (!$job->items()->exists() && filled($job->product)) {
            FlowJobItem::create([
                'flow_job_id' => $job->id,
                'supplier_id' => $job->supplier_id ?: null,
                'category_name' => $job->category,
                'product_name' => $job->product,
                'quantity' => max(1, (int) $job->quantity),
                'unit_price' => 0,
                'updated_by' => $actor->id,
                'sort_order' => 0,
            ]);
        }

        if ($supplierId) {
            $supplierExists = MasterRecord::query()
                ->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('supplier')
                ->active()
                ->whereKey($supplierId)
                ->exists();
            abort_unless($supplierExists, 422, 'Select an active supplier for this product.');
        }

        $supplierUnitPrice = null;
        if ($catalogProductId && $supplierId) {
            $catalogProduct = MasterRecord::query()->forWorkspace(app(MasterDataService::class)->workspaceId())
                ->ofType('product')->active()->find($catalogProductId);
            if ($catalogProduct) {
                $costs = app(\App\Services\Orders\OrderProductPricing::class)->supplierUnitPrices([[
                    'product_id' => (int) $catalogProductId, 'supplier_id' => (int) $supplierId,
                    'quantity' => max(1, $quantity),
                ]], collect([(int) $catalogProductId => $catalogProduct]));
                $supplierUnitPrice = $costs[$catalogProductId.':'.$supplierId] ?? null;
            }
        }

        $item = FlowJobItem::create([
            'flow_job_id' => $job->id,
            'catalog_product_id' => $catalogProductId,
            'supplier_id' => $supplierId,
            'category_name' => $category !== '' ? $category : null,
            'product_name' => $product !== '' ? $product : null,
            'quantity' => max(1, $quantity),
            'unit_price' => max(0, $unitPrice),
            'supplier_unit_price' => $supplierUnitPrice,
            'updated_by' => $actor->id,
            'sort_order' => ((int) $job->items()->max('sort_order')) + 1,
        ]);

        $this->syncItemSummary($job);

        // Blank rows are drafts opened by “Add product”; wait until a product
        // is selected before recording the normal Product added notification.
        if ($product !== '') {
            $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.product_added', 'description' => 'Product added to Order']);
            app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Product added to Order', $job->displayOrderNumber().' · '.$item->product_name.' · '.number_format($item->quantity).' units', 'update', $actor);
        }

        return $item->refresh();
    }

    public function removeItem(FlowJob $job, FlowJobItem $item, User $actor, ?string $reason = null): void
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'view') && app(AccessControlService::class)->can($actor, 'catalog_products', 'delete'), 403);
        $this->assertEditable($job, $actor);
        abort_unless((int) $item->flow_job_id === (int) $job->id, 404);
        if ($item->is_removed) return;

        $wasDraft = blank($item->product_name);
        $item->update([
            'is_removed' => true,
            'removed_at' => now(),
            'removed_by' => $actor->id,
            'removal_reason' => blank($reason) ? null : trim((string) $reason),
            'updated_by' => $actor->id,
        ]);
        $this->syncItemSummary($job);

        if (!$wasDraft) {
            $description = 'Product removed from active Order';
            if (filled($reason)) $description .= ': '.trim((string) $reason);
            $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.product_removed', 'description' => $description]);
            app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Product removed from Order', $job->job_number.' · Product retained in history', 'update', $actor);
        }
    }

    public function restoreItem(FlowJob $job, FlowJobItem $item, User $actor): FlowJobItem
    {
        abort_unless(app(AccessControlService::class)->can($actor, 'catalog_products', 'view') && app(AccessControlService::class)->can($actor, 'catalog_products', 'edit'), 403);
        $this->assertEditable($job, $actor);
        abort_unless((int) $item->flow_job_id === (int) $job->id, 404);
        if (!$item->is_removed) return $item;

        $item->update([
            'is_removed' => false,
            'removed_at' => null,
            'removed_by' => null,
            'removal_reason' => null,
            'updated_by' => $actor->id,
        ]);
        $this->syncItemSummary($job);

        $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.product_restored', 'description' => 'Product restored to active Order']);
        app(NotificationService::class)->notifyJobParticipants($job->refresh(), 'Product restored to Order', $job->job_number.' · Product list updated', 'update', $actor);

        return $item->refresh();
    }

    private function syncItemSummary(FlowJob $job): void
    {
        app(\App\Services\Orders\OrderItemSummaryService::class)->sync($job);
    }

    public function syncWorkflowTasks(FlowJob $job, ?User $actor = null, bool $includeDraft = false): void
    {
        $job->loadMissing([
            'workflow.phases.taskPack.items.defaultAssignee',
            'workflow.phases.taskPack.items.defaultDepartment',
            'workflow.phases.taskPack.items.priority',
            'workflow.phases.taskPack.items.documentCategory',
            'phaseHistories','phase','items',
        ]);

        if (!$job->items()->exists() && filled($job->product)) {
            FlowJobItem::create([
                'flow_job_id' => $job->id,
                'product_name' => $job->product,
                'category_name' => $job->category,
                'quantity' => max(1, (int) $job->quantity),
                'sort_order' => 0,
            ]);
        }

        if ($job->status === 'Draft' && !$includeDraft) {
            return;
        }

        foreach ($job->workflow->phases as $phase) {
            $this->syncPhaseTaskPack($job, $phase, false, $actor);
        }
        // Avoid any supplier-assignment queries for Task Packs that only use
        // internal assignees (the common path).
        if ($job->workflow->phases->contains(fn ($phase) => $phase->taskPack?->items?->contains(
            fn ($item) => ($item->default_assignee_type ?? 'user') === 'supplier'
        ))) {
            app(\App\Services\SupplierBridge\SupplierDefaultTaskAssigner::class)->syncForOrder($job, $actor);
        }
    }

    public function appendTask(FlowJob $job, array $data, User $actor): Task
    {
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);

        $access = app(AccessControlService::class);
        abort_unless($access->canCreateJobTask($actor, $job), 403);
        abort_if($job->completed_at || $job->status === 'Completed', 422, 'A completed Order cannot receive another task.');
        abort_if(in_array($job->status, self::INACTIVE_STATUSES, true), 422, 'An inactive Order cannot receive another task.');

        return DB::transaction(function () use ($job, $data, $actor): Task {
            $lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($lockedJob);
            $lockedJob->loadMissing('phase', 'workflow.phases');

            $phaseId = (int) ($data['workflow_phase_id'] ?? 0);
            abort_unless($phaseId > 0, 422, 'Select a workflow phase for this task.');

            $workflowPhaseIds = $lockedJob->workflow?->phases?->pluck('id')->map(fn ($id) => (int) $id) ?? collect();
            abort_unless($workflowPhaseIds->contains($phaseId), 422, 'The selected phase does not belong to this Order workflow.');

            $nextNumber = max(301, (int) Task::withTrashed()->max('id') + 301);
            do {
                $taskNumber = 'TSK-'.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);
                $nextNumber++;
            } while (Task::withTrashed()->where('task_number', $taskNumber)->exists());

            $isDraft = $lockedJob->status === 'Draft';
            $orderTaskRules = app(OrderTaskFlagService::class);
            $initialStatus = $isDraft ? $orderTaskRules->notStartedStatus() : $orderTaskRules->readyStatus();
            $initialStatusId = $orderTaskRules->statusRecord($initialStatus, false)?->id;
            $assigneeId = filled($data['assignee_id'] ?? null) ? (int) $data['assignee_id'] : null;
            $task = Task::create([
                'task_number' => $taskNumber,
                'flow_job_id' => $lockedJob->id,
                'workflow_phase_id' => $phaseId,
                'task_pack_task_id' => null,
                'assignee_id' => $assigneeId,
                'setup_assignee_id' => null,
                'title' => trim((string) $data['title']),
                'description' => blank($data['description'] ?? null) ? null : trim((string) $data['description']),
                'status' => $initialStatus,
                'order_task_status_id' => $initialStatusId,
                'priority' => $lockedJob->priority ?: 'Medium',
                'progress' => 0,
                'start_date' => $isDraft ? null : app(WorkspaceSettingsService::class)->localToday(),
                'due_date' => blank($data['due_date'] ?? null) ? null : $data['due_date'],
            ]);

            $task = $orderTaskRules->syncTask($task);

            FlowTaskComment::create([
                'flow_task_id' => $task->id,
                'user_id' => $actor->id,
                'body' => 'Task added manually to the Order taskflow.',
            ]);

            if ($assigneeId) {
                FlowJobMember::firstOrCreate(
                    ['flow_job_id' => $lockedJob->id, 'user_id' => $assigneeId],
                    ['access_level' => 'member', 'can_manage_tasks' => false, 'can_upload_documents' => true, 'can_view_financials' => false],
                );
            }

            $lockedJob->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.task_added',
                'description' => 'Task added: '.$task->title,
                'meta' => ['task_id' => $task->id, 'phase_id' => $phaseId],
            ]);

            $this->recalculateProgress($lockedJob->refresh());

            if ($assigneeId) {
                $taskId = $task->id;
                DB::afterCommit(function () use ($taskId, $actor): void {
                    $freshTask = Task::with(['job', 'phase', 'assignee'])->find($taskId);
                    if ($freshTask) app(NotificationService::class)->notifyTaskAssigned($freshTask, $actor);
                });
            }

            return $task->refresh();
        });
    }

    /**
     * Keep the parent Order working status synchronized with taskflow state.
     *
     * Order status is intentionally not a separately editable Master Data
     * catalogue. Initial task states keep a genuinely untouched Order as New;
     * once any task has started/completed the Order has an In Progress floor so
     * activating the next Ready/Not Started task cannot regress it back to New.
     * Working/special task states (for example Waiting for Client or Blocked)
     * are reflected on the parent while that task is the active work item.
     * Final Order states remain owned by the workflow/status actions.
     */
    public function syncAutomaticStatus(FlowJob $job, ?User $actor = null): FlowJob
    {
        $job->refresh();
        $current = mb_strtolower(trim((string) $job->status));
        if ($job->completed_at || in_array($current, ['draft', 'completed', 'cancelled', 'canceled', 'inactive'], true)) {
            return $job;
        }

        $tasks = $job->tasks()
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'workflow_phase_id', 'status', 'progress', 'completed_at', 'updated_at']);

        if ($tasks->isEmpty()) return $job;

        $isInitial = static function (string $status): bool {
            $normalized = mb_strtolower(trim($status));
            return BoardLaneResolver::isNotStarted($status)
                || in_array($normalized, ['ready', 'to do', 'todo', 'new'], true);
        };
        $isCompleted = static fn (Task $task): bool => $task->completed_at !== null
            || BoardLaneResolver::isCompleted((string) $task->status);

        $hasProgress = $tasks->contains(function (Task $task) use ($isInitial, $isCompleted): bool {
            return $isCompleted($task)
                || (int) $task->progress > 0
                || !$isInitial((string) $task->status);
        });

        $phaseId = (int) ($job->workflow_phase_id ?: 0);
        $phaseTasks = $phaseId > 0
            ? $tasks->filter(fn (Task $task): bool => (int) $task->workflow_phase_id === $phaseId)
            : $tasks;

        $openTasks = $phaseTasks->reject($isCompleted);
        $currentTask = $openTasks
            ->sortByDesc(function (Task $task) use ($isInitial): string {
                $workingBucket = $isInitial((string) $task->status) ? '0' : '1';
                $updated = $task->updated_at?->format('YmdHis.u') ?: '00000000000000000000';
                return $workingBucket.'-'.$updated.'-'.str_pad((string) $task->id, 10, '0', STR_PAD_LEFT);
            })
            ->first();

        if (!$currentTask) {
            $nextStatus = $hasProgress ? 'In Progress' : 'New';
        } elseif ($isInitial((string) $currentTask->status)) {
            $nextStatus = $hasProgress ? 'In Progress' : 'New';
        } else {
            $taskStatus = trim((string) $currentTask->status);
            $normalized = mb_strtolower($taskStatus);
            $nextStatus = in_array($normalized, ['completed', 'complete', 'done', 'cancelled', 'canceled', 'inactive'], true)
                ? 'In Progress'
                : ($taskStatus !== '' ? $taskStatus : 'In Progress');
        }

        if ((string) $job->status !== $nextStatus) {
            $oldStatus = (string) $job->status;
            $job->update(['status' => $nextStatus]);

            if ($actor) {
                $job->activities()->create([
                    'user_id' => $actor->id,
                    'event' => 'job.status_auto_changed',
                    'description' => 'Order status automatically changed from '.$oldStatus.' to '.$nextStatus.' based on task progress.',
                ]);
            }
        }

        return $job->refresh();
    }

    public function recalculateProgress(FlowJob $job): int
    {
        $job->loadMissing('workflow.phases.taskPack.items', 'tasks.setupTemplate', 'tasks.template');
        if ($job->completed_at || $job->status === 'Completed') {
            if ((int) $job->progress !== 100) $job->update(['progress' => 100]);
            app(\App\Services\Orders\OrderWorkflowSummaryService::class)->markStale((int) $job->id);
            return 100;
        }

        $phases = $job->workflow->phases->sortBy('sequence')->values();
        if ($phases->isEmpty()) {
            $job->update(['progress' => 0]);
            app(\App\Services\Orders\OrderWorkflowSummaryService::class)->markStale((int) $job->id);
            return 0;
        }

        $currentSequence = (int) ($job->phase?->sequence ?? 1);
        $score = 0.0;
        foreach ($phases as $phase) {
            if ((int) $phase->sequence < $currentSequence) {
                $score += 100;
                continue;
            }

            $tasks = JobDetailPresenter::phaseTasks($job, $phase)
                ->filter(fn (Task $task) => OrderDetailPresenter::isApplicableTask($task))
                ->values();
            if ($tasks->isEmpty()) continue;
            $completed = $tasks->filter(fn ($task) => $task->completed_at || $task->status === 'Completed')->count();
            $score += ($completed / max(1, $tasks->count())) * 100;
        }

        $progress = max(0, min(99, (int) round($score / $phases->count())));
        if ((int) $job->progress !== $progress) $job->update(['progress' => $progress]);
        app(\App\Services\Orders\OrderWorkflowSummaryService::class)->markStale((int) $job->id);
        return $progress;
    }

    /**
     * Only internal workflow-transition checks use this unfiltered task graph.
     * The Order is already authorized via findVisibleBase()/findVisible(), and
     * this relation is never returned as a user-visible task list. Restrict to
     * the current phase and eager-load evidence to keep queries bounded.
     */
    private function loadCanonicalCurrentPhaseTasks(FlowJob $job): void
    {
        $tasks = Task::query()
            ->where('flow_job_id', $job->id)
            ->where('workflow_phase_id', (int) $job->workflow_phase_id)
            ->with(['setupTemplate.documentCategory', 'template', 'documentCategory', 'documents', 'links'])
            ->get();

        $job->setRelation('tasks', $tasks);
    }

    /**
     * Read a bounded workflow graph for the automatic phase gate. The Order
     * stays permission-scoped, but its internal blocker evaluation must not
     * inherit the current actor's task-list visibility rules.
     */
    private function findVisibleForAutoAdvance(User $actor, int $id): FlowJob
    {
        $job = $this->findVisibleBase($actor, $id);
        $this->loadVisibleOverviewSummary($job, $actor);

        // The actor's task scope is for UI visibility, not for deciding whether
        // an Order may advance. A supplier action is executed by its nominated
        // FlowTracker delegate, who can see only some tasks in this phase.
        // Re-evaluate all phase blockers against the canonical current-phase
        // task set; never interpret hidden tasks as completed or absent.
        $this->loadCanonicalCurrentPhaseTasks($job);

        $currentPhase = $job->workflow?->phases?->firstWhere('id', (int) $job->workflow_phase_id);
        if ($currentPhase?->task_pack_id) {
            // The overview summary intentionally loads only Task Pack item IDs.
            // Blocker evaluation needs the requirement flags/category metadata,
            // so upgrade only the current phase rather than every workflow phase.
            $currentPhase->load(['taskPack.items.documentCategory']);
        }

        if ($job->relationLoaded('tasks') && $job->tasks->isNotEmpty()) {
            // Reuse the already eager-loaded current-phase evidence without
            // per-task queries or any additional user-visible task hydration.
            $job->tasks->loadMissing(['documents', 'links']);
        }

        return $job;
    }

    public function maybeAutoAdvance(FlowJob $job, User $actor): FlowJob
    {
        $job = $this->findVisibleForAutoAdvance($actor, $job->id);
        if (app(\App\Services\Orders\OrderHoldService::class)->activeHold($job)) return $job;

        // The approved Order runtime stays sequential/automatic regardless of
        // which reusable Order workflow template was selected. Inquiry and
        // other workflow types continue to respect their configured flag.
        $isDedicatedOrderWorkflow = app(OrderWorkflowSetupService::class)
            ->isActiveOrderWorkflow((int) $job->workflow_id);

        // Orders can keep a compatibility/snapshot workflow id even though the
        // generated runtime tasks are the seven-stage prototype tasks. Detect
        // that runtime from the already eager-loaded current-phase tasks so a
        // stale workflow id or auto_advance flag cannot strand the Order on a
        // completed phase. This adds no per-row/N+1 query.
        $workflowActions = app(OrderWorkflowActionService::class);
        $isPrototypeOrderRuntime = $job->tasks
            ->where('workflow_phase_id', $job->workflow_phase_id)
            ->contains(fn (Task $task) => filled($workflowActions->automationKey($task)));

        if (!$isDedicatedOrderWorkflow && !$isPrototypeOrderRuntime && !$job->phase?->auto_advance_on_ready) return $job;

        if (JobDetailPresenter::blockers($job)->isEmpty()) {
            return $this->completePhase($job, $actor, true);
        }

        return $job;
    }

    public function completePhase(FlowJob $job, User $actor, bool $automatic = false): FlowJob
    {
        if (!$automatic) $this->assertStatusEditable($job, $actor);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        return DB::transaction(function () use ($job, $actor, $automatic) {
            $this->syncWorkflowTasks($job, $actor);
            $job = $this->findVisible($actor, $job->id);
            $current = $job->phase;
            abort_unless($current, 422, 'The Job does not have a current workflow phase.');

            if ($automatic) {
                // Recheck the full set under the existing transaction before
                // committing an automatic stage change. Never use the delegate's
                // UI-filtered task collection to bypass a required task/file.
                $this->loadCanonicalCurrentPhaseTasks($job);
            }

            $blockers = JobDetailPresenter::blockers($job);
            if ($blockers->isNotEmpty()) {
                $firstBlocker = $blockers->first();
                abort(422, data_get($firstBlocker, 'description')
                    ?: data_get($firstBlocker, 'label')
                    ?: 'Complete the required Task Pack work before moving to the next phase.');
            }

            $next = $job->workflow->phases->firstWhere('sequence', $current->sequence + 1);
            FlowJobPhaseHistory::where('flow_job_id', $job->id)
                ->where('workflow_phase_id', $current->id)
                ->whereNull('completed_at')
                ->update(['status' => 'completed', 'completed_at' => now()]);

            if (!$next) {
                $job->update([
                    'status' => 'Completed',
                    'health' => 'Completed',
                    'progress' => 100,
                    'next_action' => null,
                    'order_flag_id' => null,
                    'needs_attention' => false,
                    'attention_requested' => false,
                    'attention_reason' => null,
                    'attention_by' => null,
                    'attention_at' => null,
                    'completed_at' => now(),
                ]);
                $job->activities()->create(['user_id' => $actor->id, 'event' => 'job.completed', 'description' => 'Workflow completed']);
                $jobId = $job->id;
                DB::afterCommit(function () use ($jobId, $actor) {
                    $fresh = FlowJob::with(['members','tasks'])->find($jobId);
                    if ($fresh) app(NotificationService::class)->notifyJobParticipants($fresh, 'Job completed', $fresh->job_number.' · Workflow completed', 'update', $actor);
                });
                return $job->refresh();
            }

            $isCompletedPhase = $next->short_name === 'Completed';
            $job->update([
                'workflow_phase_id' => $next->id,
                'source_workflow_phase_id' => $next->source_workflow_phase_id ?: $next->id,
                'status' => $isCompletedPhase ? 'Completed' : 'In Progress',
                'health' => $isCompletedPhase ? 'Completed' : 'On Track',
                'next_action' => $next->entry_condition ?: $next->entry_rule,
                'order_flag_id' => $isCompletedPhase ? null : $job->order_flag_id,
                'needs_attention' => $isCompletedPhase ? false : $job->needs_attention,
                'attention_requested' => $isCompletedPhase ? false : $job->attention_requested,
                'attention_reason' => $isCompletedPhase ? null : $job->attention_reason,
                'attention_by' => $isCompletedPhase ? null : $job->attention_by,
                'attention_at' => $isCompletedPhase ? null : $job->attention_at,
                'completed_at' => $isCompletedPhase ? now() : null,
            ]);

            $this->activateTaskPack($job, $next, $actor);
            FlowJobPhaseHistory::updateOrCreate(
                ['flow_job_id' => $job->id, 'workflow_phase_id' => $next->id],
                ['changed_by' => $actor->id, 'phase_owner_id' => $job->coordinator_id, 'target_date' => $job->delivery_date, 'health_override' => $job->health, 'status' => $isCompletedPhase ? 'completed' : 'active', 'entered_at' => now(), 'completed_at' => $isCompletedPhase ? now() : null]
            );

            $job->activities()->create(['user_id' => $actor->id, 'event' => 'phase.activated', 'description' => $next->name.' activated']);
            $jobId = $job->id;
            $nextName = $next->name;
            DB::afterCommit(function () use ($jobId, $nextName, $actor) {
                $fresh = FlowJob::with(['members','tasks'])->find($jobId);
                if ($fresh) app(NotificationService::class)->notifyJobParticipants($fresh, 'Job moved to '.$nextName, $fresh->job_number.' · Current phase is now '.$nextName, 'update', $actor);
            });
            $this->recalculateProgress($job->refresh());
            return $job->refresh();
        });
    }

    public function moveToPhase(FlowJob $job, int $phaseId, User $actor): FlowJob
    {
        $this->assertStatusEditable($job, $actor);
        app(\App\Services\Orders\OrderHoldService::class)->assertNotHeld($job);
        $job->load('phase', 'workflow.phases');
        $target = $job->workflow->phases->firstWhere('id', $phaseId)
            ?: $job->workflow->phases->firstWhere('source_workflow_phase_id', $phaseId);
        abort_unless($target, 422, 'Invalid workflow phase.');
        if ($target->sequence === $job->phase->sequence + 1) return $this->completePhase($job, $actor);
        abort_if($target->sequence > $job->phase->sequence + 1, 422, 'Complete required phase controls before skipping ahead.');
        $job->update([
            'workflow_phase_id' => $target->id,
            'source_workflow_phase_id' => $target->source_workflow_phase_id ?: $target->id,
            'status' => $target->short_name === 'Completed' ? 'Completed' : 'In Progress',
        ]);
        $this->activateTaskPack($job, $target, $actor);
        $this->recalculateProgress($job->refresh());
        return $job->refresh();
    }

    private function activateTaskPack(FlowJob $job, WorkflowPhase $phase, User $actor): void
    {
        $this->syncPhaseTaskPack($job, $phase, true, $actor);
    }

    private function syncPhaseTaskPack(FlowJob $job, WorkflowPhase $phase, bool $activate = false, ?User $actor = null): void
    {
        $phase->loadMissing('taskPack.items.defaultAssignee', 'taskPack.items.defaultDepartment', 'taskPack.items.priority', 'taskPack.items.documentCategory');
        if (!$phase->taskPack) return;

        // Older FlowTrack data stored a phase-level required document before
        // Task Pack items became the single source of truth. If a mapped Task
        // Pack has no document requirement at all, migrate that legacy value
        // onto the most likely Task Pack item once, then all runtime logic
        // continues to read only from Task Pack/task data.
        if ($this->restoreLegacyTaskPackDocumentRequirement($phase)) {
            $phase->unsetRelation('taskPack');
            $phase->load('taskPack.items.defaultAssignee', 'taskPack.items.defaultDepartment', 'taskPack.items.priority', 'taskPack.items.documentCategory');
        }

        $currentSequence = (int) ($job->phase?->sequence ?? $job->workflow_phase_id);
        $isDraft = $job->status === 'Draft';
        $isCurrent = !$isDraft && ((int) $phase->id === (int) $job->workflow_phase_id || $activate);
        $isPast = !$isDraft && (int) $phase->sequence < $currentSequence;

        $orderTaskRules = app(OrderTaskFlagService::class);
        $notStartedStatus = $orderTaskRules->notStartedStatus();
        $readyStatus = $orderTaskRules->readyStatus();
        $completedStatus = $orderTaskRules->completedStatus();

        // CHANGE 2026-08-24:
        // tasks.task_pack_task_id still has a foreign key to the legacy
        // task_pack_tasks table. Modern Order workflow setup reads from
        // task_pack_items, so repair a missing same-id legacy mirror before
        // attempting to create runtime Task rows. This also self-heals any
        // Task Pack item that was inserted directly by an older migration.
        $repairedLegacyMirrors = app(TaskPackService::class)
            ->ensureLegacyMirrorsForPack($phase->taskPack);

        if ($repairedLegacyMirrors) {
            $phase->taskPack->unsetRelation('items');
            $phase->taskPack->load(
                'items.defaultAssignee',
                'items.defaultDepartment',
                'items.priority',
                'items.documentCategory'
            );
        }

        $templates = $phase->taskPack->items->values();
        $firstRequiredTemplateId = (int) (($templates->first(fn ($item) => $item->is_required !== false) ?: $templates->first())?->id ?: 0);

        // Resolve department fallback assignees in two bounded queries instead
        // of querying Department/User once per Task Pack item (N+1 safe).
        $departmentCodes = $templates
            ->filter(fn ($item) => !$item->defaultAssignee && $item->defaultDepartment)
            ->pluck('defaultDepartment.code')
            ->filter()
            ->unique()
            ->values();
        $departmentIdsByCode = $departmentCodes->isEmpty()
            ? collect()
            : Department::query()->whereIn('code', $departmentCodes)->pluck('id', 'code');
        $departmentIds = $departmentIdsByCode->values()->filter()->unique()->values();
        $fallbackUsersByDepartment = $departmentIds->isEmpty()
            ? collect()
            : User::query()
                ->where('is_active', true)
                ->whereIn('department_id', $departmentIds)
                ->orderBy('id')
                ->get(['id', 'department_id', 'name', 'profile_image_path'])
                ->unique('department_id')
                ->keyBy('department_id');
        $nextTaskNumber = max(301, (int) Task::withTrashed()->max('id') + 301);

        foreach ($templates as $template) {
            $assignee = $template->defaultAssignee;
            if (!$assignee && ($template->default_assignee_type ?? 'user') !== 'supplier' && $template->defaultDepartment) {
                $legacyDepartmentId = (int) ($departmentIdsByCode->get($template->defaultDepartment->code) ?: 0);
                $assignee = $legacyDepartmentId ? $fallbackUsersByDepartment->get($legacyDepartmentId) : null;
            }

            $assigneeId = $assignee?->id;
            $priority = $template->priority?->name ?: $job->priority;
            $dueDate = null;
            if ($isCurrent) {
                $dueDate = app(WorkspaceSettingsService::class)->localToday()->addDays(max(0, (int) $template->due_offset_days));
                if ($job->delivery_date && $dueDate->gt($job->delivery_date)) $dueDate = $job->delivery_date->copy();
            }

            $isFirstRequired = $firstRequiredTemplateId > 0 && (int) $template->id === $firstRequiredTemplateId;
            $isEstimatedDeliveryTask = (string) ($template->automation_key ?? '') === 'PROD_SET_ESTIMATED_DELIVERY';
            $estimatedDeliveryAlreadySet = $isEstimatedDeliveryTask && filled($job->estimated_delivery_date);
            $defaultStatus = ($isPast || $estimatedDeliveryAlreadySet)
                ? $completedStatus
                : ($isCurrent && $isFirstRequired ? $readyStatus : $notStartedStatus);
            $defaultStatusId = $orderTaskRules->statusRecord($defaultStatus, false)?->id;
            $task = Task::firstOrCreate([
                'flow_job_id' => $job->id,
                'workflow_phase_id' => $phase->id,
                'task_pack_task_id' => $template->id,
            ], [
                'task_number' => 'TSK-'.str_pad((string) $nextTaskNumber++, 5, '0', STR_PAD_LEFT),
                'assignee_id' => $assigneeId,
                'setup_assignee_id' => $assigneeId,
                'document_category_id' => $template->document_category_id,
                'document_requirement_source' => $template->document_category_id ? 'task_pack' : null,
                'title' => $template->title,
                'description' => $template->description,
                'status' => $defaultStatus,
                'order_task_status_id' => $defaultStatusId,
                'priority' => $priority,
                'progress' => ($isPast || $estimatedDeliveryAlreadySet) ? 100 : 0,
                'start_date' => $isPast || $isCurrent || $estimatedDeliveryAlreadySet ? app(WorkspaceSettingsService::class)->localToday() : null,
                'due_date' => $dueDate,
                'completed_at' => ($isPast || $estimatedDeliveryAlreadySet) ? now() : null,
            ]);

            $becameActive = $task->wasRecentlyCreated && $isCurrent && $isFirstRequired && ! $estimatedDeliveryAlreadySet;
            $changes = [];
            if ($estimatedDeliveryAlreadySet && ! $task->completed_at) {
                $changes['status'] = $completedStatus;
                $changes['order_task_status_id'] = $orderTaskRules->statusRecord($completedStatus, false)?->id;
                $changes['progress'] = 100;
                $changes['completed_at'] = now();
                $changes['start_date'] = $task->start_date ?: app(WorkspaceSettingsService::class)->localToday();
            }
            if ($isCurrent && $isFirstRequired && ! $estimatedDeliveryAlreadySet && \App\Support\BoardLaneResolver::isNotStarted((string) $task->status)) {
                $becameActive = true;
                $changes['status'] = $readyStatus;
                $changes['order_task_status_id'] = $orderTaskRules->statusRecord($readyStatus, false)?->id;
                $changes['due_date'] = $task->due_date ?: $dueDate;
            }
            // Task Pack assignment is the source of truth for generated tasks.
            // Existing tasks created by the old coordinator fallback are corrected
            // when the Task Pack now has an explicit default assignee. A manual
            // reassignment is preserved because its assignee differs from the
            // stored setup_assignee_id.
            // Store the resolved Task Pack assignee (explicit user first,
            // otherwise the Task Pack's default department resolution) so the
            // same initial owner is shown everywhere in the system.
            $templateAssigneeId = $assigneeId ? (int) $assigneeId : null;
            $previousSetupAssigneeId = $task->setup_assignee_id ? (int) $task->setup_assignee_id : null;
            $followsSetup = !$task->assignee_id
                || ($previousSetupAssigneeId && (int) $task->assignee_id === $previousSetupAssigneeId)
                || (!$previousSetupAssigneeId && (int) $task->assignee_id === (int) ($job->coordinator_id ?: 0));

            if ($followsSetup && (int) ($task->assignee_id ?: 0) !== (int) ($templateAssigneeId ?: 0)) {
                $changes['assignee_id'] = $templateAssigneeId;
            }
            if ($previousSetupAssigneeId !== $templateAssigneeId) {
                $changes['setup_assignee_id'] = $templateAssigneeId;
            }
            if ((int) ($task->document_category_id ?: 0) !== (int) ($template->document_category_id ?: 0)) {
                $changes['document_category_id'] = $template->document_category_id ?: null;
                $changes['document_requirement_source'] = $template->document_category_id ? 'task_pack' : null;
            }
            if (!$task->description && $template->description) $changes['description'] = $template->description;
            if ($isCurrent && !$task->start_date) $changes['start_date'] = app(WorkspaceSettingsService::class)->localToday();
            if ($changes) $task->update($changes);
            // A Task Pack phase is a batch. Persist each task's own flag/status,
            // but defer the expensive parent Order rescan until all tasks in
            // this phase have reached their final generated state.
            $task = $orderTaskRules->syncTask($task->refresh(), false);

            FlowTaskComment::firstOrCreate(['flow_task_id' => $task->id, 'body' => 'Task created from the configured phase Task Pack.'], ['user_id' => $job->coordinator_id]);
            if ($task->assignee_id) {
                FlowJobMember::firstOrCreate(['flow_job_id' => $job->id, 'user_id' => $task->assignee_id], ['access_level' => 'member', 'can_manage_tasks' => false, 'can_upload_documents' => true, 'can_view_financials' => false]);
            }
            if ($actor && $becameActive && $task->assignee_id) {
                $taskId = $task->id;
                DB::afterCommit(function () use ($taskId, $actor) {
                    $freshTask = Task::with(['job','phase','assignee'])->find($taskId);
                    if ($freshTask) app(NotificationService::class)->notifyTaskAssigned($freshTask, $actor);
                });
            }
        }

        // Preserve the existing phase-level ordering: parent state is synced
        // before current-phase sequence normalization, but only once for the
        // completed phase batch instead of once per generated task.
        $freshJob = $job->fresh();
        $orderTaskRules->syncJob($freshJob);
        $this->syncAutomaticStatus($freshJob);

        if ($isCurrent) {
            app(OrderTaskSequenceService::class)->synchronizePhase($job->refresh(), $phase, $actor);
        }
    }


    private function restoreLegacyTaskPackDocumentRequirement(WorkflowPhase $phase): bool
    {
        if (!$phase->task_pack_id || !Schema::hasTable('task_pack_items')) return false;

        $items = TaskPackItem::query()
            ->where('task_pack_id', $phase->task_pack_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        if ($items->isEmpty() || $items->contains(fn ($item) => filled($item->document_category_id))) return false;

        $documentCategoryId = app(TaskPackService::class)->resolveLegacyDocumentCategoryId(
            $phase->document_category_id ?? null,
            Schema::hasColumn('workflow_phases', 'required_document') ? ($phase->required_document ?? null) : null
        );
        if (!$documentCategoryId) return false;

        $document = MasterRecord::query()
            ->whereKey($documentCategoryId)
            ->where('type', 'document_category')
            ->first();
        if (!$document) return false;

        $keywords = collect(preg_split('/[^a-z0-9]+/i', strtolower($document->name)))
            ->filter(fn ($word) => strlen($word) >= 3 && !in_array($word, ['document','file','required'], true))
            ->values();

        $ranked = $items
            ->map(function ($item) use ($keywords) {
                $title = strtolower((string) $item->title);
                $score = $item->is_required ? 2 : 0;
                foreach ($keywords as $word) if (str_contains($title, $word)) $score += 8;
                foreach (['upload','document','file','attach','submit','quotation','invoice','approval','confirmation','order','po'] as $hint) {
                    if (str_contains($title, $hint)) $score += 2;
                }
                return ['item' => $item, 'score' => $score];
            })
            ->sortByDesc(fn ($row) => $row['score']);
        $candidate = data_get($ranked->first(), 'item') ?: $items->first();

        if (!$candidate) return false;

        $candidate->update(['document_category_id' => $document->id]);
        Task::query()->where('task_pack_task_id', $candidate->id)->update([
            'document_category_id' => $document->id,
            'document_requirement_source' => 'task_pack',
        ]);

        return true;
    }

    private function assertEditable(FlowJob $job, User $actor): void
    {
        abort_unless(app(AccessControlService::class)->canEditJob($actor, $job), 403);
    }

    private function assertStatusEditable(FlowJob $job, User $actor): void
    {
        abort_unless(
            app(AccessControlService::class)->canChangeJobStatus($actor, $job),
            403,
            'Only the assigned Job owner or an Admin/Super Admin can change the Job status.'
        );
    }

    private function ensureMembers(FlowJob $job): void
    {
        foreach (collect([$job->owner_id, $job->coordinator_id])->filter()->unique() as $memberId) {
            FlowJobMember::updateOrCreate(
                ['flow_job_id' => $job->id, 'user_id' => $memberId],
                ['access_level' => $memberId === $job->owner_id ? 'lead' : 'member', 'can_manage_tasks' => $memberId === $job->owner_id, 'can_upload_documents' => true, 'can_view_financials' => $memberId === $job->owner_id]
            );
        }
    }
}
