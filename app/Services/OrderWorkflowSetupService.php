<?php

namespace App\Services;

use App\Models\MasterRecord;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Arr;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Order workflow runtime/configuration support.
 *
 * Order and Inquiry workflows are configured from the shared Workflow Setup
 * screen. Task Packs stay reusable and are edited only from Task Pack Setup.
 * The Order runtime still requires the same seven stages and automation keys;
 * this service protects that contract while allowing multiple Order workflow
 * templates to reuse independently managed Task Packs.
 */
class OrderWorkflowSetupService
{
    /** @var array<int,bool> request-scoped active workflow checks */
    private array $activeOrderWorkflowCache = [];

    public const WORKFLOW_NAME = 'FlowTrack Order Workflow';
    public const WORKFLOW_CODE = 'ORDER_PROCESS';

    /** Query every reusable Order workflow in the current workspace. */
    public static function orderWorkflowQuery(): Builder
    {
        return WorkflowTemplate::query()
            ->where('workspace_id', app(WorkflowService::class)->workspaceId())
            ->where('applies_to', 'orders');
    }

    /**
     * Legacy query kept for backward compatibility with old ORDER_PROCESS URLs
     * and data. New configuration uses orderWorkflowQuery().
     */
    public static function dedicatedWorkflowQuery(): Builder
    {
        return self::orderWorkflowQuery()
            ->where(function (Builder $query): void {
                $query->where('code', self::WORKFLOW_CODE)
                    ->orWhere('code', 'like', self::WORKFLOW_CODE.'-%');
            });
    }

    /** @return array<int, array<string, mixed>> */
    public static function fixedStages(): array
    {
        return [
            [
                'key' => 'new', 'name' => 'New Order', 'short' => 'New Order', 'color' => '#2d72d9',
                'tasks' => [
                    self::task('NEW_UPLOAD_PO', 'Upload Purchase Order', 'Order Team', 0, true, 'Purchase Order', true, true, "Upload the customer's purchase order and any supporting documents."),
                    self::task('NEW_SEND_PO_ARTWORK', 'Send Purchase Order to Artwork Team', 'Order Team', 0),
                ],
            ],
            [
                'key' => 'art', 'name' => 'Artwork', 'short' => 'Artwork', 'color' => '#7b61c9',
                'tasks' => [
                    self::task('ART_PREPARE_UPLOAD', 'Prepare & Upload Artwork', 'Artwork', 0, true, 'Artwork', true, true, 'Upload up to 10 artwork files as one revision. Older revisions remain in history.'),
                    self::task('ART_INTERNAL_REVIEW', 'Internal Artwork Review', 'Artwork', 0),
                    self::task('ART_SEND_ORDER_TEAM', 'Send Artwork to Order Team', 'Artwork', 0),
                    self::task('ART_CLIENT_ERP_DECISION', 'Client ERP / Approval', 'Order Team', 1, true, 'Artwork Approval', false, true, 'Attach client approval evidence when available.'),
                    self::task('ART_SAMPLE_APPROVAL', 'Sample Approval (when required)', 'Order Team', 1, false, 'Sample Approval', true, true, 'Required only when the order uses the sample-approval path.'),
                ],
            ],
            [
                'key' => 'prod', 'name' => 'Production', 'short' => 'Production', 'color' => '#d17b1f',
                'tasks' => [
                    array_merge(
                        self::task('PROD_SET_ESTIMATED_DELIVERY', 'Set estimated delivery date', 'Production', 0),
                        [
                            'description' => 'Required before Production can start.',
                            'color' => '#f28c28',
                        ],
                    ),
                    self::task('PROD_START', 'Start Production', 'Production', 0),
                    self::task('PROD_ISSUE', 'Monitor / Resolve Production Issue', 'Production', 1, false, 'Production Document', false, true, 'Add supplier evidence, screenshots or issue documents when needed.'),
                    self::task('PROD_FINISH', 'Finish Production', 'Production', 0),
                ],
            ],
            [
                'key' => 'qc', 'name' => 'QC', 'short' => 'QC', 'color' => '#138d7a',
                'tasks' => [
                    self::task('QC_CHECK', 'Perform QC Check', 'QC', 0, true, 'QC Document', false, true, 'Upload QC photos or supporting documents when applicable.'),
                    self::task('QC_ISSUE', 'Resolve QC Issue (when needed)', 'QC', 1, false, 'QC Document', false, true, 'Attach supplier resolution evidence when available.'),
                    self::task('QC_APPROVE_SHIPMENT', 'Approve for Shipment', 'QC', 0),
                ],
            ],
            [
                'key' => 'ship', 'name' => 'Shipment', 'short' => 'Shipment', 'color' => '#1873a8',
                'tasks' => [
                    self::task('SHIP_CONFIRM_INFO', 'Review or update shipment details', 'Order Team', 0),
                    self::task('SHIP_LABEL', 'Add tracking number & print courier label', 'Shipping', 0, true, 'Shipping Document', true, false, 'Attach or generate the final courier label.'),
                    self::task('SHIP_PACKAGE', 'Dispatch shipment', 'Shipping', 0, true, 'Shipping Document', false, true, 'Optional shipment receipt or dispatch document.'),
                ],
            ],
            [
                'key' => 'bill', 'name' => 'Billing', 'short' => 'Billing', 'color' => '#b65983',
                'tasks' => [
                    self::task('BILL_PREPARE', 'Prepare Invoice', 'Finance', 0, true, 'Invoice', true, false, 'Invoice must exist before this task can complete.'),
                    self::task('BILL_SEND', 'Send Invoice', 'Finance', 0),
                ],
            ],
            [
                'key' => 'pay', 'name' => 'Payment', 'short' => 'Payment', 'color' => '#27855a',
                'tasks' => [
                    self::task('PAY_PROCESS', 'Receive & Process Payment', 'Finance', 0, true, 'Payment Proof', false, true, 'Attach bank/payment evidence when available.'),
                ],
            ],
        ];
    }

