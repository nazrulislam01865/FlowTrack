<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\InquiryTask;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\TaskPackTask;
use App\Models\WorkflowPhase;
use App\Support\MasterColor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskPackService
{
    public function workspaceId(): int { return app(SetupContext::class)->workspaceId(); }

    public function ensureTaskPackMasterDataDefaults(): void
    {
        if (!Schema::hasTable('master_records')) return;

        $workspaceId = $this->workspaceId();
        foreach (MasterDataService::TASK_PACK_MASTER_DEFAULTS as $type => $defaults) {
            $hasActive = MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', $type)
                ->where('status', 'active')
                ->exists();
            if ($hasActive) continue;

            $hasAny = MasterRecord::withTrashed()
                ->where('workspace_id', $workspaceId)
                ->where('type', $type)
                ->exists();

            if (! $hasAny) {
                // First-time setup: seed the complete default option family.
                foreach ($defaults as $index => $default) {
                    MasterRecord::query()->create([
                        'workspace_id' => $workspaceId,
                        'parent_id' => null,
                        'type' => $type,
                        'code' => $default['code'],
                        'name' => $default['name'],
                        'description' => null,
                        'metadata' => array_merge(
                            ['seeded_by' => 'task_pack_master_data_v1'],
                            (array) ($default['metadata'] ?? [])
                        ),
                        'status' => 'active',
                        'sort_order' => $index + 1,
                    ]);
                }

                Cache::forget("flowtrack:master:active:{$workspaceId}:{$type}");
                continue;
            }

            // Existing custom configuration with no active value would make
            // every Task Pack impossible to save. Restore only the primary
            // system fallback instead of repopulating/overriding the full list.
            $default = $defaults[0] ?? null;
            if (!$default) continue;

            $record = MasterRecord::withTrashed()
                ->where('workspace_id', $workspaceId)
                ->where('type', $type)
                ->where('code', $default['code'])
                ->first();

            $payload = [
                'parent_id' => null,
                'name' => $default['name'],
                'description' => null,
                'metadata' => array_merge(
                    ['seeded_by' => 'task_pack_master_data_v1'],
                    (array) ($default['metadata'] ?? [])
                ),
                'status' => 'active',
                'sort_order' => 1,
            ];

            if ($record) {
                if (method_exists($record, 'trashed') && $record->trashed()) {
                    $record->restore();
                }
                $record->update($payload);
            } else {
                MasterRecord::query()->create($payload + [
                    'workspace_id' => $workspaceId,
                    'type' => $type,
                    'code' => $default['code'],
                ]);
            }

            Cache::forget("flowtrack:master:active:{$workspaceId}:{$type}");
        }
    }

    public function all()
    {
        return TaskPack::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_snapshot', false)
            ->select(['id', 'workspace_id', 'code', 'name', 'description', 'is_active'])
            ->with([
                'items' => fn ($query) => $query->select([
                    'id', 'task_pack_id', 'title', 'color', 'default_assignee_id', 'default_assignee_type',
                    'default_department_id', 'priority_id', 'document_category_id',
                    'document_required_before_completion', 'allow_multiple_documents', 'document_instructions',
                    'is_required', 'sort_order',
                ]),
                'items.defaultAssignee:id,name',
                'items.defaultDepartment:id,name',
                'items.priority:id,name',
                'items.documentCategory:id,name',
            ])
            ->orderBy('name')
            ->get();
    }


    public function resolveLegacyDocumentCategoryId(?int $documentCategoryId, ?string $legacyName = null): ?int
    {
        $workspaceId = $this->workspaceId();
        if ($documentCategoryId) {
            $existing = MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', 'document_category')
                ->find($documentCategoryId);
            if ($existing) return (int) $existing->id;
        }

        $legacyName = trim((string) $legacyName);
        if ($legacyName === '') return null;

        $existingId = MasterRecord::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', 'document_category')
            ->where('name', $legacyName)
            ->value('id');
        if ($existingId) return (int) $existingId;

        // Some legacy workflow requirements (for example "Purchase Order")
        // were never present in master_values. Preserve them by promoting the
        // existing configured name into Master Data, then attach it to the
        // mapped Task Pack item.
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $legacyName), 0, 10)) ?: 'DOC';
        $code = $base;
        $suffix = 1;
        while (MasterRecord::query()->where('workspace_id', $workspaceId)->where('type', 'document_category')->where('code', $code)->exists()) {
            $code = substr($base, 0, 8).'-'.$suffix++;
        }

        return (int) MasterRecord::query()->create([
            'workspace_id' => $workspaceId,
            'parent_id' => null,
            'type' => 'document_category',
            'code' => $code,
            'name' => $legacyName,
            'description' => 'Migrated from an existing FlowTrack workflow document requirement.',
            'metadata' => ['source' => 'legacy_workflow_requirement'],
            'status' => 'active',
            'sort_order' => ((int) MasterRecord::query()->where('workspace_id', $workspaceId)->where('type', 'document_category')->max('sort_order')) + 1,
        ])->id;
    }

    public function nextCode(): string
    {
        $next = ((int) TaskPack::where('workspace_id', $this->workspaceId())->where('is_snapshot', false)->max('id')) + 1;
        do {
            $code = 'TPK-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (TaskPack::where('workspace_id', $this->workspaceId())->where('code', $code)->exists());

        return $code;
    }

    public function savePackWithItems(array $packData, array $items, ?int $id = null): TaskPack
    {
        $this->assertAction($id ? 'edit' : 'create');

        if ($id) {
            $orderedExistingIds = collect($items)
                ->pluck('id')
                ->filter()
                ->map(fn ($itemId) => (int) $itemId)
                ->values()
                ->all();
            $this->assertOrderPackCoreSequence($id, $orderedExistingIds);
        }

        $pack = DB::transaction(function () use ($packData, $items, $id) {
            $pack = $this->savePack($packData, $id, false);
            $keepIds = [];

            foreach (array_values($items) as $index => $row) {
                $itemId = !empty($row['id']) ? (int) $row['id'] : null;
                if ($itemId && !TaskPackItem::where('task_pack_id', $pack->id)->whereKey($itemId)->exists()) {
                    throw ValidationException::withMessages(['tasks' => 'A Task Pack item no longer belongs to this Task Pack.']);
                }

                $saved = $this->saveItem($pack, [
                    'title' => $row['title'] ?? '',
                    'description' => $row['description'] ?? null,
                    'color' => $row['color'] ?? '#2563EB',
                    'default_assignee_id' => $row['default_assignee_id'] ?? null,
                    'default_assignee_type' => $row['default_assignee_type'] ?? 'user',
                    'default_department_id' => $row['default_department_id'] ?? null,
                    'priority_id' => $row['priority_id'] ?? null,
                    'document_category_id' => $row['document_category_id'] ?? null,
                    'due_offset_days' => $row['due_offset_days'] ?? 1,
                    'standard_duration_value' => $row['standard_duration_value'] ?? 8,
                    'standard_duration_unit' => $row['standard_duration_unit'] ?? 'TPD-001',
                    'timer_start_rule' => $row['timer_start_rule'] ?? 'TPS-001',
                    'timer_stop_rule' => $row['timer_stop_rule'] ?? 'TPE-001',
                    'work_calendar' => $row['work_calendar'] ?? 'TPW-001',
                    'set_due_from_standard_duration' => (bool) ($row['set_due_from_standard_duration'] ?? true),
                    'allow_efficiency_override' => (bool) ($row['allow_efficiency_override'] ?? false),
                    'is_required' => (bool) ($row['is_required'] ?? true),
                    'sort_order' => $index,
                ], $itemId, false, false);
                $keepIds[] = $saved->id;
            }

            $removed = $pack->items()->when($keepIds, fn ($q) => $q->whereNotIn('id', $keepIds))->pluck('id');
            if ($removed->isNotEmpty()) {
                $user = auth()->user();
                abort_unless($user && app(AccessControlService::class)->can($user, 'taskpacks', 'delete'), 403);
            }
            foreach ($removed as $removedId) {
                $this->deleteItem((int) $removedId, false);
            }

            $this->normalize($pack->id);
            return $pack->fresh(['items.defaultAssignee','items.defaultDepartment','items.priority','items.documentCategory']);
        });

        $this->publishMappedOrderWorkflows((int) $pack->id);
        return $pack;
    }

    public function savePack(array $data, ?int $id = null, bool $authorize = true): TaskPack
    {
        if ($authorize) $this->assertAction($id ? 'edit' : 'create');
        $workspaceId = $this->workspaceId();
        $code = strtoupper(trim($data['code']));
        if (TaskPack::where('workspace_id', $workspaceId)->where('code', $code)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['packCode' => 'This Task Pack code already exists.']);
        }
        $payload = [
            'workspace_id' => $workspaceId,
            'code' => $code,
            'name' => trim($data['name']),
            'description' => blank($data['description'] ?? null) ? null : trim($data['description']),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
        if (Schema::hasColumn('task_packs', 'slug')) $payload['slug'] = Str::slug($data['name']).'-'.strtolower($code);

        if ($id) {
            $pack = TaskPack::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_snapshot', false)
                ->findOrFail($id);
            if (! $payload['is_active'] && $this->mappedOrderWorkflowIds((int) $pack->id)->isNotEmpty()) {
                throw ValidationException::withMessages(['packActive' => 'A Task Pack mapped to an Order workflow cannot be deactivated. Remap that workflow first.']);
            }
            $pack->update($payload);
            return $pack->refresh();
        }

        return TaskPack::query()->create($payload + ['is_snapshot' => false]);
    }

    public function togglePack(int $id): void
    {
        $this->assertAction('edit');
        $pack = TaskPack::where('workspace_id', $this->workspaceId())->where('is_snapshot', false)->findOrFail($id);
        if ($pack->is_active && $this->mappedOrderWorkflowIds((int) $pack->id)->isNotEmpty()) {
            throw ValidationException::withMessages(['pack' => 'A Task Pack mapped to an Order workflow cannot be deactivated. Remap that workflow first.']);
        }
        $pack->update(['is_active' => !$pack->is_active]);
    }

    /**
     * Resolve Task Pack dependencies only when the user opens the destructive
     * delete dialog. Normal Task Pack rendering remains unchanged and fast.
     */
    public function packDeleteImpact(int $id): array
    {
        $this->assertAction('delete');
        $pack = TaskPack::query()
            ->where('workspace_id', $this->workspaceId())
            ->where('is_snapshot', false)
            ->findOrFail($id);

        $phaseBase = WorkflowPhase::query()
            ->where('task_pack_id', $id)
            ->whereNotNull('workflow_template_id');

        $mappedPhaseCount = (clone $phaseBase)->count();
        $sourceWorkflowIds = (clone $phaseBase)
            ->select('workflow_template_id')
            ->distinct()
            ->pluck('workflow_template_id')
            ->filter()
            ->map(fn ($workflowId) => (int) $workflowId)
            ->values();

        $mappedPhases = (clone $phaseBase)
            ->with(['workflowTemplate:id,name'])
            ->orderBy('workflow_template_id')
            ->orderBy('sequence')
            ->limit(8)
            ->get(['id', 'workflow_template_id', 'name', 'sequence', 'task_pack_id', 'color']);

        $jobsBase = FlowJob::withTrashed()
            ->where(function ($query) use ($sourceWorkflowIds) {
                $query->whereIn('source_workflow_id', $sourceWorkflowIds)
                    ->orWhere(function ($legacy) use ($sourceWorkflowIds) {
                        $legacy->whereNull('source_workflow_id')->whereIn('workflow_id', $sourceWorkflowIds);
                    });
            });

        $jobCount = $sourceWorkflowIds->isEmpty() ? 0 : (clone $jobsBase)->count();
        $taskCount = $sourceWorkflowIds->isEmpty()
            ? 0
            : Task::withTrashed()
                ->whereIn('flow_job_id', (clone $jobsBase)->select('id'))
                ->count();

        $jobs = $sourceWorkflowIds->isEmpty()
            ? collect()
            : (clone $jobsBase)
                ->orderBy('job_number')
                ->limit(8)
                ->get(['id', 'job_number', 'title', 'workflow_id', 'source_workflow_id', 'deleted_at']);

        $mappedOrderWorkflowIds = $this->mappedOrderWorkflowIds((int) $pack->id);

        return [
            'id' => (int) $pack->id,
            'can_delete' => $mappedOrderWorkflowIds->isEmpty(),
            'blocked_reason' => $mappedOrderWorkflowIds->isNotEmpty()
                ? 'This Task Pack is mapped to an Order workflow. Remap that stage in Workflow Setup before deleting the Task Pack.'
                : null,
            'name' => (string) $pack->name,
            'mapped_phase_count' => $mappedPhaseCount,
            'mapped_phases' => $mappedPhases->map(fn (WorkflowPhase $phase) => [
                'id' => (int) $phase->id,
                'name' => (string) $phase->name,
                'sequence' => (int) $phase->sequence,
                'color' => \App\Support\MasterColor::normalize((string) ($phase->color ?? '')),
                'workflow_name' => (string) ($phase->workflowTemplate?->name ?: 'Workflow'),
            ])->all(),
            'generated_task_count' => 0,
            'generated_tasks' => [],
            'job_count' => $jobCount,
            'jobs' => $jobs->map(fn (FlowJob $job) => [
                'id' => (int) $job->id,
                'job_number' => (string) $job->displayOrderNumber(),
                'title' => (string) $job->title,
                'trashed' => $job->deleted_at !== null,
                'already_snapshotted' => $job->source_workflow_id !== null
                    && (int) $job->workflow_id !== (int) $job->source_workflow_id,
            ])->all(),
            'task_count' => $taskCount,
        ];
    }

    public function deletePack(int $id): array
    {
        $this->assertAction('delete');
        $pack = TaskPack::where('workspace_id', $this->workspaceId())->where('is_snapshot', false)->findOrFail($id);
        if ($this->mappedOrderWorkflowIds((int) $pack->id)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'pack' => 'This Task Pack is mapped to an Order workflow. Remap that stage in Workflow Setup before deleting it.',
            ]);
        }

        $mappedPhases = WorkflowPhase::query()
            ->where('task_pack_id', $id)
            ->whereNotNull('workflow_template_id')
            ->get(['id', 'workflow_id', 'workflow_template_id']);
        $sourceWorkflowIds = $mappedPhases
            ->map(fn (WorkflowPhase $phase) => (int) ($phase->workflow_template_id ?: $phase->workflow_id))
            ->filter()->unique()->values();

        $legacyJobIds = $sourceWorkflowIds->isEmpty()
            ? []
            : FlowJob::withTrashed()
                ->whereIn('workflow_id', $sourceWorkflowIds)
                ->pluck('id')
                ->map(fn ($jobId) => (int) $jobId)
                ->all();

        $protectedJobs = 0;
        try {
            DB::transaction(function () use ($pack, $id, $legacyJobIds, &$protectedJobs) {
                if ($legacyJobIds) {
                    $protectedJobs = app(JobWorkflowSnapshotService::class)->snapshotJobs($legacyJobIds);
                }

                // Setup phases keep their structure; deleting a reusable Task
                // Pack only clears the setup mapping. Existing Jobs use their
                // own private copied Task Pack and Tasks.
                WorkflowPhase::query()
                    ->where('task_pack_id', $id)
                    ->whereNotNull('workflow_template_id')
                    ->update(['task_pack_id' => null]);

                TaskPackTask::query()->where('task_pack_id', $id)->delete();
                TaskPackItem::query()->where('task_pack_id', $id)->delete();
                $pack->delete();
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'pack' => 'FlowTrack could not safely detach every linked Job. Nothing was deleted. Refresh and try again.',
                ]);
            }
            throw $exception;
        }

        return [
            'pack_name' => (string) $pack->name,
            'job_count' => $protectedJobs,
            'task_count' => 0,
            'mapped_phase_count' => $mappedPhases->count(),
        ];
    }

    public function saveItem(TaskPack $pack, array $data, ?int $id = null, bool $authorize = true, bool $publishOrderWorkflows = true): TaskPackItem
    {
        if ($authorize) $this->assertAction('edit');
        abort_if((bool) $pack->is_snapshot, 404);
        $item = DB::transaction(function () use ($pack, $data, $id) {
            $existingItem = $id ? TaskPackItem::query()->findOrFail($id) : null;
            $previousType = (string) ($existingItem?->default_assignee_type ?: 'user');
            $nextType = ($data['default_assignee_type'] ?? $previousType) === 'supplier' ? 'supplier' : 'user';
            $previousDefaultAssigneeId = $existingItem?->default_assignee_id ? (int) $existingItem->default_assignee_id : null;
            $previousDefaultDepartmentId = $existingItem?->default_department_id ? (int) $existingItem->default_department_id : null;

            $sort = array_key_exists('sort_order', $data)
                ? max(0, (int) $data['sort_order'])
                : ($id ? (int) $existingItem->sort_order : ((int) $pack->items()->max('sort_order') + 1));
            $payload = [
                'task_pack_id' => $pack->id,
                'title' => trim($data['title']),
                'description' => blank($data['description'] ?? null) ? null : trim($data['description']),
                'default_assignee_type' => $nextType,
                'default_assignee_id' => $data['default_assignee_id'] ?? null,
                'default_department_id' => $nextType === 'supplier' ? null : ($data['default_department_id'] ?? null),
                'priority_id' => $data['priority_id'] ?? null,
                'document_category_id' => $data['document_category_id'] ?? null,
                'due_offset_days' => max(0, (int) ($data['due_offset_days'] ?? 1)),
                'standard_duration_value' => max(0.01, (float) ($data['standard_duration_value'] ?? $existingItem?->standard_duration_value ?? 8)),
                'standard_duration_unit' => trim((string) ($data['standard_duration_unit'] ?? $existingItem?->standard_duration_unit ?? 'TPD-001')),
                'timer_start_rule' => trim((string) ($data['timer_start_rule'] ?? $existingItem?->timer_start_rule ?? 'TPS-001')),
                'timer_stop_rule' => trim((string) ($data['timer_stop_rule'] ?? $existingItem?->timer_stop_rule ?? 'TPE-001')),
                'work_calendar' => trim((string) ($data['work_calendar'] ?? $existingItem?->work_calendar ?? 'TPW-001')),
                'set_due_from_standard_duration' => array_key_exists('set_due_from_standard_duration', $data)
                    ? (bool) $data['set_due_from_standard_duration']
                    : ($existingItem ? (bool) $existingItem->set_due_from_standard_duration : true),
                'allow_efficiency_override' => array_key_exists('allow_efficiency_override', $data)
                    ? (bool) $data['allow_efficiency_override']
                    : ($existingItem ? (bool) $existingItem->allow_efficiency_override : false),
                'is_required' => (bool) ($data['is_required'] ?? true),
                'sort_order' => $sort,
            ];

            if ($this->taskPackItemColumnExists('color')) {
                $payload['color'] = MasterColor::normalize((string) ($data['color'] ?? $existingItem?->color ?? '#2563EB')) ?: '#2563EB';
            }

            // These fields support the protected Order workflow runtime. Keep the
            // generic Task Pack service backward-compatible during deployments
            // where PHP code is updated before the migration has finished. The
            // idempotent migration adds the columns; until then saves no longer
            // crash with SQLSTATE[42S22].
            if ($this->taskPackItemColumnExists('automation_key')) {
                $payload['automation_key'] = array_key_exists('automation_key', $data)
                    ? (blank($data['automation_key']) ? null : trim((string) $data['automation_key']))
                    : ($existingItem?->automation_key ?: null);
            }

            if ($this->taskPackItemColumnExists('document_required_before_completion')) {
                $payload['document_required_before_completion'] = array_key_exists('document_required_before_completion', $data)
                    ? (bool) $data['document_required_before_completion']
                    : ($existingItem ? (bool) ($existingItem->document_required_before_completion ?? true) : true);
            }
            if ($this->taskPackItemColumnExists('allow_multiple_documents')) {
                $payload['allow_multiple_documents'] = array_key_exists('allow_multiple_documents', $data)
                    ? (bool) $data['allow_multiple_documents']
                    : ($existingItem ? (bool) ($existingItem->allow_multiple_documents ?? false) : false);
            }
            if ($this->taskPackItemColumnExists('document_instructions')) {
                $payload['document_instructions'] = array_key_exists('document_instructions', $data)
                    ? (blank($data['document_instructions']) ? null : trim((string) $data['document_instructions']))
                    : ($existingItem?->document_instructions ?: null);
            }

            $item = TaskPackItem::query()->updateOrCreate(['id' => $id], $payload);
            $this->mirrorLegacyItem($item);

            // Task Pack is the single source of truth for required documents.
            // Keep already generated tasks synchronized when this requirement is
            // added, changed or removed from the Task Pack.
            Task::query()->where('task_pack_task_id', $item->id)->update([
                'title' => $item->title,
                'description' => $item->description,
                'document_category_id' => $item->document_category_id ?: null,
                'document_requirement_source' => $item->document_category_id ? 'task_pack' : null,
            ]);

            // Updating a title, color, timing or document option must not scan
            // every generated task in the system. Re-resolve generated task
            // assignees only when the Task Pack's assignment source changed.
            $nextDefaultAssigneeId = $item->default_assignee_id ? (int) $item->default_assignee_id : null;
            $nextDefaultDepartmentId = $item->default_department_id ? (int) $item->default_department_id : null;
            $assignmentChanged = $previousDefaultAssigneeId !== $nextDefaultAssigneeId
                || $previousDefaultDepartmentId !== $nextDefaultDepartmentId
                || $previousType !== $nextType;

            if ($assignmentChanged) {
                $this->syncGeneratedTaskAssignees($item->fresh(), $previousDefaultAssigneeId);
                // A change in the Task Pack default updates only matching generated tasks.
                app(\App\Services\SupplierBridge\SupplierDefaultTaskAssigner::class)->syncForTaskPackItem($item);
            }

            return $item;
        });

        // Generated Order tasks are already synchronized selectively above.
        // Mark only summaries that reference this Task Pack item so the next
        // Order Details read recomputes its small next-action/progress payload.
        app(\App\Services\Orders\OrderWorkflowSummaryService::class)
            ->markStaleForTaskPackItem((int) $item->id);

        if ($publishOrderWorkflows) {
            // Editing an existing Task Pack item already invalidates only Orders
            // generated from that item above. A brand-new item is structural: it
            // has no generated Task rows yet, so mapped workflow summaries must be
            // invalidated until the existing lazy binding sync creates that task.
            $this->publishMappedOrderWorkflows((int) $pack->id, $id === null);
        }
        return $item;
    }


    /** @var array<string, bool> */
    private static array $taskPackItemColumnCache = [];

    private function taskPackItemColumnExists(string $column): bool
    {
        return self::$taskPackItemColumnCache[$column]
            ??= Schema::hasTable('task_pack_items') && Schema::hasColumn('task_pack_items', $column);
    }

    private function syncGeneratedTaskAssignees(TaskPackItem $item, ?int $previousDefaultAssigneeId = null): void
    {
        if (!Schema::hasTable('tasks') || !Schema::hasColumn('tasks', 'assignee_id')) return;

        $item->loadMissing('defaultDepartment');
        $desiredAssigneeId = $item->default_assignee_id ? (int) $item->default_assignee_id : null;

        // A Task Pack may use a default department instead of a named user.
        // Resolve that once, then synchronize matching generated rows in bulk.
        if (!$desiredAssigneeId && $item->defaultDepartment && Schema::hasTable('departments')) {
            $legacyDepartmentId = DB::table('departments')
                ->where('code', $item->defaultDepartment->code)
                ->value('id');
            if ($legacyDepartmentId) {
                $desiredAssigneeId = DB::table('users')
                    ->where('is_active', true)
                    ->where('department_id', $legacyDepartmentId)
                    ->orderBy('id')
                    ->value('id');
                $desiredAssigneeId = $desiredAssigneeId ? (int) $desiredAssigneeId : null;
            }
        }

        $taskBase = Task::query()->where('task_pack_task_id', $item->id);
        $hasSetupAssignee = Schema::hasColumn('tasks', 'setup_assignee_id');

        $followingTaskIds = (clone $taskBase)
            ->where(function ($query) use ($previousDefaultAssigneeId, $hasSetupAssignee): void {
                $query->whereNull('assignee_id');
                if ($hasSetupAssignee) {
                    $query->orWhere(function ($sameSetup) {
                        $sameSetup->whereNotNull('setup_assignee_id')
                            ->whereColumn('assignee_id', 'setup_assignee_id');
                    });
                }
                if ($previousDefaultAssigneeId) {
                    $query->orWhere('assignee_id', $previousDefaultAssigneeId);
                }
            })
            ->pluck('id');

        if ($followingTaskIds->isNotEmpty()) {
            $jobIds = Task::query()
                ->whereIn('id', $followingTaskIds)
                ->whereNotNull('flow_job_id')
                ->distinct()
                ->pluck('flow_job_id')
                ->map(fn ($id) => (int) $id)
                ->values();

            $changes = ['assignee_id' => $desiredAssigneeId];
            if ($hasSetupAssignee) $changes['setup_assignee_id'] = $desiredAssigneeId;
            Task::query()->whereIn('id', $followingTaskIds)->update($changes);

            if ($desiredAssigneeId && $jobIds->isNotEmpty() && Schema::hasTable('flow_job_members')) {
                $now = now();
                $rows = $jobIds->map(fn (int $jobId) => [
                    'flow_job_id' => $jobId,
                    'user_id' => $desiredAssigneeId,
                    'access_level' => 'member',
                    'can_manage_tasks' => false,
                    'can_upload_documents' => true,
                    'can_view_financials' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('flow_job_members')->upsert(
                    $rows,
                    ['flow_job_id', 'user_id'],
                    ['access_level', 'can_manage_tasks', 'can_upload_documents', 'can_view_financials', 'updated_at']
                );
            }
        }

        // Inquiry taskflows use setup_assignee_id to distinguish Task Pack
        // assignment from a deliberate manual reassignment. Preserve manual
        // assignments while updating the setup value in bounded statements.
        if (Schema::hasTable('inquiry_tasks') && Schema::hasColumn('inquiry_tasks', 'setup_assignee_id')) {
            $inquiryBase = InquiryTask::query()->where('source_task_pack_item_id', $item->id);
            $followingInquiryIds = (clone $inquiryBase)
                ->where(function ($query) use ($previousDefaultAssigneeId): void {
                    $query->whereNull('assignee_id')
                        ->orWhere(function ($sameSetup) {
                            $sameSetup->whereNotNull('setup_assignee_id')
                                ->whereColumn('assignee_id', 'setup_assignee_id');
                        });
                    if ($previousDefaultAssigneeId) {
                        $query->orWhere('assignee_id', $previousDefaultAssigneeId);
                    }
                })
                ->pluck('id');

            if ($followingInquiryIds->isNotEmpty()) {
                InquiryTask::query()->whereIn('id', $followingInquiryIds)->update([
                    'assignee_id' => $desiredAssigneeId,
                    'setup_assignee_id' => $desiredAssigneeId,
                ]);
            }

            (clone $inquiryBase)->whereNotIn('id', $followingInquiryIds)->update([
                'setup_assignee_id' => $desiredAssigneeId,
            ]);
        }
    }

    public function deleteItem(int $id, bool $authorize = true): void
    {
        if ($authorize) $this->assertAction('delete');
        $item = TaskPackItem::findOrFail($id);
        $packId = (int) $item->task_pack_id;
        if (filled($item->automation_key) && $this->mappedOrderWorkflowIds($packId)->isNotEmpty()) {
            throw ValidationException::withMessages(['item' => 'Core Order automation tasks cannot be deleted. You can edit their title, assignee, timing and document settings.']);
        }
        if (Task::where('task_pack_task_id', $item->id)->exists()) {
            throw ValidationException::withMessages(['item' => 'This Task Pack item has generated Tasks and cannot be deleted.']);
        }
        DB::transaction(function () use ($item, $packId) {
            if (Schema::hasTable('task_pack_tasks')) TaskPackTask::whereKey($item->id)->delete();
            $item->delete();
            $this->normalize($packId);
        });
        $this->publishMappedOrderWorkflows($packId);
    }

    public function moveItem(int $id, int $direction): void
    {
        $this->assertAction('edit');
        $packId = 0;
        DB::transaction(function () use ($id, $direction, &$packId) {
            $item = TaskPackItem::findOrFail($id);
            $packId = (int) $item->task_pack_id;
            $items = TaskPackItem::where('task_pack_id', $packId)->orderBy('sort_order')->orderBy('id')->get()->values();
            $index = $items->search(fn ($row) => $row->id === $item->id);
            $target = $index + $direction;
            if ($index === false || $target < 0 || $target >= $items->count()) return;

            $projected = $items->pluck('id')->map(fn ($rowId) => (int) $rowId)->all();
            [$projected[$index], $projected[$target]] = [$projected[$target], $projected[$index]];
            $this->assertOrderPackCoreSequence($packId, $projected);

            $a = $items[$index]; $b = $items[$target]; $tmp = $a->sort_order;
            $a->update(['sort_order' => 999999]);
            $b->update(['sort_order' => $tmp]);
            $a->update(['sort_order' => $target]);
            $this->normalize($packId);
        });
        if ($packId) $this->publishMappedOrderWorkflows($packId);
    }

    /** @return Collection<int,int> */
    private function mappedOrderWorkflowIds(int $packId): Collection
    {
        return WorkflowPhase::query()
            ->where('task_pack_id', $packId)
            ->whereNotNull('workflow_template_id')
            ->whereHas('workflowTemplate', fn ($query) => $query
                ->where('workspace_id', app(WorkflowService::class)->workspaceId())
                ->where('applies_to', 'orders'))
            ->pluck('workflow_template_id')
            ->filter()
            ->map(fn ($workflowId) => (int) $workflowId)
            ->unique()
            ->values();
    }

    private function publishMappedOrderWorkflows(int $packId, bool $invalidateWorkflowSummaries = true): void
    {
        $refreshed = false;

        foreach ($this->mappedOrderWorkflowIds($packId) as $workflowId) {
            try {
                $orderService = app(OrderWorkflowSetupService::class);
                if (! $orderService->isReadyForOrderCreation((int) $workflowId)) continue;

                // Do not synchronously call publishWorkflow() here. Publishing
                // an Order workflow runs syncActiveOrders(), which can rebuild
                // hundreds of imported live Orders inside one Livewire request
                // and exceed PHP's execution limit. Normal Task Pack edits
                // already synchronize generated task fields directly. Structural
                // changes are repaired lazily when an Order is opened and can be
                // bulk-applied explicitly with flowtrack:sync-order-workflow.
                $orderService->ensureRuntimeMirror((int) $workflowId);
                if ($invalidateWorkflowSummaries) {
                    app(\App\Services\Orders\OrderWorkflowSummaryService::class)
                        ->markStaleForWorkflow((int) $workflowId);
                }
                $refreshed = true;
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($refreshed) {
            app(WorkspaceRefreshService::class)->touch('TaskPackSetup:order-definition-updated');
        }
    }

    /**
     * Extra custom tasks may be inserted anywhere, but the protected Order
     * automation tasks must remain present and in their original relative order.
     */
    private function assertOrderPackCoreSequence(int $packId, array $orderedItemIds): void
    {
        $phases = WorkflowPhase::query()
            ->where('task_pack_id', $packId)
            ->whereNotNull('workflow_template_id')
            ->whereHas('workflowTemplate', fn ($query) => $query
                ->where('workspace_id', app(WorkflowService::class)->workspaceId())
                ->where('applies_to', 'orders'))
            ->get(['id', 'sequence']);
        if ($phases->isEmpty()) return;

        $keysById = TaskPackItem::query()
            ->where('task_pack_id', $packId)
            ->whereIn('id', $orderedItemIds)
            ->pluck('automation_key', 'id');
        $actualCoreKeys = collect($orderedItemIds)
            ->map(fn ($itemId) => $keysById[(int) $itemId] ?? null)
            ->filter()
            ->values()
            ->all();

        foreach ($phases as $phase) {
            $expected = OrderWorkflowSetupService::automationKeysForStage((int) $phase->sequence);
            if (! $expected) continue;
            $filtered = array_values(array_filter($actualCoreKeys, fn ($key) => in_array($key, $expected, true)));
            if ($filtered !== $expected) {
                throw ValidationException::withMessages([
                    'tasks' => 'Core Order automation tasks cannot be removed or reordered relative to each other. Extra tasks may be added anywhere.',
                ]);
            }
        }
    }

    public function syncLegacy(): void
    {
        if (!Schema::hasTable('task_pack_items')) return;
        app(MasterDataService::class)->syncLegacy();
        $workspaceId = $this->workspaceId();
        foreach (TaskPack::query()->where('workspace_id', $workspaceId)->where('is_snapshot', false)->where(fn ($q) => $q->whereNull('code')->orWhere('code', ''))->get() as $pack) {
            $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($pack->slug ?: $pack->name)), 0, 8)) ?: 'PACK';
            $pack->update(['code' => $base.'-'.$pack->id]);
        }
        if (!Schema::hasTable('task_pack_tasks')) return;
        $medium = MasterRecord::where('workspace_id', $workspaceId)->where('type', 'priority')->where('code', 'MED')->value('id');
        foreach (TaskPackTask::query()->orderBy('id')->get() as $legacy) {
            TaskPackItem::firstOrCreate(['id' => $legacy->id], [
                'task_pack_id' => $legacy->task_pack_id,
                'title' => $legacy->title,
                'color' => Schema::hasColumn('task_pack_tasks', 'color')
                    ? (MasterColor::normalize((string) ($legacy->color ?? '')) ?: '#2563EB')
                    : '#2563EB',
                'priority_id' => $medium,
                'due_offset_days' => max(1, (int) $legacy->sequence),
                'is_required' => $legacy->is_required,
                'sort_order' => max(0, (int) $legacy->sequence - 1),
            ]);
        }

        $this->syncLegacyDocumentRequirements();
    }

    private function syncLegacyDocumentRequirements(): void
    {
        if (!Schema::hasTable('task_pack_items')) return;

        // Preserve document requirements already carried by generated tasks.
        if (Schema::hasTable('tasks') && Schema::hasColumn('tasks', 'document_category_id')) {
            Task::query()
                ->whereNotNull('task_pack_task_id')
                ->whereNotNull('document_category_id')
                ->when(Schema::hasColumn('tasks', 'document_requirement_source'), fn ($q) => $q->where('document_requirement_source', 'task_pack'))
                ->select(['task_pack_task_id','document_category_id'])
                ->distinct()
                ->get()
                ->each(function ($task) {
                    TaskPackItem::query()
                        ->whereKey($task->task_pack_task_id)
                        ->whereNull('document_category_id')
                        ->update(['document_category_id' => $task->document_category_id]);
                });
        }

        // Legacy workflow phases once carried a required document directly.
        // Migrate it into the mapped Task Pack only when that pack has no
        // explicit document requirement yet. After this, Job logic reads the
        // Task Pack only.
        if (!Schema::hasTable('workflow_phases')) return;
        WorkflowPhase::query()->whereNotNull('task_pack_id')->orderBy('id')->get()->each(function ($phase) {
            $documentId = $this->resolveLegacyDocumentCategoryId(
                $phase->document_category_id ?? null,
                Schema::hasColumn('workflow_phases', 'required_document') ? ($phase->required_document ?? null) : null
            );
            if (!$documentId) return;

            $items = TaskPackItem::query()->where('task_pack_id', $phase->task_pack_id)->orderBy('sort_order')->orderBy('id')->get();
            if ($items->isEmpty() || $items->contains(fn ($item) => filled($item->document_category_id))) return;

            $candidate = $items->first(fn ($item) => preg_match('/upload|document|file|attach|submit|confirmation|quotation|invoice|approval|\bpo\b/i', (string) $item->title))
                ?: $items->firstWhere('is_required', true)
                ?: $items->first();
            if (!$candidate) return;

            $candidate->update(['document_category_id' => $documentId]);
            Task::query()->where('task_pack_task_id', $candidate->id)->update([
                'document_category_id' => $documentId,
                'document_requirement_source' => 'task_pack',
            ]);
        });
    }

    /**
     * Ensure every modern task_pack_items row has the legacy task_pack_tasks
     * mirror with the same primary key. Runtime Order tasks still carry a
     * foreign key to task_pack_tasks.id, so a direct SQL insert into
     * task_pack_items without the mirror would make task generation fail.
     *
     * Returns true only when a repair/normalization was required.
     */
    public function ensureLegacyMirrorsForPack(TaskPack $pack): bool
    {
        if (! Schema::hasTable('task_pack_items') || ! Schema::hasTable('task_pack_tasks')) {
            return false;
        }

        $itemIds = TaskPackItem::query()
            ->where('task_pack_id', $pack->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($itemIds->isEmpty()) {
            return false;
        }

        $legacyIds = TaskPackTask::query()
            ->where('task_pack_id', $pack->id)
            ->whereIn('id', $itemIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        if ($itemIds->diff($legacyIds)->isEmpty()) {
            return false;
        }

        DB::transaction(fn () => $this->normalize((int) $pack->id));

        return true;
    }

    private function normalize(int $packId): void
    {
        if (Schema::hasTable('task_pack_tasks')) {
            TaskPackTask::where('task_pack_id', $packId)->get()->each(fn ($row) => $row->update(['sequence' => 10000 + $row->id]));
        }
        TaskPackItem::where('task_pack_id', $packId)->orderBy('sort_order')->orderBy('id')->get()->values()->each(function ($item, $index) {
            $item->update(['sort_order' => $index]);
            $this->mirrorLegacyItem($item->fresh());
        });
    }

    private function mirrorLegacyItem(TaskPackItem $item): void
    {
        if (!Schema::hasTable('task_pack_tasks')) return;

        $legacyDepartmentId = null;
        if ($item->default_department_id && Schema::hasTable('departments')) {
            $master = MasterRecord::find($item->default_department_id);
            if ($master) $legacyDepartmentId = DB::table('departments')->where('code', $master->code)->value('id');
        }

        $sequence = max(1, (int) $item->sort_order + 1);

        // task_pack_tasks is kept only for backwards compatibility. Older data
        // can contain a row occupying the sequence we are about to mirror. Move
        // that stale row out of the active sequence range first so editing a
        // modern Task Pack can never fail on the legacy unique index.
        $conflict = TaskPackTask::query()
            ->where('task_pack_id', $item->task_pack_id)
            ->where('sequence', $sequence)
            ->whereKeyNot($item->id)
            ->first();

        if ($conflict) {
            $conflict->update(['sequence' => 50000 + (int) $conflict->id]);
        }

        $legacyPayload = [
            'task_pack_id' => $item->task_pack_id,
            'title' => $item->title,
            'sequence' => $sequence,
            'is_required' => $item->is_required,
            'default_department_id' => $legacyDepartmentId,
        ];
        if (Schema::hasColumn('task_pack_tasks', 'color')) {
            $legacyPayload['color'] = MasterColor::normalize((string) ($item->color ?? '')) ?: '#2563EB';
        }

        TaskPackTask::query()->updateOrCreate(['id' => $item->id], $legacyPayload);
    }
    private function taskPackTemplateIds(int $packId): array
    {
        $ids = TaskPackItem::query()->where('task_pack_id', $packId)->pluck('id');

        if (Schema::hasTable('task_pack_tasks')) {
            $ids = $ids->merge(
                TaskPackTask::query()->where('task_pack_id', $packId)->pluck('id')
            );
        }

        return $ids
            ->map(fn ($templateId) => (int) $templateId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function assertAction(string $action): void
    {
        $user = auth()->user();
        abort_unless($user && app(AccessControlService::class)->can($user, 'taskpacks', $action), 403);
    }

}
