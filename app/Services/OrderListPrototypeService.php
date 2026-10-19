<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Support\OrderArtworkListState;
use App\Support\OrderDetailPresenter;
use App\Support\OrderStageResolver;
use App\Support\MasterColor;
use App\Support\UserLocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Query and presentation support for the prototype-faithful Orders list.
 *
 * The service keeps the Livewire component thin, performs fixed-count aggregate
 * queries instead of per-order lookups, and eagerly loads every relation used
 * by the Blade view so rendering cannot introduce N+1 queries.
 */
class OrderListPrototypeService
{
    private const STAGE_DEFINITION_CACHE_TTL_MINUTES = 10;
    private const STAGE_COUNT_CACHE_TTL_SECONDS = 15;
    private const STAGE_PHASE_MAP_CACHE_TTL_MINUTES = 10;

    public const QUICK_FILTERS = [
        1 => ['all' => 'All', 'po_pending' => 'PO pending', 'po_uploaded' => 'PO uploaded'],
        2 => ['all' => 'All', 'review' => 'Internal review', 'revision' => 'Revision required', 'client' => 'Client approval'],
        3 => ['all' => 'All', 'ongoing' => 'Ongoing', 'issue' => 'Issue reported'],
        4 => ['all' => 'All', 'awaiting' => 'Awaiting QC', 'issue' => 'Issue reported', 'passed' => 'QC passed'],
        5 => ['all' => 'All', 'label' => 'Label pending', 'ready' => 'Ready to ship', 'shipped' => 'Shipped'],
        6 => ['all' => 'All', 'pending' => 'Pending', 'prepared' => 'Prepared', 'sent' => 'Sent'],
        7 => ['all' => 'All', 'awaiting' => 'Awaiting payment', 'partial' => 'Partially paid', 'overdue' => 'Overdue'],
    ];

    /**
     * Prototype filter colors. These colors are used only on the selected
     * stage view so the checkbox filter and its matching rows share the same
     * visual language. The general all-stage list keeps its existing active
     * task color behavior.
     *
     * @return array<string,array{label:string,color:string}>
     */
    public static function quickFilterMeta(int $sequence): array
    {
        $colors = [
            1 => ['all' => '#0F8F7C', 'po_pending' => '#D97706', 'po_uploaded' => '#159A68'],
            2 => ['all' => '#0F8F7C', 'review' => '#2D8CF0', 'revision' => '#EF476F', 'client' => '#8B5CF6'],
            3 => ['all' => '#0F8F7C', 'ongoing' => '#2D8CF0', 'issue' => '#EF476F'],
            4 => ['all' => '#0F8F7C', 'awaiting' => '#2D8CF0', 'issue' => '#EF476F', 'passed' => '#159A68'],
            5 => ['all' => '#0F8F7C', 'label' => '#D97706', 'ready' => '#2D8CF0', 'shipped' => '#159A68'],
            6 => ['all' => '#0F8F7C', 'pending' => '#D97706', 'prepared' => '#2D8CF0', 'sent' => '#159A68'],
            7 => ['all' => '#0F8F7C', 'awaiting' => '#2D8CF0', 'partial' => '#D97706', 'overdue' => '#EF476F'],
        ];

        $labels = self::QUICK_FILTERS[$sequence] ?? ['all' => 'All'];

        return collect($labels)
            ->mapWithKeys(fn (string $label, string $key) => [
                $key => [
                    'label' => $label,
                    'color' => (string) data_get($colors, $sequence.'.'.$key, '#0F8F7C'),
                ],
            ])
            ->all();
    }

    /** @return Collection<int,array{id:int,name:string,short_name:string,sequence:int,color:string,count:int}> */
    public function stages(User $user, bool $myTasksOnly = false): Collection
    {
        $workspaceId = app(SetupContext::class)->workspaceId();
        $key = implode(':', [
            'flowtrack', 'orders', 'stage-counts', 'v2',
            'workspace', max(1, $workspaceId),
            'user', (int) $user->id,
            $myTasksOnly ? 'my-tasks' : 'all',
        ]);

        return collect(Cache::remember(
            $key,
            now()->addSeconds(self::STAGE_COUNT_CACHE_TTL_SECONDS),
            fn (): array => $this->buildStages($user, $myTasksOnly)->values()->all(),
        ));
    }

    /**
     * Recalculate workflow-stage cards only when a list-wide filter can change
     * their population. The phase itself is intentionally excluded because the
     * cards are the phase facet; keeping all seven counts visible lets users
     * move between stages without losing the surrounding filtered context.
     *
     * @return Collection<int,array{id:int,name:string,short_name:string,sequence:int,color:string,count:int}>
     */
    public function stagesForFilters(User $user, array $filters): Collection
    {
        $hasListWideFilter = filled(trim((string) ($filters['search'] ?? '')))
            || $this->positiveInt($filters['client_id'] ?? null) !== null
            || $this->positiveInt($filters['owner_id'] ?? null) !== null
            || (bool) ($filters['hold_on'] ?? false)
            || filled(trim((string) ($filters['metric'] ?? '')))
            || filled(trim((string) ($filters['date_from'] ?? '')))
            || filled(trim((string) ($filters['date_to'] ?? '')))
            || (bool) ($filters['dashboard_scope'] ?? false)
            || $this->positiveInt($filters['dashboard_team_id'] ?? null) !== null
            || $this->positiveInt($filters['import_id'] ?? null) !== null;

        if (! $hasListWideFilter) {
            return $this->stages($user);
        }

        $countFilters = $filters;
        $countFilters['phase_id'] = null;
        $query = $this->buildListQuery($user, $countFilters, collect());

        return $this->stageCardsFromCountQuery($query);
    }

    /**
     * Count completed Orders inside the currently-applied list context. This is
     * a single indexed COUNT query and does not hydrate Order rows or relations.
     */
    public function completedCount(User $user, array $filters, Collection $stages): int
    {
        $countFilters = $filters;
        $countFilters['metric'] = 'completed';

        return (int) $this->buildListQuery($user, $countFilters, $stages)
            ->reorder()
            ->count('flow_jobs.id');
    }

    /**
     * Dashboard workflow cards use the same canonical seven-stage contract as
     * the Orders page, but their counts must respect the dashboard's global
     * Today / 7 days / 30 days, Client and Team filters.
     *
     * @return Collection<int,array{id:int,name:string,short_name:string,sequence:int,color:string,count:int}>
     */
    public function dashboardStages(
        User $user,
        int $clientId = 0,
        int $departmentId = 0,
        int $rangeDays = 7,
    ): Collection {
        $rangeDays = in_array($rangeDays, [1, 7, 30], true) ? $rangeDays : 7;
        $today = app(WorkspaceSettingsService::class)->localToday();

        return $this->dashboardScopedStages(
            $user,
            $today->copy()->subDays($rangeDays - 1)->toDateString(),
            $today->toDateString(),
            $clientId,
            $departmentId,
        );
    }