    /**
     * Keep the legacy workflows mirror required by flow_jobs.workflow_id in
     * sync with the dedicated WorkflowTemplate. This is an infrastructure
     * compatibility repair, not a second workflow-definition source.
     */
    public function ensureRuntimeMirror(int $workflowId): Workflow
    {
        $template = self::orderWorkflowQuery()->whereKey($workflowId)->firstOrFail();
        $workflow = Workflow::query()->updateOrCreate(['id' => $template->id], [
            'name' => (string) $template->name,
            'slug' => Str::slug((string) $template->name).'-order-process-'.$template->id,
            'description' => $template->description,
            'is_active' => (bool) $template->is_active,
            'is_snapshot' => false,
            'source_workflow_id' => null,
            'snapshot_job_id' => null,
        ]);

        WorkflowPhase::query()
            ->where('workflow_template_id', $template->id)
            ->where(function (Builder $query) use ($workflow): void {
                $query->whereNull('workflow_id')
                    ->orWhere('workflow_id', '!=', $workflow->id);
            })
            ->update(['workflow_id' => $workflow->id]);

        return $workflow;
    }

    /**
     * A workflow is creatable only when all seven published stages have a Task
     * Pack and at least one task. This prevents Create Order from showing a
     * workflow card with "0 tasks" after an interrupted setup save.
     */
    public function isReadyForOrderCreation(?int $workflowId = null): bool
    {
        $workflow = self::orderWorkflowQuery()
            ->when($workflowId, fn ($query) => $query->whereKey($workflowId))
            ->where('is_active', true)
            ->with([
                'phases' => fn ($query) => $query->where('is_active', true)->orderBy('sequence'),
                'phases.taskPack.items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            ])
            ->orderBy('id')
            ->first();

        if (! $workflow) return false;

        return $this->publishedPhasesAreReady($workflow->phases);
    }

    /**
     * Request-scoped active Order-workflow lookup. Order Details asks this same
     * question during binding, auto-advance and read-model hydration. Keeping
     * the answer only for the current Laravel request removes duplicate EXISTS
     * queries without allowing setup changes to become stale across requests.
     */
    public function isActiveOrderWorkflow(int $workflowId): bool
    {
        if ($workflowId <= 0) return false;

        if (array_key_exists($workflowId, $this->activeOrderWorkflowCache)) {
            return $this->activeOrderWorkflowCache[$workflowId];
        }

        return $this->activeOrderWorkflowCache[$workflowId] = self::orderWorkflowQuery()
            ->whereKey($workflowId)
            ->where('is_active', true)
            ->exists();
    }

    /** Whether a template belongs to the shared Order workflow family. */
    public function isOrderWorkflow(int $workflowId): bool
    {
        return self::orderWorkflowQuery()->whereKey($workflowId)->exists();
    }

    /** @return array<int,string> */
    public static function automationKeysForStage(int $sequence): array
    {
        $stage = self::fixedStages()[$sequence - 1] ?? null;
        if (! $stage) return [];

        return collect($stage['tasks'] ?? [])
            ->pluck('automation_key')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Initialize a newly-created Order workflow with the fixed seven runtime
     * stages. Task Packs are separate reusable records and can later be edited
     * from Task Pack Setup. When duplicating, clone the source packs so changes
     * to one Order workflow never silently alter another one.
     */
    public function initializeWorkflowTemplate(WorkflowTemplate $workflow, ?WorkflowTemplate $source = null): void
    {
        if ((string) $workflow->applies_to !== 'orders') return;

        $sourcePhases = collect();
        if ($source && (string) $source->applies_to === 'orders') {
            $sourcePhases = $source->phases()
                ->with(['taskPack.items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
                ->orderBy('sequence')
                ->get();
        }
        $canClone = $this->structureMatchesOrderRuntime($sourcePhases);
        $workflowService = app(WorkflowService::class);

        // workflow_phases.workflow_id is a required FK to the legacy runtime
        // workflows table. Create/synchronize that compatibility mirror before
        // inserting the seven template phases so fresh databases (especially
        // SQLite tests) never observe an invalid intermediate state.
        $this->ensureRuntimeMirror((int) $workflow->id);

        DB::transaction(function () use ($workflow, $sourcePhases, $canClone, $workflowService): void {
            foreach (self::fixedStages() as $index => $fixed) {
                $sequence = $index + 1;
                $sourcePhase = $canClone ? $sourcePhases->values()->get($index) : null;
                $pack = $this->createStageTaskPack($workflow, $sequence, $fixed, $sourcePhase?->taskPack);

                $workflowService->savePhase($workflow, [
                    'name' => $fixed['name'],
                    'short_name' => $fixed['short'],
                    'color' => $sourcePhase?->color ?: $fixed['color'],
                    'task_pack_id' => $pack->id,
                    'requires_approval' => false,
                    'is_active' => true,
                    'entry_condition' => $sequence === 1 ? 'Order created' : 'Previous stage complete',
                    'exit_condition' => 'Required tasks complete',
                    'sequence' => $sequence,
                ], null, false);
            }
        });

        $this->ensureRuntimeMirror((int) $workflow->id);
    }

    /** Task Packs safe to map to a fixed Order stage. */
    public function compatibleTaskPacksForStage(int $sequence): Collection
    {
        return TaskPack::query()
            ->where('workspace_id', app(TaskPackService::class)->workspaceId())
            ->where('is_snapshot', false)
            ->where('is_active', true)
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->orderBy('name')
            ->get()
            ->filter(fn (TaskPack $pack) => $this->taskPackSupportsStage($pack, $sequence))
            ->values();
    }

    public function assertCompatibleTaskPack(int $taskPackId, int $sequence): TaskPack
    {
        $pack = TaskPack::query()
            ->where('workspace_id', app(TaskPackService::class)->workspaceId())
            ->where('is_snapshot', false)
            ->where('is_active', true)
            ->with(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->findOrFail($taskPackId);

        if (! $this->taskPackSupportsStage($pack, $sequence)) {
            $stage = self::fixedStages()[$sequence - 1]['name'] ?? 'Order stage';
            throw ValidationException::withMessages([
                'taskPackId' => $pack->name.' is not compatible with '.$stage.'. Edit its core Order tasks in Task Pack Setup first.',
            ]);
        }

        return $pack;
    }

    /** Publish one Order workflow without changing its branch/action logic. */
    public function publishWorkflow(int $workflowId): int
    {
        if (! $this->isReadyForOrderCreation($workflowId)) {
            throw ValidationException::withMessages([
                'phase' => 'This Order workflow is incomplete. Every fixed stage needs a compatible Task Pack.',
            ]);
        }

        $this->ensureRuntimeMirror($workflowId);
        $count = app(OrderWorkflowBindingService::class)->syncActiveOrders($workflowId);
        app(WorkspaceRefreshService::class)->touch('WorkflowSetup:order-published');

        return $count;
    }

    private function structureMatchesOrderRuntime(Collection $phases): bool
    {
        if ($phases->count() !== count(self::fixedStages())) return false;

        foreach (self::fixedStages() as $index => $fixed) {
            $phase = $phases->values()->get($index);
            if (! $phase || (int) $phase->sequence !== $index + 1) return false;
            if (strcasecmp(trim((string) $phase->name), (string) $fixed['name']) !== 0) return false;
            if (! $phase->taskPack || ! $this->taskPackSupportsStage($phase->taskPack, $index + 1)) return false;
        }

        return true;
    }

    /**
     * Validate an already-loaded published phase graph using the same rules as
     * isReadyForOrderCreation(). This lets the Order Details binding path reuse
     * the phase/task-pack graph it must load anyway instead of querying it a
     * second time solely for readiness validation.
     */
    public function publishedPhasesAreReady(Collection $phases): bool
    {
        if ($phases->count() !== count(self::fixedStages())) return false;

        foreach (self::fixedStages() as $index => $fixed) {
            $phase = $phases->values()->get($index);
            if (! $phase || (int) $phase->sequence !== $index + 1) return false;
            if (strcasecmp(trim((string) $phase->name), (string) $fixed['name']) !== 0) return false;
            if (! $phase->taskPack || $phase->taskPack->items->isEmpty()) return false;
            if (! $this->taskPackSupportsStage($phase->taskPack, $index + 1)) return false;
        }

        return true;
    }

    private function taskPackSupportsStage(TaskPack $pack, int $sequence): bool
    {
        $expected = self::automationKeysForStage($sequence);
        if (! $expected) return false;

        $actual = $pack->items
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->pluck('automation_key')
            ->filter()
            ->values()
            ->all();

        $position = -1;
        foreach ($expected as $key) {
            $next = array_search($key, array_slice($actual, $position + 1), true);
            if ($next === false) return false;
            $position += $next + 1;
        }

        return true;
    }

    private function createStageTaskPack(WorkflowTemplate $workflow, int $sequence, array $fixed, ?TaskPack $sourcePack = null): TaskPack
    {
        $taskPackService = app(TaskPackService::class);
        $pack = $taskPackService->savePack([
            'code' => 'OWF-'.$workflow->id.'-S'.$sequence,
            'name' => $workflow->name.' · '.$fixed['name'],
            'description' => 'Order workflow stage Task Pack. Edit task content from Task Pack Setup; core Order automation keys are protected.',
            'is_active' => true,
        ], null, false);

        if ($sourcePack) {
            $sourcePack->loadMissing(['items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')]);
            foreach ($sourcePack->items as $index => $item) {
                $taskPackService->saveItem($pack, [
                    'automation_key' => $item->automation_key,
                    'title' => $item->title,
                    'description' => $item->description,
                    'color' => $item->color ?? '#2563EB',
                    'default_assignee_id' => $item->default_assignee_id,
                    'default_assignee_type' => $item->default_assignee_type ?: 'user',
                    'default_department_id' => $item->default_department_id,
                    'priority_id' => $item->priority_id,
                    'document_category_id' => $item->document_category_id,
                    'document_required_before_completion' => (bool) ($item->document_required_before_completion ?? true),
                    'allow_multiple_documents' => (bool) ($item->allow_multiple_documents ?? false),
                    'document_instructions' => $item->document_instructions,
                    'due_offset_days' => $item->due_offset_days,
                    'standard_duration_value' => $item->standard_duration_value,
                    'standard_duration_unit' => $item->standard_duration_unit,
                    'timer_start_rule' => $item->timer_start_rule,
                    'timer_stop_rule' => $item->timer_stop_rule,
                    'work_calendar' => $item->work_calendar,
                    'set_due_from_standard_duration' => (bool) $item->set_due_from_standard_duration,
                    'allow_efficiency_override' => (bool) $item->allow_efficiency_override,
                    'is_required' => (bool) $item->is_required,
                    'sort_order' => $index,
                ], null, false, false);
            }
            return $pack;
        }

        $departments = $this->departments()->keyBy(fn (MasterRecord $record) => mb_strtolower(trim((string) $record->name)));
        foreach ($fixed['tasks'] as $index => $task) {
            $documentCategoryId = filled($task['document_type'] ?? null)
                ? $taskPackService->resolveLegacyDocumentCategoryId(null, (string) $task['document_type'])
                : null;
            $taskPackService->saveItem($pack, [
                'automation_key' => $task['automation_key'],
                'title' => $task['title'],
                'description' => $task['description'] ?? null,
                'color' => $task['color'] ?? ['#2563EB','#7C3AED','#0891B2','#0F766E','#16A34A','#CA8A04','#EA580C','#DC2626'][$index % 8],
                'default_department_id' => $departments->get(mb_strtolower((string) ($task['team'] ?? '')))?->id,
                'document_category_id' => $documentCategoryId,
                'document_required_before_completion' => (bool) ($task['document_required_before_completion'] ?? true),
                'allow_multiple_documents' => (bool) ($task['allow_multiple_documents'] ?? false),
                'document_instructions' => $task['document_instructions'] ?? null,
                'due_offset_days' => (int) ($task['due_offset_days'] ?? 0),
                'is_required' => (bool) ($task['is_required'] ?? true),
                'sort_order' => $index,
            ], null, false, false);
        }

        return $pack;
    }

    /**
     * Super/Admin self-heal for a dedicated workflow whose previous save was
     * interrupted by a missing schema column. The visible defaults already
     * match the approved prototype, so saving them is deterministic.
     */
    public function repairIfIncomplete(): ?int
    {
        $workflowId = self::dedicatedWorkflowQuery()->where('is_active', true)->orderBy('id')->value('id');
        if (! $workflowId) return null;

        $this->ensureRuntimeMirror((int) $workflowId);
        if ($this->isReadyForOrderCreation((int) $workflowId)) return (int) $workflowId;
        if (! auth()->user()?->canModule('workflow', 'edit')) return (int) $workflowId;

        $state = $this->defaultState();
        $saved = $this->save((int) $workflowId, $state['stages']);
        $resolved = filled($saved['workflow_id'] ?? null) ? (int) $saved['workflow_id'] : (int) $workflowId;
        $this->ensureRuntimeMirror($resolved);
        return $resolved;
    }

    public function load(?int $workflowId = null): array
    {
        $workflow = $this->orderWorkflow($workflowId);
        $departments = $this->departments();
        $documents = $this->documentCategories();

        $departmentByName = $departments->keyBy(fn (MasterRecord $record) => mb_strtolower(trim((string) $record->name)));
        $documentByName = $documents->keyBy(fn (MasterRecord $record) => mb_strtolower(trim((string) $record->name)));

        $workflowPhases = $workflow?->phases?->sortBy('sequence')->values() ?? collect();
        $structureMatchesPrototype = $workflowPhases->count() === count(self::fixedStages())
            && $workflowPhases->values()->every(function (WorkflowPhase $phase, int $index): bool {
                $fixed = self::fixedStages()[$index] ?? null;
                return $fixed
                    && (int) $phase->sequence === $index + 1
                    && strcasecmp(trim((string) $phase->name), (string) $fixed['name']) === 0;
            });
        $phases = $workflowPhases->keyBy(fn (WorkflowPhase $phase) => (int) $phase->sequence);

        $stages = collect(self::fixedStages())->map(function (array $fixed, int $index) use ($phases, $departmentByName, $documentByName, $structureMatchesPrototype): array {
            $sequence = $index + 1;
            /** @var WorkflowPhase|null $phase */
            $phase = $phases->get($sequence);
            $items = $structureMatchesPrototype
                ? ($phase?->taskPack?->items?->sortBy('sort_order')->values() ?? collect())
                : collect();

            $tasks = $items->isNotEmpty()
                ? $items->map(fn (TaskPackItem $item) => $this->itemToState($item))->all()
                : collect($fixed['tasks'])->map(function (array $task) use ($departmentByName, $documentByName): array {
                    $task['default_department_id'] = $departmentByName->get(mb_strtolower($task['team']))?->id;
                    $task['document_category_id'] = $task['document_type']
                        ? $documentByName->get(mb_strtolower($task['document_type']))?->id
                        : null;
                    return $task;
                })->all();

            return [
                'key' => $fixed['key'],
                'sequence' => $sequence,
                'phase_id' => $phase?->id,
                'task_pack_id' => $phase?->task_pack_id,
                'name' => $fixed['name'],
                'short' => $fixed['short'],
                'color' => $this->normalizeColor((string) (($structureMatchesPrototype ? $phase?->color : null) ?: $fixed['color']), $fixed['color']),
                'active' => true,
                'tasks' => array_values($tasks),
            ];
        })->all();

        return [
            'workflow_id' => $workflow?->id,
            'workflow_name' => $workflow?->name ?: self::WORKFLOW_NAME,
            'stages' => $stages,
            'departments' => $departments->map(fn (MasterRecord $record) => ['id' => (int) $record->id, 'name' => (string) $record->name])->values()->all(),
            'document_categories' => $documents->map(fn (MasterRecord $record) => ['id' => (int) $record->id, 'name' => (string) $record->name])->values()->all(),
        ];
    }

    public function save(?int $workflowId, array $stages, bool $authorize = true): array
    {
        $this->assertFixedStructure($stages);

        $workflowService = app(WorkflowService::class);
        $taskPackService = app(TaskPackService::class);
        $workspaceId = $workflowService->workspaceId();

        $workflow = $workflowId
            ? self::orderWorkflowQuery()->findOrFail($workflowId)
            : null;

        // Capture every active Order's intended seven-stage destination before
        // any legacy five-stage phase is renamed in-place. Without this, an
        // old "Invoice & Payment" phase can become "Shipment" before the
        // binding service gets a chance to classify the Order correctly.
        $capturedTargetSequences = $workflow
            ? app(OrderWorkflowBindingService::class)->captureActiveOrderTargetSequences((int) $workflow->id)
            : [];

        if ($workflow) {
            $workflow = $workflowService->saveWorkflow([
                'code' => (string) $workflow->code,
                'name' => (string) ($workflow->name ?: self::WORKFLOW_NAME),
                'description' => $workflow->description,
                'is_active' => true,
                'version' => max(1, (int) $workflow->version + 1),
                'applies_to' => 'orders',
                'client_availability' => $workflow->client_availability === 'specific' ? 'specific' : 'all',
                'client_ids' => $workflow->client_availability === 'specific'
                    ? $workflow->clients()->pluck('clients.id')->map(fn ($id) => (int) $id)->all()
                    : [],
            ], (int) $workflow->id, $authorize);
        } else {
            $code = self::WORKFLOW_CODE;
            if (WorkflowTemplate::query()->where('workspace_id', $workspaceId)->where('code', $code)->exists()) {
                $code .= '-'.str_pad((string) (((int) WorkflowTemplate::query()->where('workspace_id', $workspaceId)->max('id')) + 1), 3, '0', STR_PAD_LEFT);
            }
            $workflow = $workflowService->saveWorkflow([
                'code' => $code,
                'name' => self::WORKFLOW_NAME,
                'description' => 'Fixed seven-stage Order workflow managed from shared Workflow Setup.',
                'is_active' => true,
                'version' => 1,
                'applies_to' => 'orders',
                'client_availability' => 'all',
                'client_ids' => [],
            ], null, $authorize);
        }

        // The shared Workflow Setup is the live definition for active Orders.
        // Completed/cancelled Orders keep their historical workflow, while
        // active Orders are synchronized after this save completes.

        DB::transaction(function () use ($workflow, $stages, $taskPackService, $workflowService, $workspaceId): void {
            $existingPhases = WorkflowPhase::query()
                ->where('workflow_template_id', $workflow->id)
                ->with(['taskPack.items'])
                ->orderBy('sequence')
                ->get();
            $keptPhaseIds = [];

            foreach (array_values($stages) as $index => $stage) {
                $sequence = $index + 1;
                $fixed = self::fixedStages()[$index];
                $phase = $existingPhases->first(fn (WorkflowPhase $row) => (int) $row->sequence === $sequence);

                $packCode = substr('OWF-'.$workflow->id.'-S'.$sequence, 0, 40);
                $pack = TaskPack::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('is_snapshot', false)
                    ->where('code', $packCode)
                    ->first();

                if (! $pack) {
                    $pack = $taskPackService->savePack([
                        'code' => $packCode,
                        'name' => $workflow->name.' · '.$fixed['name'],
                        'description' => 'Managed by the Order workflow runtime; edit task content from Task Pack Setup.',
                        'is_active' => true,
                    ], null, false);
                } else {
                    $pack = $taskPackService->savePack([
                        'code' => $packCode,
                        'name' => $workflow->name.' · '.$fixed['name'],
                        'description' => 'Managed by the Order workflow runtime; edit task content from Task Pack Setup.',
                        'is_active' => true,
                    ], (int) $pack->id, false);
                }

                $existingItems = $pack->items()->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
                $existingItemsByAutomationKey = $existingItems
                    ->filter(fn ($item) => filled($item->automation_key))
                    ->keyBy(fn ($item) => trim((string) $item->automation_key));
                $existingItemsByNormalizedTitle = $existingItems
                    ->groupBy(fn ($item) => (string) Str::of((string) $item->title)
                        ->lower()
                        ->replace('&', ' and ')
                        ->replaceMatches('/[^a-z0-9]+/', ' ')
                        ->squish());
                $keepItemIds = [];
                foreach (array_values($stage['tasks'] ?? []) as $taskIndex => $taskState) {
                    $automationKey = (string) (($fixed['tasks'][$taskIndex]['automation_key'] ?? null) ?: ($taskState['automation_key'] ?? ''));

                    // Core Order workflow tasks have a stable automation key.
                    // Use it as the identity fallback when Livewire state does
                    // not carry the Task Pack item id. Creating a replacement
                    // item here used to soft-delete generated Tasks and leave
                    // their artwork Documents pointing at the retired task id.
                    $itemId = !empty($taskState['id']) && $existingItems->has((int) $taskState['id'])
                        ? (int) $taskState['id']
                        : (int) ($existingItemsByAutomationKey->get(trim($automationKey))?->id ?? 0);

                    if ($itemId <= 0) {
                        $normalizedTitle = (string) Str::of((string) ($taskState['title'] ?? ''))
                            ->lower()
                            ->replace('&', ' and ')
                            ->replaceMatches('/[^a-z0-9]+/', ' ')
                            ->squish();
                        $titleMatches = collect($existingItemsByNormalizedTitle->get($normalizedTitle, collect()))->values();
                        if ($normalizedTitle !== '' && $titleMatches->count() === 1) {
                            $itemId = (int) $titleMatches->first()->id;
                        }
                    }

                    $itemId = $itemId > 0 ? $itemId : null;
                    $documentCategoryId = null;
                    if (!empty($taskState['document_enabled'])) {
                        $documentCategoryId = filled($taskState['document_category_id'] ?? null)
                            ? (int) $taskState['document_category_id']
                            : $taskPackService->resolveLegacyDocumentCategoryId(null, (string) ($taskState['document_type'] ?? ''));
                    }

                    $saved = $taskPackService->saveItem($pack, [
                        'automation_key' => $automationKey,
                        'title' => trim((string) ($taskState['title'] ?? 'Task')),
                        'description' => blank($taskState['description'] ?? null) ? null : trim((string) $taskState['description']),
                        'color' => $taskState['color'] ?? '#2563EB',
                        'default_assignee_id' => null,
                        'default_department_id' => filled($taskState['default_department_id'] ?? null) ? (int) $taskState['default_department_id'] : null,
                        'priority_id' => null,
                        'document_category_id' => $documentCategoryId,
                        'document_required_before_completion' => !empty($taskState['document_enabled']) && (bool) ($taskState['document_required_before_completion'] ?? true),
                        'allow_multiple_documents' => !empty($taskState['document_enabled']) && (bool) ($taskState['allow_multiple_documents'] ?? false),
                        'document_instructions' => !empty($taskState['document_enabled']) ? ($taskState['document_instructions'] ?? null) : null,
                        'due_offset_days' => max(0, min(3650, (int) ($taskState['due_offset_days'] ?? 0))),
                        'is_required' => (bool) ($taskState['is_required'] ?? true),
                        'sort_order' => $taskIndex,
                    ], $itemId, false, false);
                    $keepItemIds[] = (int) $saved->id;
                }

                foreach ($existingItems->keys()->map(fn ($id) => (int) $id)->diff($keepItemIds) as $removeId) {
                    // This dedicated setup is live for active Orders. When an
                    // admin removes a configured task, archive its generated
                    // Order task rows first so the reusable Task Pack item can
                    // be removed safely without destroying audit history.
                    Task::query()
                        ->where('task_pack_task_id', (int) $removeId)
                        ->delete();
                    $taskPackService->deleteItem((int) $removeId, false);
                }

                $savedPhase = $workflowService->savePhase($workflow, [
                    'name' => $fixed['name'],
                    'short_name' => $fixed['short'],
                    'color' => $this->normalizeColor((string) ($stage['color'] ?? $fixed['color']), $fixed['color']),
                    'task_pack_id' => (int) $pack->id,
                    'requires_approval' => false,
                    'is_active' => true,
                    'entry_condition' => $sequence === 1 ? 'Order created' : 'Previous stage complete',
                    'exit_condition' => 'Required tasks complete',
                    'sequence' => $sequence,
                ], $phase, false);
                $keptPhaseIds[] = (int) $savedPhase->id;
            }

            WorkflowPhase::query()
                ->where('workflow_template_id', $workflow->id)
                ->when($keptPhaseIds, fn ($query) => $query->whereNotIn('id', $keptPhaseIds))
                ->delete();
        });

        Cache::forget(OrderListPrototypeService::stageDefinitionCacheKey($workspaceId));

        // Publish the saved seven-stage definition to every active Order.
        // This is intentionally different from the original reusable Workflow
        // Setup: the Order workflow template is the operational source of truth for
        // current Orders, so old 5-stage Order records cannot keep resurfacing
        // on the Order Details page after this setup is saved.
        $syncedOrderCount = app(OrderWorkflowBindingService::class)->syncActiveOrders((int) $workflow->id, $capturedTargetSequences);

        app(WorkspaceRefreshService::class)->touch('OrderWorkflowSetup:saved');

        $state = $this->load((int) $workflow->id);
        $state['synced_order_count'] = $syncedOrderCount;
        return $state;
    }

    public function defaultState(): array
    {
        $state = $this->load();
        $departments = collect($state['departments'])->keyBy(fn (array $row) => mb_strtolower($row['name']));
        $documents = collect($state['document_categories'])->keyBy(fn (array $row) => mb_strtolower($row['name']));

        $state['stages'] = collect(self::fixedStages())->map(function (array $stage, int $index) use ($departments, $documents): array {
            $stage['sequence'] = $index + 1;
            $stage['phase_id'] = null;
            $stage['task_pack_id'] = null;
            $stage['active'] = true;
            $stageColor = (string) $stage['color'];
            $stage['tasks'] = collect($stage['tasks'])->map(function (array $task) use ($departments, $documents, $stageColor): array {
                $task['id'] = null;
                $task['color'] = $task['color'] ?? $stageColor;
                $task['default_department_id'] = $departments->get(mb_strtolower($task['team']))['id'] ?? null;
                $task['document_category_id'] = $task['document_type']
                    ? ($documents->get(mb_strtolower($task['document_type']))['id'] ?? null)
                    : null;
                return $task;
            })->all();
            return $stage;
        })->all();

        return $state;
    }

    private function orderWorkflow(?int $workflowId = null): ?WorkflowTemplate
    {
        return self::orderWorkflowQuery()
            ->when($workflowId, fn ($query) => $query->whereKey($workflowId))
            ->with([
                'phases' => fn ($query) => $query->orderBy('sequence'),
                'phases.taskPack.items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'phases.taskPack.items.defaultDepartment:id,name',
                'phases.taskPack.items.documentCategory:id,name',
            ])
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
    }

    private function departments()
    {
        return MasterRecord::query()
            ->where('workspace_id', app(MasterDataService::class)->workspaceId())
            ->where('type', 'department')
            ->where('status', 'active')
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);
    }

    private function documentCategories()
    {
        return MasterRecord::query()
            ->where('workspace_id', app(MasterDataService::class)->workspaceId())
            ->where('type', 'document_category')
            ->where('status', 'active')
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);
    }

    private function itemToState(TaskPackItem $item): array
    {
        return [
            'id' => (int) $item->id,
            'automation_key' => Schema::hasColumn('task_pack_items', 'automation_key') ? (string) ($item->automation_key ?? '') : '',
            'title' => (string) $item->title,
            'description' => (string) ($item->description ?? ''),
            'color' => \App\Support\MasterColor::normalize((string) ($item->color ?? '')) ?: '#2563EB',
            'team' => (string) ($item->defaultDepartment?->name ?? ''),
            'default_department_id' => $item->default_department_id ? (int) $item->default_department_id : null,
            'due_offset_days' => (int) $item->due_offset_days,
            'is_required' => (bool) $item->is_required,
            'document_enabled' => (bool) $item->document_category_id,
            'document_type' => (string) ($item->documentCategory?->name ?? ''),
            'document_category_id' => $item->document_category_id ? (int) $item->document_category_id : null,
            'document_required_before_completion' => (bool) ($item->document_required_before_completion ?? true),
            'allow_multiple_documents' => (bool) ($item->allow_multiple_documents ?? false),
            'document_instructions' => (string) ($item->document_instructions ?? ''),
        ];
    }

    private function assertFixedStructure(array $stages): void
    {
        if (count($stages) !== count(self::fixedStages())) {
            throw ValidationException::withMessages(['stages' => 'The Order workflow must contain exactly seven fixed stages.']);
        }
        foreach (self::fixedStages() as $index => $fixed) {
            if (($stages[$index]['key'] ?? null) !== $fixed['key']) {
                throw ValidationException::withMessages(['stages' => 'The seven Order stages and their order are fixed.']);
            }
            if (count($stages[$index]['tasks'] ?? []) < 1) {
                throw ValidationException::withMessages(["stages.$index.tasks" => $fixed['name'].' must contain at least one task.']);
            }
        }
    }

    private function normalizeColor(string $value, string $fallback): string
    {
        $value = trim($value);
        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtolower($value) : strtolower($fallback);
    }

    private static function task(
        string $automationKey,
        string $title,
        string $team,
        int $dueOffsetDays,
        bool $required = true,
        ?string $documentType = null,
        bool $documentRequiredBeforeCompletion = true,
        bool $allowMultipleDocuments = false,
        string $documentInstructions = '',
    ): array {
        return [
            'id' => null,
            'automation_key' => $automationKey,
            'title' => $title,
            'description' => '',
            'team' => $team,
            'default_department_id' => null,
            'due_offset_days' => $dueOffsetDays,
            'is_required' => $required,
            'document_enabled' => $documentType !== null,
            'document_type' => $documentType ?: '',
            'document_category_id' => null,
            'document_required_before_completion' => $documentType !== null && $documentRequiredBeforeCompletion,
            'allow_multiple_documents' => $documentType !== null && $allowMultipleDocuments,
            'document_instructions' => $documentInstructions,
        ];
    }
}