    /**
     * Exact Dashboard-scope stage counts for the Orders page.
     *
     * Dashboard deep links carry explicit local dates. The Orders table already
     * uses those exact dates, so its stage cards must use the same date/client/
     * team population rather than the normal unfiltered Orders totals.
     *
     * @return Collection<int,array{id:int,name:string,short_name:string,sequence:int,color:string,count:int}>
     */
    public function dashboardScopedStages(
        User $user,
        string $dateFrom,
        string $dateTo,
        int $clientId = 0,
        int $departmentId = 0,
    ): Collection {
        $clientId = max(0, $clientId);
        $departmentId = max(0, $departmentId);
        [$rangeFrom, $rangeTo] = app(WorkspaceSettingsService::class)
            ->localDateRangeUtcBounds($dateFrom, $dateTo);

        // A malformed/partial dashboard scope should never produce misleading
        // card totals. The Livewire page normally disables dashboard scope in
        // this case; keep the service safe for direct callers as well.
        if ($rangeFrom === null || $rangeTo === null) {
            return $this->stages($user);
        }

        $workspaceId = app(SetupContext::class)->workspaceId();
        $key = implode(':', [
            'flowtrack', 'dashboard', 'stage-counts', 'v3',
            'workspace', max(1, $workspaceId),
            'user', (int) $user->id,
            'from', $dateFrom,
            'to', $dateTo,
            'client', $clientId,
            'team', $departmentId,
        ]);

        return collect(Cache::remember(
            $key,
            now()->addSeconds(self::STAGE_COUNT_CACHE_TTL_SECONDS),
            function () use ($user, $clientId, $departmentId, $rangeFrom, $rangeTo): array {
                return $this->buildStages(
                    $user,
                    false,
                    function (Builder $query) use ($clientId, $departmentId, $rangeFrom, $rangeTo): void {
                        $this->applyDashboardStageCountScope(
                            $query,
                            $clientId,
                            $departmentId,
                            $rangeFrom,
                            $rangeTo,
                        );
                    },
                )->values()->all();
            },
        ));
    }

    private function applyDashboardStageCountScope(
        Builder $query,
        int $clientId,
        int $departmentId,
        $rangeFrom,
        $rangeTo,
    ): void {
        $query
            ->whereHas('client', fn (Builder $client) => $client->where('clients.is_active', true))
            ->whereBetween('flow_jobs.updated_at', [$rangeFrom, $rangeTo])
            ->when($clientId > 0, fn (Builder $orders) => $orders->where('flow_jobs.client_id', $clientId))
            ->when(
                $departmentId > 0,
                fn (Builder $orders) => $orders->whereHas(
                    'owner',
                    fn (Builder $owner) => $owner->where('users.department_id', $departmentId),
                ),
            );
    }

    /**
     * @param null|callable(Builder):void $countScope
     * @return Collection<int,array{id:int,name:string,short_name:string,sequence:int,color:string,count:int}>
     */
    private function buildStages(User $user, bool $myTasksOnly = false, ?callable $countScope = null): Collection
    {
        // Count by the canonical seven-stage runtime contract instead of by
        // raw workflow phase IDs. Historical Orders can still reference retired
        // rows such as "Order Intake"; those rows must count under New Order
        // without forcing an expensive/destructive workflow rewrite on list load.
        $countQuery = app(JobService::class)
            ->visibleQuery($user)
            ->whereNull('flow_jobs.completed_at')
            ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES);

        if ($myTasksOnly) {
            $countQuery->whereIn(
                'flow_jobs.id',
                app(MyWorkService::class)->personalOpenOrderIdsQuery($user),
            );
        }

        if ($countScope !== null) {
            $countScope($countQuery);
        }

        return $this->stageCardsFromCountQuery($countQuery);
    }

    /** @return Collection<int,array{id:int,name:string,short_name:string,sequence:int,color:string,count:int}> */
    private function stageCardsFromCountQuery(Builder $countQuery): Collection
    {
        $rawCounts = $countQuery
            ->leftJoin('workflow_phases as order_list_count_phases', 'order_list_count_phases.id', '=', 'flow_jobs.workflow_phase_id')
            ->reorder()
            ->select([
                'flow_jobs.workflow_phase_id',
                'flow_jobs.status',
                'order_list_count_phases.name as phase_name',
                'order_list_count_phases.short_name as phase_short_name',
                'order_list_count_phases.sequence as phase_sequence',
            ])
            ->selectRaw('COUNT(flow_jobs.id) as aggregate')
            ->groupBy([
                'flow_jobs.workflow_phase_id',
                'flow_jobs.status',
                'order_list_count_phases.name',
                'order_list_count_phases.short_name',
                'order_list_count_phases.sequence',
            ])
            ->get();

        $countBySequence = $rawCounts
            ->groupBy(fn ($row) => OrderStageResolver::resolve(
                $row->phase_name,
                $row->phase_short_name,
                $row->phase_sequence !== null ? (int) $row->phase_sequence : null,
                $row->status,
            )['sequence'])
            ->map(fn (Collection $rows): int => (int) $rows->sum(fn ($row) => (int) $row->aggregate));

        $preferredBySequence = $this->cachedStageDefinitions()->keyBy('sequence');

        return collect(OrderWorkflowSetupService::fixedStages())->values()->map(function (array $fixed, int $index) use ($preferredBySequence, $countBySequence): array {
            $sequence = $index + 1;
            $phase = $preferredBySequence->get($sequence);
            return [
                // The list filters by logical stage sequence so multiple Order
                // workflow templates can share the same seven operational cards.
                'id' => $sequence,
                'name' => (string) $fixed['name'],
                'short_name' => (string) (($phase['short_name'] ?? '') ?: $fixed['short']),
                'sequence' => $sequence,
                'color' => (string) (($phase['color'] ?? '') ?: $fixed['color']),
                'count' => (int) ($countBySequence[$sequence] ?? 0),
            ];
        });
    }

    public static function stageDefinitionCacheKey(int $workspaceId): string
    {
        return 'flowtrack:orders:stage-definitions:v1:workspace:'.max(1, $workspaceId);
    }

    /**
     * Cache only stable workflow presentation metadata. Live Order/task counts
     * remain outside this cache and are recalculated for every authorized read.
     *
     * @return Collection<int,array{id:int,name:string,short_name:string,sequence:int,color:string}>
     */
    private function cachedStageDefinitions(): Collection
    {
        $workspaceId = app(WorkflowService::class)->workspaceId();
        $key = self::stageDefinitionCacheKey($workspaceId);
        $expiresAt = now()->addMinutes(self::STAGE_DEFINITION_CACHE_TTL_MINUTES);

        $resolver = function (): array {
            $preferredWorkflowId = OrderWorkflowSetupService::orderWorkflowQuery()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->value('id');

            if (! $preferredWorkflowId) {
                return [];
            }

            return WorkflowPhase::query()
                ->where('workflow_template_id', (int) $preferredWorkflowId)
                ->where('is_active', true)
                ->whereBetween('sequence', [1, count(OrderWorkflowSetupService::fixedStages())])
                ->orderBy('sequence')
                ->get(['id', 'name', 'short_name', 'sequence', 'color'])
                ->map(fn (WorkflowPhase $phase): array => [
                    'id' => (int) $phase->id,
                    'name' => (string) $phase->name,
                    'short_name' => (string) ($phase->short_name ?: $phase->name),
                    'sequence' => (int) $phase->sequence,
                    'color' => (string) ($phase->color ?: ''),
                ])
                ->values()
                ->all();
        };

        $rows = Cache::remember($key, $expiresAt, $resolver);

        // Defend against stale/legacy cache payloads after deployment. Laravel
        // may keep an old cache store across releases, so validate scalars before
        // letting cached data influence the Orders stage UI.
        if (! $this->validStageDefinitionRows($rows)) {
            Cache::forget($key);
            $rows = $resolver();
            Cache::put($key, $rows, $expiresAt);
        }

        return collect($rows);
    }

    private function validStageDefinitionRows(mixed $rows): bool
    {
        if (! is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }

            foreach (['id', 'name', 'short_name', 'sequence', 'color'] as $field) {
                if (! array_key_exists($field, $row)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function buildListQuery(User $user, array $filters, Collection $stages, bool $myTasksOnly = false): Builder
    {
        $stageId = (int) ($filters['phase_id'] ?? 0);
        $sequence = (int) ($stages->firstWhere('id', $stageId)['sequence'] ?? 0);
        $phaseIds = $sequence > 0 ? $this->orderPhaseIdsForSequence($sequence) : [];

        $dashboardScope = (bool) ($filters['dashboard_scope'] ?? false);
        $dateFrom = (string) ($filters['date_from'] ?? '');
        $dateTo = (string) ($filters['date_to'] ?? '');

        $query = app(JobService::class)->ordersListQuery(
            $user,
            (string) ($filters['search'] ?? ''),
            $this->positiveInt($filters['client_id'] ?? null),
            null,
            null,
            $this->positiveInt($filters['owner_id'] ?? null),
            (string) ($filters['metric'] ?? ''),
            $dashboardScope ? '' : $dateFrom,
            $dashboardScope ? '' : $dateTo,
            $this->positiveInt($filters['import_id'] ?? null),
        );

        if ($dashboardScope) {
            [$rangeFrom, $rangeTo] = app(WorkspaceSettingsService::class)
                ->localDateRangeUtcBounds($dateFrom, $dateTo);
            $dashboardTeamId = $this->positiveInt($filters['dashboard_team_id'] ?? null);

            // Match Dashboard::dashboardStages() exactly so a card showing N
            // orders opens a table containing the same N-order population before
            // pagination: active-client operational orders touched in the selected
            // local period, optionally limited to the selected owner's Team.
            $query
                ->whereHas('client', fn (Builder $client) => $client->where('clients.is_active', true))
                ->when($rangeFrom, fn (Builder $orders) => $orders->where('flow_jobs.updated_at', '>=', $rangeFrom))
                ->when($rangeTo, fn (Builder $orders) => $orders->where('flow_jobs.updated_at', '<=', $rangeTo))
                ->when(
                    $dashboardTeamId,
                    fn (Builder $orders) => $orders->whereHas(
                        'owner',
                        fn (Builder $owner) => $owner->where('users.department_id', $dashboardTeamId),
                    ),
                );
        }

        if ($myTasksOnly) {
            $query->whereIn(
                'flow_jobs.id',
                app(MyWorkService::class)->personalOpenOrderIdsQuery($user),
            );
        }

        if ((bool) ($filters['hold_on'] ?? false)) {
            $query->whereHas('activeHold');
        }

        if ($sequence > 0) {
            // The stage cards/counts are resolved from the Order's *current runtime*
            // workflow_phase_id, so the table filter must use the same source of truth.
            // source_workflow_phase_id is provenance for snapshot/template mapping and
            // can legitimately differ on older/rebound Orders. Using it here allowed a
            // Shipment Order to leak into Billing even while its live Shipment tasks
            // were still incomplete.
            if ($phaseIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('flow_jobs.workflow_phase_id', $phaseIds);
            }
        }

        // The supplied prototype is an operational queue. Completed Orders are
        // kept in history/detail screens rather than mixed into the seven active
        // stage cards. Dashboard links to completed-this-week remain supported.
        if (! in_array((string) ($filters['metric'] ?? ''), ['completedThisWeek', 'completed'], true)) {
            $query->whereNull('flow_jobs.completed_at');
        }

        $this->applyStageSpecificFilters($query, $phaseIds, $sequence, $filters);

        return $query;
    }

    public function paginate(User $user, array $filters, Collection $stages, int $perPage = 10, bool $myTasksOnly = false): LengthAwarePaginator
    {
        $query = $this->buildListQuery($user, $filters, $stages, $myTasksOnly);

        return $query
            ->select([
                'flow_jobs.id', 'flow_jobs.job_number', 'flow_jobs.order_number', 'flow_jobs.client_id',
                'flow_jobs.workflow_phase_id', 'flow_jobs.source_workflow_phase_id', 'flow_jobs.owner_id',
                'flow_jobs.title', 'flow_jobs.product', 'flow_jobs.quantity', 'flow_jobs.status',
                'flow_jobs.progress', 'flow_jobs.delivery_date', 'flow_jobs.estimated_delivery_date',
                'flow_jobs.shipment_urgency_ids', 'flow_jobs.commercial_value', 'flow_jobs.currency',
                'flow_jobs.attention_requested', 'flow_jobs.attention_reason', 'flow_jobs.order_flag_id',
                'flow_jobs.completed_at', 'flow_jobs.created_at',
            ])
            ->with([
                'client:id,code,name,logo_path',
                'phase:id,name,short_name,sequence,color',
                'owner:id,name,profile_image_path',
                'orderFlag:id,name,color',
                'activeHold:id,flow_job_id,hold_from',
                'items' => fn ($items) => $items
                    ->select(['id','flow_job_id','supplier_id','product_name','category_name','quantity','unit_price','is_removed','sort_order'])
                    ->with('supplier:id,name,metadata,type'),
                'tasks' => fn ($tasks) => $tasks
                    ->select(['id','flow_job_id','workflow_phase_id','assignee_id','task_pack_task_id','title','status','progress','due_date','completed_at'])
                    ->with([
                        'assignee:id,name,profile_image_path',
                        'setupTemplate:id,task_pack_id,source_task_pack_item_id,title,is_required,sort_order,automation_key,color',
                        'setupTemplate.sourceItem:id,color',
                        'template:id,task_pack_id,title,is_required,sequence,color',
                        'documents:id,flow_job_id,task_id,name,path,uploaded_by,created_at',
                    ])
                    ->orderBy('workflow_phase_id')
                    ->orderBy('id'),
                'invoices' => fn ($invoices) => $invoices
                    ->select(['id','flow_job_id','invoice_number','issue_date','due_date','status','total'])
                    ->with(['payments:id,flow_job_id,invoice_id,amount,payment_date'])
                    ->orderByDesc('issue_date')->orderByDesc('id'),
                // latestOfMany() joins activities back to an aggregate subquery that
                // also exposes subject_type/subject_id. Qualify every selected activity
                // column so MySQL cannot treat those morph columns as ambiguous.
                'latestShipmentActivity' => fn ($activity) => $activity->select([
                    'activities.id', 'activities.subject_type', 'activities.subject_id',
                    'activities.event', 'activities.description', 'activities.meta', 'activities.created_at',
                ]),
                'latestWorkflowInvoiceActivity' => fn ($activity) => $activity->select([
                    'activities.id', 'activities.subject_type', 'activities.subject_id',
                    'activities.event', 'activities.description', 'activities.meta', 'activities.created_at',
                ]),
                'latestQcActivity' => fn ($activity) => $activity->select([
                    'activities.id', 'activities.subject_type', 'activities.subject_id',
                    'activities.event', 'activities.description', 'activities.meta', 'activities.created_at',
                ]),
                'latestArtworkRevisionActivity' => fn ($activity) => $activity->select([
                    'activities.id', 'activities.subject_type', 'activities.subject_id',
                    'activities.event', 'activities.description', 'activities.meta', 'activities.created_at',
                ]),
            ])
            ->paginate(max(1, min($perPage, 50)));
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(LengthAwarePaginator $jobs, Collection $urgencyOptions): array
    {
        return collect($jobs->items())
            ->mapWithKeys(fn (FlowJob $job) => [(int) $job->id => $this->present($job, $urgencyOptions)])
            ->all();
    }

    public function supplierOptions(): Collection
    {
        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('supplier')
            ->active()
            ->orderBy('name')
            ->limit(100)
            ->get(['id','name']);
    }

    public function urgencyOptions(): Collection
    {
        return MasterRecord::query()
            ->forWorkspace(app(SetupContext::class)->workspaceId())
            ->ofType('shipment_urgency')
            ->active()
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id','name']);
    }

    private function applyStageSpecificFilters(Builder $query, array $phaseIds, int $sequence, array $filters): void
    {
        if ($phaseIds === [] || $sequence < 1) return;

        $supplierId = $this->positiveInt($filters['stage_supplier_id'] ?? null);
        $assigneeId = $this->positiveInt($filters['stage_assignee_id'] ?? null);
        $clientId = $this->positiveInt($filters['stage_client_id'] ?? null);
        $urgencyId = $this->positiveInt($filters['stage_urgency_id'] ?? null);
        $carrier = trim((string) ($filters['stage_carrier'] ?? ''));
        $quick = (string) ($filters['stage_quick'] ?? 'all');

        if ($supplierId) {
            $query->whereHas('items', fn (Builder $items) => $items
                ->where('is_removed', false)
                ->where('supplier_id', $supplierId));
        }

        if ($clientId) $query->where('flow_jobs.client_id', $clientId);

        if ($assigneeId) {
            if ($sequence === 1) {
                $query->where('flow_jobs.owner_id', $assigneeId);
            } else {
                $query->whereHas('tasks', fn (Builder $tasks) => $tasks
                    ->whereIn('workflow_phase_id', $phaseIds)
                    ->where('assignee_id', $assigneeId));
            }
        }

        if ($urgencyId) $query->whereJsonContains('flow_jobs.shipment_urgency_ids', $urgencyId);

        if ($carrier !== '') {
            $query->whereHas('activities', fn (Builder $activities) => $activities
                ->where('event', 'job.package_shipped')
                ->where('meta->carrier', $carrier));
        }

        if ($quick === '' || $quick === 'all') return;

        match ($sequence) {
            1 => $this->applyNewOrderQuick($query, $phaseIds, $quick),
            2 => $this->applyArtworkQuick($query, $phaseIds, $quick),
            3 => $this->applyIssueQuick($query, $phaseIds, $quick, 'PROD_ISSUE'),
            4 => $this->applyQcQuick($query, $phaseIds, $quick),
            5 => $this->applyShipmentQuick($query, $phaseIds, $quick),
            6 => $this->applyBillingQuick($query, $phaseIds, $quick),
            7 => $this->applyPaymentQuick($query, $phaseIds, $quick),
            default => null,
        };
    }

    private function applyNewOrderQuick(Builder $query, array $phaseIds, string $quick): void
    {
        // New Order row state is evidence-driven: the Purchase Order is
        // considered uploaded only when the Upload Purchase Order task has at
        // least one linked document. Task completion alone must not color the
        // row green or place it in the PO uploaded bucket.
        if ($quick === 'po_pending') $this->wherePurchaseOrderDocument($query, $phaseIds, false);
        if ($quick === 'po_uploaded') $this->wherePurchaseOrderDocument($query, $phaseIds, true);
    }

    private function wherePurchaseOrderDocument(Builder $query, array $phaseIds, bool $uploaded): void
    {
        $scope = function (Builder $tasks) use ($phaseIds): void {
            $tasks->whereIn('workflow_phase_id', $phaseIds)
                ->where(function (Builder $purchaseOrderTask): void {
                    $purchaseOrderTask
                        ->whereHas('setupTemplate', fn (Builder $setup) => $setup->where('automation_key', 'NEW_UPLOAD_PO'))
                        ->orWhereRaw('LOWER(TRIM(tasks.title)) = ?', ['upload purchase order']);
                })
                ->whereHas('documents');
        };

        $uploaded
            ? $query->whereHas('tasks', $scope)
            : $query->whereDoesntHave('tasks', $scope);
    }

    private function applyArtworkQuick(Builder $query, array $phaseIds, string $quick): void
    {
        // CHANGE 2026-08-24: the prototype artwork checkboxes are mutually
        // meaningful. A revision is detected from the real revision activity,
        // because restartArtwork() resets the upload task to Ready rather than
        // storing "Revision" in the Order/task status.
        if ($quick === 'revision') {
            $this->whereOutstandingArtworkRevision($query);
            return;
        }

        if ($quick === 'review') {
            $this->whereTask($query, $phaseIds, 'ART_INTERNAL_REVIEW', false);
            $this->whereNoOutstandingArtworkRevision($query);
            return;
        }

        if ($quick === 'client') {
            // Once internal review is complete the Order is in the
            // client/sample decision part of Artwork. Sample approval remains
            // inside this prototype bucket, matching the CLIENT / SAMPLE cell.
            $this->whereTask($query, $phaseIds, 'ART_INTERNAL_REVIEW', true);
            $this->whereNoOutstandingArtworkRevision($query);
        }
    }

    /** Restrict the query to Orders with a revision request not yet answered by a newer artwork upload. */
    private function whereOutstandingArtworkRevision(Builder $query): void
    {
        $query->whereHas('activities', function (Builder $activities): void {
            $activities
                ->where('activities.event', 'job.artwork_revision_requested')
                ->whereNotExists(function ($documents): void {
                    $documents
                        ->selectRaw('1')
                        ->from('documents as revision_artwork_documents')
                        ->join('tasks as revision_artwork_tasks', 'revision_artwork_tasks.id', '=', 'revision_artwork_documents.task_id')
                        ->leftJoin('task_pack_items as revision_artwork_setup', 'revision_artwork_setup.id', '=', 'revision_artwork_tasks.task_pack_task_id')
                        ->whereColumn('revision_artwork_documents.flow_job_id', 'flow_jobs.id')
                        ->whereColumn('revision_artwork_documents.created_at', '>', 'activities.created_at')
                        ->whereNull('revision_artwork_tasks.deleted_at')
                        ->where(function ($artworkTask): void {
                            $artworkTask
                                ->where('revision_artwork_setup.automation_key', 'ART_PREPARE_UPLOAD')
                                ->orWhereIn('revision_artwork_tasks.title', [
                                    'Prepare & Upload Artwork',
                                    'Prepare and Upload Artwork',
                                ]);
                        });
                });
        });
    }

    /** Exclude only currently outstanding revisions; resolved historic revisions stay in audit history. */
    private function whereNoOutstandingArtworkRevision(Builder $query): void
    {
        $query->whereDoesntHave('activities', function (Builder $activities): void {
            $activities
                ->where('activities.event', 'job.artwork_revision_requested')
                ->whereNotExists(function ($documents): void {
                    $documents
                        ->selectRaw('1')
                        ->from('documents as revision_artwork_documents')
                        ->join('tasks as revision_artwork_tasks', 'revision_artwork_tasks.id', '=', 'revision_artwork_documents.task_id')
                        ->leftJoin('task_pack_items as revision_artwork_setup', 'revision_artwork_setup.id', '=', 'revision_artwork_tasks.task_pack_task_id')
                        ->whereColumn('revision_artwork_documents.flow_job_id', 'flow_jobs.id')
                        ->whereColumn('revision_artwork_documents.created_at', '>', 'activities.created_at')
                        ->whereNull('revision_artwork_tasks.deleted_at')
                        ->where(function ($artworkTask): void {
                            $artworkTask
                                ->where('revision_artwork_setup.automation_key', 'ART_PREPARE_UPLOAD')
                                ->orWhereIn('revision_artwork_tasks.title', [
                                    'Prepare & Upload Artwork',
                                    'Prepare and Upload Artwork',
                                ]);
                        });
                });
        });
    }

    private function applyIssueQuick(Builder $query, array $phaseIds, string $quick, string $key): void
    {
        if ($quick === 'issue') {
            $query->whereHas('tasks', fn (Builder $tasks) => $tasks
                ->whereIn('workflow_phase_id', $phaseIds)
                ->whereHas('setupTemplate', fn (Builder $setup) => $setup->where('automation_key', $key))
                ->whereLike('status', '%Issue%'));
            return;
        }
        if ($quick === 'ongoing') {
            $query->whereDoesntHave('tasks', fn (Builder $tasks) => $tasks
                ->whereIn('workflow_phase_id', $phaseIds)
                ->whereLike('status', '%Issue%'));
        }
    }

    private function applyQcQuick(Builder $query, array $phaseIds, string $quick): void
    {
        if ($quick === 'issue') {
            $this->applyIssueQuick($query, $phaseIds, 'issue', 'QC_ISSUE');
            return;
        }
        if ($quick === 'awaiting') {
            $this->whereTask($query, $phaseIds, 'QC_CHECK', false);
            return;
        }
        if ($quick === 'passed') {
            $this->whereTask($query, $phaseIds, 'QC_CHECK', true);
        }
    }

    private function applyShipmentQuick(Builder $query, array $phaseIds, string $quick): void
    {
        if ($quick === 'label') $this->whereTask($query, $phaseIds, 'SHIP_LABEL', false);
        if ($quick === 'ready') $this->whereTask($query, $phaseIds, 'SHIP_PACKAGE', false);
        if ($quick === 'shipped') $this->whereTask($query, $phaseIds, 'SHIP_PACKAGE', true);
    }

    private function applyBillingQuick(Builder $query, array $phaseIds, string $quick): void
    {
        if ($quick === 'pending') $this->whereTask($query, $phaseIds, 'BILL_PREPARE', false);
        if ($quick === 'prepared') {
            $this->whereTask($query, $phaseIds, 'BILL_PREPARE', true);
            $this->whereTask($query, $phaseIds, 'BILL_SEND', false);
        }
        if ($quick === 'sent') $this->whereTask($query, $phaseIds, 'BILL_SEND', true);
    }

    private function applyPaymentQuick(Builder $query, array $phaseIds, string $quick): void
    {
        if ($quick === 'partial') {
            $query->whereHas('tasks', fn (Builder $tasks) => $tasks
                ->whereIn('workflow_phase_id', $phaseIds)
                ->whereLike('status', '%Partially Paid%'));
            return;
        }
        if ($quick === 'overdue') {
            $query->whereHas('invoices', fn (Builder $invoices) => $invoices
                ->whereDate('due_date', '<', app(WorkspaceSettingsService::class)->localToday())
                ->whereNotIn('status', ['paid','cancelled']));
            return;
        }
        if ($quick === 'awaiting') {
            $this->whereTask($query, $phaseIds, 'PAY_PROCESS', false);
            $query->whereDoesntHave('tasks', fn (Builder $tasks) => $tasks
                ->whereIn('workflow_phase_id', $phaseIds)
                ->whereLike('status', '%Partially Paid%'));
        }
    }

    private function whereTask(Builder $query, array $phaseIds, string $automationKey, bool $completed): void
    {
        $query->whereHas('tasks', function (Builder $tasks) use ($phaseIds, $automationKey, $completed): void {
            $tasks->whereIn('workflow_phase_id', $phaseIds)
                ->whereHas('setupTemplate', fn (Builder $setup) => $setup->where('automation_key', $automationKey));
            $completed ? $tasks->whereNotNull('completed_at') : $tasks->whereNull('completed_at');
        });
    }

    /** @return array<string,mixed> */
    private function present(FlowJob $job, Collection $urgencyOptions): array
    {
        $items = $job->items->filter(fn ($item) => ! (bool) $item->is_removed)->values();
        $productNames = $items->pluck('product_name')->filter()->values();
        $suppliers = $items->map(fn ($item) => (string) ($item->supplier?->supplierShortCode() ?: 'Not linked'))->filter()->unique()->values();
        $totalUnits = (int) $items->sum(fn ($item) => (int) ($item->quantity ?? 0));
        $phaseId = (int) ($job->workflow_phase_id ?: 0);
        $phaseTasks = $job->tasks->filter(fn (Task $task) => (int) $task->workflow_phase_id === $phaseId)->values();
        $nextTask = $this->nextTaskFromLoaded($phaseTasks);
        $stage = OrderStageResolver::resolve(
            $job->phase?->name,
            $job->phase?->short_name,
            $job->phase?->sequence,
            $job->status,
            $nextTask?->setupTemplate?->automation_key,
        );
        $stageSequence = (int) $stage['sequence'];
        $hasEvidence = $nextTask?->documents?->isNotEmpty() ?? false;
        $next = $nextTask ? app(OrderWorkflowActionService::class)->descriptor($nextTask, $hasEvidence) : ['label' => 'Open order'];
        $stageAssignee = $stageSequence === 1
            ? $job->owner
            : ($nextTask?->assignee ?: $phaseTasks->first(fn (Task $task) => $task->assignee)?->assignee ?: $job->owner);
        $stageDue = $nextTask?->due_date ?: $job->delivery_date;
        // Visual metadata follows the live source Task Pack color when this
        // Order uses a workflow snapshot. The snapshot still protects all
        // workflow/business rules; only the presentation color stays current.
        $activeTaskColor = MasterColor::normalize((string) (
            $nextTask?->setupTemplate?->sourceItem?->color
            ?? $nextTask?->setupTemplate?->color
            ?? $nextTask?->template?->color
            ?? ''
        ));

        $taskByKey = function (string $key) use ($phaseTasks): ?Task {
            $task = $phaseTasks->first(fn (Task $task) => (string) ($task->setupTemplate?->automation_key ?? '') === $key);

            // Keep legacy/snapshot Orders compatible when an automation key is
            // absent but the workflow task still has the canonical PO title.
            if (! $task && $key === 'NEW_UPLOAD_PO') {
                $task = $phaseTasks->first(fn (Task $task) =>
                    strtolower(trim((string) $task->title)) === 'upload purchase order'
                );
            }

            return $task;
        };
        $latestTaskDocument = function (string $key) use ($taskByKey) {
            $task = $taskByKey($key);
            return $task?->documents?->sortByDesc('id')->first();
        };

        $poDocument = $latestTaskDocument('NEW_UPLOAD_PO');

        $artworkListState = $stageSequence === 2
            ? OrderArtworkListState::resolve($phaseTasks, $nextTask, $job->latestArtworkRevisionActivity)
            : null;
        if ($artworkListState) {
            $activeTaskColor = MasterColor::normalize($artworkListState['color']);
        }

        // CHANGE 2026-08-24: when one workflow stage is selected, its rows use
        // the same semantic color as the phase-wise checkbox filter. New Order
        // is intentionally stricter: only real PO document evidence turns the
        // row green; without a file the row remains plain white. The same rule
        // also overrides the active-task tint in the all-stage view.
        $stageQuickKey = $this->resolveStageQuickKey($job, $phaseTasks, $artworkListState, $stageSequence);
        $stageQuickMeta = data_get(self::quickFilterMeta($stageSequence), $stageQuickKey, []);
        $stageQuickColor = MasterColor::normalize((string) data_get($stageQuickMeta, 'color', ''));

        if ($stageSequence === 1) {
            $purchaseOrderEvidenceColor = $poDocument ? '#159A68' : null;
            $activeTaskColor = $purchaseOrderEvidenceColor;
            $stageQuickColor = $purchaseOrderEvidenceColor;
        }
        $artTask = $taskByKey('ART_PREPARE_UPLOAD');
        $artDocument = $latestTaskDocument('ART_PREPARE_UPLOAD');
        $clientTask = $taskByKey('ART_CLIENT_ERP_DECISION');
        $sampleTask = $taskByKey('ART_SAMPLE_APPROVAL');
        $prodIssue = $taskByKey('PROD_ISSUE');
        $qcCheck = $taskByKey('QC_CHECK');
        $qcIssue = $taskByKey('QC_ISSUE');
        $labelTask = $taskByKey('SHIP_LABEL');
        $labelDocument = $latestTaskDocument('SHIP_LABEL');
        $shipTask = $taskByKey('SHIP_PACKAGE');
        $latestInvoice = $job->invoices->first();
        $invoicePaid = $latestInvoice ? (float) $latestInvoice->payments->sum('amount') : 0.0;
        $invoiceTotal = $latestInvoice ? (float) $latestInvoice->total : (float) ($job->commercial_value ?? 0);
        $shipment = $job->latestShipmentActivity;
        $workflowInvoice = $job->latestWorkflowInvoiceActivity;
        $qcActivity = $job->latestQcActivity;
        $urgencyName = OrderDetailPresenter::shipmentUrgencyName($job, $urgencyOptions);

        return [
            // CHANGE 2026-08-24: expose the real active workflow task so the
            // phase-wise Orders list can execute the exact same action as the
            // Order Details page without navigating away first.
            'order_id' => (int) $job->id,
            'next_task_id' => (int) ($nextTask?->id ?? 0),
            'order' => $job->displayOrderNumber(),
            'reference' => trim((string) ($job->order_number ?: '—')),
            'created' => $job->created_at ? UserLocalTime::format($job->created_at, 'Y-m-d') : '—',
            'client' => (string) ($job->client?->name ?: '—'),
            'client_code' => (string) ($job->client?->code ?: ''),
            'client_logo' => $job->client?->logo_path,
            'has_completed_task' => $job->tasks->contains(fn (Task $task): bool => filled($task->completed_at)),
            'title' => (string) ($job->title ?: $job->product ?: 'Order'),
            'product' => $items->count() === 1 ? (string) ($productNames->first() ?: 'Product') : ($items->count().' ordered products'),
            'product_detail' => $items->count() === 1 ? number_format($totalUnits).' pcs' : number_format($totalUnits).' pcs · '.$productNames->take(2)->implode(' · '),
            'supplier' => $suppliers->count() === 1 ? (string) $suppliers->first() : ($suppliers->count() > 1 ? $suppliers->count().' linked suppliers' : 'Not linked'),
            'quantity' => $totalUnits,
            'phase_name' => (string) $stage['name'],
            'phase_sequence' => $stageSequence,
            'phase_color' => (string) ($job->phase?->color ?: $stage['color']),
            'is_on_hold' => (bool) $job->activeHold,
            'status' => $job->activeHold ? 'On Hold' : (string) ($job->status ?: 'New'),
            'flag' => (string) ($job->orderFlag?->name ?: ($job->attention_requested ? 'Needs attention' : '')),
            'owner' => (string) ($job->owner?->name ?: 'Unassigned'),
            'owner_initials' => OrderDetailPresenter::initials($job->owner?->name),
            'owner_image' => $job->owner?->profile_image_path,
            'owner_avatar' => $job->owner?->profileImageUrl(),
            'delivery' => $job->delivery_date?->format('Y-m-d'),
            'progress' => max(0, min(100, (int) $job->progress)),
            'next_action' => (string) ($next['label'] ?? 'Open order'),
            'active_task_title' => (string) ($nextTask?->title ?: ''),
            'active_task_color' => $activeTaskColor,
            // CHANGE 2026-08-24: selected-stage row tint metadata.
            'stage_filter_key' => $stageQuickKey,
            'stage_filter_color' => $stageQuickColor,
            'artwork_step' => (string) data_get($artworkListState, 'key', ''),
            'artwork_step_label' => (string) data_get($artworkListState, 'label', ''),
            'stage_assignee' => (string) ($stageAssignee?->name ?: 'Unassigned'),
            'stage_assignee_initials' => OrderDetailPresenter::initials($stageAssignee?->name),
            'stage_assignee_avatar' => $stageAssignee?->profileImageUrl(),
            'stage_due' => $stageDue?->format('Y-m-d'),
            'po_status' => $poDocument ? 'PO Uploaded' : 'PO Pending',
            'po_document' => $poDocument,
            'art_version' => max(0, (int) ($artTask?->documents?->count() ?? 0)),
            'art_status' => (string) ($artTask?->status ?: $job->status),
            'art_document' => $artDocument,
            'client_approval' => (string) ($clientTask?->status ?: 'Pending'),
            'sample_status' => $sampleTask ? (OrderDetailPresenter::isSkippedTask($sampleTask) ? 'Not required' : (OrderDetailPresenter::isCompletedTask($sampleTask) ? 'Approved' : (OrderDetailPresenter::isConditionalTaskActivated($sampleTask) ? 'Required / pending' : 'Not decided'))) : 'Not decided',
            'production_status' => (string) ($prodIssue?->status ?: $job->status),
            'production_issue' => $prodIssue && str_contains(strtolower((string) $prodIssue->status), 'issue') ? (string) $prodIssue->status : 'No open issue',
            'qc_status' => (string) ($qcCheck?->status ?: $job->status),
            'qc_inspection' => sprintf('%s / %s', number_format((int) data_get($qcActivity?->meta, 'qty_inspected', 0)), number_format((int) (data_get($qcActivity?->meta, 'qty_received') ?: $totalUnits))),
            'qc_issue' => $qcIssue && str_contains(strtolower((string) $qcIssue->status), 'issue') ? (string) $qcIssue->status : 'None',
            'urgency' => $urgencyName,
            'urgency_tone' => OrderDetailPresenter::urgencyTone($urgencyName),
            'label_status' => (string) ($labelTask?->status ?: 'Label pending'),
            'label_document' => $labelDocument,
            'carrier' => (string) data_get($shipment?->meta, 'carrier', 'Not selected'),
            'tracking' => (string) data_get($shipment?->meta, 'tracking_number', 'Pending'),
            'invoice' => $latestInvoice,
            'invoice_number' => (string) ($latestInvoice?->invoice_number ?: data_get($workflowInvoice?->meta, 'invoice_number', 'Pending')),
            'invoice_amount' => $invoiceTotal > 0 ? $invoiceTotal : (float) data_get($workflowInvoice?->meta, 'invoice_amount', 0),
            'invoice_status' => (string) ($latestInvoice?->status ?: ($workflowInvoice ? 'Prepared' : 'Pending')),
            'invoice_due' => $latestInvoice?->due_date?->format('Y-m-d') ?: (string) data_get($workflowInvoice?->meta, 'invoice_due_date', ''),
            'paid_amount' => $invoicePaid,
            'balance' => max(0, $invoiceTotal - $invoicePaid),
            'payment_status' => $latestInvoice ? (string) $latestInvoice->status : (str_contains(strtolower((string) $job->status), 'partially') ? 'Partially Paid' : 'Awaiting Payment'),
        ];
    }

    /**
     * Resolve the checkbox bucket that describes the row while the Order is in
     * its current phase. It intentionally uses already-loaded relations only,
     * so row coloring introduces no extra database queries.
     */
    private function resolveStageQuickKey(FlowJob $job, Collection $phaseTasks, ?array $artworkListState, int $sequence): string
    {
        $taskByKey = fn (string $key): ?Task => $phaseTasks->first(
            fn (Task $task) => (string) ($task->setupTemplate?->automation_key ?? '') === $key
        );
        $completed = static fn (?Task $task): bool => $task ? OrderDetailPresenter::isCompletedTask($task) : false;
        $hasIssue = static fn (?Task $task): bool => $task
            ? str_contains(strtolower(trim((string) $task->status)), 'issue')
            : false;

        if ($sequence === 1) {
            $purchaseOrderTask = $taskByKey('NEW_UPLOAD_PO')
                ?: $phaseTasks->first(fn (Task $task) => strtolower(trim((string) $task->title)) === 'upload purchase order');

            return $purchaseOrderTask?->documents?->isNotEmpty() ? 'po_uploaded' : 'po_pending';
        }

        if ($sequence === 2) {
            return match ((string) data_get($artworkListState, 'key', '')) {
                OrderArtworkListState::REVISION_REQUIRED => 'revision',
                OrderArtworkListState::CLIENT_DECISION,
                OrderArtworkListState::CLIENT_APPROVED => 'client',
                default => 'review',
            };
        }

        if ($sequence === 3) {
            return $hasIssue($taskByKey('PROD_ISSUE')) ? 'issue' : 'ongoing';
        }

        if ($sequence === 4) {
            if ($hasIssue($taskByKey('QC_ISSUE'))) return 'issue';
            return $completed($taskByKey('QC_CHECK')) ? 'passed' : 'awaiting';
        }

        if ($sequence === 5) {
            if ($completed($taskByKey('SHIP_PACKAGE'))) return 'shipped';
            return $completed($taskByKey('SHIP_LABEL')) ? 'ready' : 'label';
        }

        if ($sequence === 6) {
            if ($completed($taskByKey('BILL_SEND'))) return 'sent';
            return $completed($taskByKey('BILL_PREPARE')) ? 'prepared' : 'pending';
        }

        if ($sequence === 7) {
            $invoice = $job->invoices->first();
            $today = app(WorkspaceSettingsService::class)->localToday();
            $isOverdue = $invoice?->due_date
                && $invoice->due_date->lt($today)
                && ! in_array(strtolower((string) $invoice->status), ['paid', 'cancelled'], true);
            if ($isOverdue) return 'overdue';

            $paymentTask = $taskByKey('PAY_PROCESS');
            $paymentStatus = strtolower(trim((string) ($paymentTask?->status ?? '')));
            $paid = $invoice ? (float) $invoice->payments->sum('amount') : 0.0;
            $total = $invoice ? (float) $invoice->total : 0.0;
            if (str_contains($paymentStatus, 'partial') || ($paid > 0 && $total > 0 && $paid < $total)) {
                return 'partial';
            }

            return 'awaiting';
        }

        return 'all';
    }

    private function nextTaskFromLoaded(Collection $tasks): ?Task
    {
        $tasks = $tasks
            ->sortBy(fn (Task $task) => [(int) ($task->setupTemplate?->sort_order ?? 999999), (int) $task->id])
            ->values();

        $required = $tasks->filter(fn (Task $task) => ($task->setupTemplate?->is_required ?? true) !== false);
        $requiredNext = $required->first(fn (Task $task) => ! OrderDetailPresenter::isCompletedTask($task) && ! OrderDetailPresenter::isSkippedTask($task));

        if ($requiredNext && strcasecmp(trim((string) $requiredNext->status), 'Waiting for Sample Approval') === 0) {
            $sample = $tasks->first(fn (Task $task) =>
                ! OrderDetailPresenter::isCompletedTask($task)
                && ! OrderDetailPresenter::isSkippedTask($task)
                && OrderDetailPresenter::isConditionalTaskActivated($task)
                && str_contains(strtolower((string) $task->title), 'sample approval')
            );
            if ($sample) return $sample;
        }

        if ($requiredNext && strcasecmp(trim((string) $requiredNext->status), 'Waiting for QC Issue Resolution') === 0) {
            $issue = $tasks->first(fn (Task $task) =>
                ! OrderDetailPresenter::isCompletedTask($task)
                && ! OrderDetailPresenter::isSkippedTask($task)
                && str_contains(strtolower((string) $task->title), 'qc issue')
            );
            if ($issue) return $issue;
        }

        if ($requiredNext) return $requiredNext;

        // Match the Order Details active-task resolver. A manually added task
        // can be the current action after configured required work is complete.
        $manual = $tasks->first(fn (Task $task) =>
            ! $task->task_pack_task_id
            && ! OrderDetailPresenter::isCompletedTask($task)
            && ! OrderDetailPresenter::isSkippedTask($task)
        );
        if ($manual) return $manual;

        return $tasks->first(fn (Task $task) =>
            ($task->setupTemplate?->is_required ?? $task->template?->is_required ?? true) === false
            && ! OrderDetailPresenter::isCompletedTask($task)
            && ! OrderDetailPresenter::isSkippedTask($task)
            && OrderDetailPresenter::isConditionalTaskActivated($task)
        );
    }

    /** @return array<int,int> */
    private function orderPhaseIdsForSequence(int $sequence): array
    {
        if ($sequence < 1 || $sequence > count(OrderWorkflowSetupService::fixedStages())) return [];

        $workspaceId = app(SetupContext::class)->workspaceId();
        $key = 'flowtrack:orders:stage-phase-map:v3:workspace:'.max(1, $workspaceId);

        $map = Cache::remember(
            $key,
            now()->addMinutes(self::STAGE_PHASE_MAP_CACHE_TTL_MINUTES),
            function (): array {
                $map = [];
                foreach (range(1, count(OrderWorkflowSetupService::fixedStages())) as $stageSequence) {
                    $map[$stageSequence] = [];
                }

                WorkflowPhase::query()
                    ->get(['id', 'name', 'short_name', 'sequence'])
                    ->each(function (WorkflowPhase $phase) use (&$map): void {
                        foreach (array_keys($map) as $stageSequence) {
                            if (! OrderStageResolver::matchesSequence(
                                (int) $stageSequence,
                                $phase->name,
                                $phase->short_name,
                                $phase->sequence,
                            )) {
                                continue;
                            }

                            // Only runtime WorkflowPhase ids belong in the Orders
                            // table filter. Snapshot source ids are provenance metadata;
                            // adding them here can make one logical source phase appear
                            // in more than one operational stage after workflow changes.
                            $map[$stageSequence][] = (int) $phase->id;
                            break;
                        }
                    });

                foreach ($map as $stageSequence => $ids) {
                    $map[$stageSequence] = array_values(array_unique(array_filter(
                        array_map('intval', $ids),
                        fn (int $id): bool => $id > 0,
                    )));
                }

                return $map;
            },
        );

        return array_values($map[$sequence] ?? []);
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }
}
