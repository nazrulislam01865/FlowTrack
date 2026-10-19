<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\TaskPack;
use App\Models\Task;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Support\MasterColor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkflowService
{
    public function workspaceId(): int { return app(SetupContext::class)->workspaceId(); }

    public function all()
    {
        return WorkflowTemplate::query()
            ->where('workspace_id', $this->workspaceId())
            ->with(['phases.taskPack'])
            ->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function saveWorkflow(array $data, ?int $id = null, bool $authorize = true): WorkflowTemplate
    {
        if ($authorize) $this->assertAction($id ? 'edit' : 'create');
        $workspaceId = $this->workspaceId();
        $code = strtoupper(trim($data['code']));
        if (WorkflowTemplate::where('workspace_id', $workspaceId)->where('code', $code)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['workflowCode' => 'This workflow code already exists.']);
        }

        $workflow = DB::transaction(function () use ($data, $id, $workspaceId, $code) {
            $scopeUpdates = [];
            if (array_key_exists('applies_to', $data)) {
                $scopeUpdates['applies_to'] = in_array($data['applies_to'], ['inquiries', 'orders'], true)
                    ? $data['applies_to']
                    : 'orders';
            }
            if (array_key_exists('client_availability', $data)) {
                $scopeUpdates['client_availability'] = $data['client_availability'] === 'specific' ? 'specific' : 'all';
            }

            if ($id) {
                $template = WorkflowTemplate::where('workspace_id', $workspaceId)->findOrFail($id);
                $template->update(array_merge([
                    'code' => $code,
                    'name' => trim($data['name']),
                    'description' => blank($data['description'] ?? null) ? null : trim($data['description']),
                    'is_active' => (bool) ($data['is_active'] ?? true),
                    'version' => max(1, (int) ($data['version'] ?? 1)),
                ], $scopeUpdates));

                if (array_key_exists('client_ids', $data)) {
                    $template->clients()->sync(($template->client_availability ?? 'all') === 'specific'
                        ? array_values(array_unique(array_map('intval', $data['client_ids'] ?? [])))
                        : []);
                }

                if (Schema::hasTable('workflows')) {
                    Workflow::updateOrCreate(['id' => $template->id], [
                        'name' => $template->name,
                        'slug' => Str::slug($template->name).'-'.strtolower($template->code),
                        'description' => $template->description,
                        'is_active' => $template->is_active,
                    ]);
                }
                return $template;
            }

            // If every Workflow was deleted, the next Workflow created becomes
            // the default automatically. This keeps the setup usable without forcing
            // the user through a separate "Set Default" step.
            $shouldBeDefault = !WorkflowTemplate::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_default', true)
                ->exists();
            $isActive = $shouldBeDefault ? true : (bool) ($data['is_active'] ?? true);

            $legacyId = null;
            if (Schema::hasTable('workflows')) {
                $legacy = Workflow::create([
                    'name' => trim($data['name']),
                    'slug' => Str::slug($data['name']).'-'.strtolower($code).'-'.Str::lower(Str::random(4)),
                    'description' => blank($data['description'] ?? null) ? null : trim($data['description']),
                    'is_active' => $isActive,
                ]);
                $legacyId = $legacy->id;
            }

            $template = WorkflowTemplate::create(array_merge([
                'id' => $legacyId,
                'workspace_id' => $workspaceId,
                'code' => $code,
                'name' => trim($data['name']),
                'description' => blank($data['description'] ?? null) ? null : trim($data['description']),
                'is_active' => $isActive,
                'is_default' => $shouldBeDefault,
                'version' => max(1, (int) ($data['version'] ?? 1)),
                'applies_to' => 'orders',
                'client_availability' => 'all',
            ], $scopeUpdates));

            if (array_key_exists('client_ids', $data) && $template->client_availability === 'specific') {
                $template->clients()->sync(array_values(array_unique(array_map('intval', $data['client_ids'] ?? []))));
            }

            return $template;
        });

        $this->invalidateBoardWorkflowCache($workspaceId, (int) $workflow->id);

        // Client availability is stored in a pivot table, so a pivot-only edit
        // may not make WorkflowTemplate itself dirty and therefore may not fire
        // an Eloquent observer. Publish one explicit invalidation for the whole
        // save operation. WorkspaceRefreshService coalesces duplicate signals.
        app(WorkspaceRefreshService::class)->touch('WorkflowTemplate:saved');

        return $workflow;
    }


    public function copyPhases(WorkflowTemplate $source, WorkflowTemplate $target): void
    {
        $this->assertAction('create');
        DB::transaction(function () use ($source, $target) {
            foreach ($source->phases()->orderBy('sequence')->get() as $phase) {
                $this->savePhase($target, [
                    'name' => $phase->name,
                    'short_name' => $phase->short_name,
                    'color' => $phase->color,
                    'task_pack_id' => $phase->task_pack_id,
                    'document_category_id' => $phase->document_category_id,
                    'allow_job_start' => (bool) $phase->allow_job_start,
                    'is_skippable' => (bool) $phase->is_skippable,
                    'requires_approval' => (bool) $phase->requires_approval,
                    'auto_advance_on_ready' => (bool) $phase->auto_advance_on_ready,
                    'is_active' => (bool) $phase->is_active,
                    'entry_condition' => $phase->entry_condition,
                    'exit_condition' => $phase->exit_condition,
                    'sequence' => (int) $phase->sequence,
                ], null, false);
            }
        });
    }

    public function setDefault(int $id): void
    {
        $this->assertAction('edit');
        $workspaceId = $this->workspaceId();
        DB::transaction(function () use ($id, $workspaceId) {
            WorkflowTemplate::where('workspace_id', $workspaceId)->update(['is_default' => false]);
            $workflow = WorkflowTemplate::where('workspace_id', $workspaceId)->findOrFail($id);
            $workflow->update(['is_default' => true, 'is_active' => true]);
            if (Schema::hasTable('workflows')) Workflow::whereKey($id)->update(['is_active' => true]);
        });
        $this->invalidateBoardWorkflowCache($workspaceId, $id);
    }

    public function toggleWorkflow(int $id): void
    {
        $this->assertAction('edit');
        $workflow = WorkflowTemplate::where('workspace_id', $this->workspaceId())->findOrFail($id);
        if ($workflow->is_default && $workflow->is_active) {
            throw ValidationException::withMessages(['workflow' => 'The default workflow cannot be deactivated. Set another default first.']);
        }
        $workflow->update(['is_active' => !$workflow->is_active]);
        if (Schema::hasTable('workflows')) Workflow::whereKey($id)->update(['is_active' => $workflow->is_active]);
        $this->invalidateBoardWorkflowCache($this->workspaceId(), $id);
    }

    /**
     * Build the destructive-delete preview only when the user asks to delete a
     * Workflow. This deliberately stays out of render() so normal setup loads
     * do not pay for dependency scans.
     */
    public function workflowDeleteImpact(int $id): array
    {
        $this->assertAction('delete');
        $workflow = WorkflowTemplate::query()
            ->where('workspace_id', $this->workspaceId())
            ->findOrFail($id);

        $phases = $this->workflowPhases($id);
        $phaseIds = $phases->pluck('id')->map(fn ($phaseId) => (int) $phaseId)->values()->all();
        $legacyJobIds = $this->workflowLinkedJobIds($id, $phaseIds);

        $jobsBase = FlowJob::withTrashed()
            ->where(function ($query) use ($id, $legacyJobIds) {
                $query->where('source_workflow_id', $id);
                if ($legacyJobIds) $query->orWhereIn('id', $legacyJobIds);
            });

        $jobCount = (clone $jobsBase)->count();
        $taskCount = $jobCount === 0
            ? 0
            : Task::withTrashed()
                ->whereIn('flow_job_id', (clone $jobsBase)->select('id'))
                ->count();

        $jobs = (clone $jobsBase)
            ->orderBy('job_number')
            ->limit(8)
            ->get(['id', 'job_number', 'title', 'workflow_id', 'source_workflow_id', 'deleted_at']);

        $jobNumbers = $jobs->pluck('job_number', 'id');
        $tasks = $jobCount === 0
            ? collect()
            : Task::withTrashed()
                ->whereIn('flow_job_id', (clone $jobsBase)->select('id'))
                ->orderBy('task_number')
                ->limit(8)
                ->get(['id', 'task_number', 'title', 'flow_job_id'])
                ->map(fn (Task $task) => [
                    'id' => (int) $task->id,
                    'task_number' => (string) $task->task_number,
                    'title' => (string) $task->title,
                    'job_number' => (string) ($jobNumbers->get($task->flow_job_id) ?? ''),
                ]);

        // A default Workflow can be deleted too. If another reusable Workflow
        // exists, FlowTrack promotes it automatically during the delete transaction.
        // This keeps production data (which may contain older/inactive Workflows)
        // from blocking deletion while still preserving the one-default invariant.
        $replacementDefault = null;
        if ($workflow->is_default) {
            $replacementDefault = WorkflowTemplate::query()
                ->where('workspace_id', $this->workspaceId())
                ->whereKeyNot($workflow->id)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->first(['id', 'name', 'is_active']);
        }

        return [
            'id' => (int) $workflow->id,
            'name' => (string) $workflow->name,
            'is_default' => (bool) $workflow->is_default,
            'can_delete' => true,
            'blocked_reason' => null,
            'will_leave_no_default' => (bool) $workflow->is_default && !$replacementDefault,
            'replacement_default' => $replacementDefault ? [
                'id' => (int) $replacementDefault->id,
                'name' => (string) $replacementDefault->name,
                'was_active' => (bool) $replacementDefault->is_active,
            ] : null,
            'phase_count' => $phases->count(),
            'phases' => $phases->take(8)->map(fn (WorkflowPhase $phase) => [
                'id' => (int) $phase->id,
                'name' => (string) $phase->name,
                'sequence' => (int) $phase->sequence,
            ])->all(),
            'job_count' => $jobCount,
            'jobs' => $jobs->map(fn (FlowJob $job) => [
                'id' => (int) $job->id,
                'job_number' => (string) $job->displayOrderNumber(),
                'title' => (string) $job->title,
                'trashed' => $job->deleted_at !== null,
                'already_snapshotted' => (int) $job->workflow_id !== (int) $id,
            ])->all(),
            'task_count' => $taskCount,
            'tasks' => $tasks->all(),
            'legacy_job_count' => count($legacyJobIds),
        ];
    }

    /**
     * Delete only the reusable setup Workflow. Jobs are first detached into
     * private snapshots when necessary; no Job or Task is deleted.
     */
    public function deleteWorkflow(int $id): array
    {
        $this->assertAction('delete');
        $workspaceId = $this->workspaceId();
        $workflow = WorkflowTemplate::where('workspace_id', $workspaceId)->findOrFail($id);

        $phaseIds = $this->workflowPhases($id)->pluck('id')->map(fn ($phaseId) => (int) $phaseId)->all();
        $legacyJobIds = $this->workflowLinkedJobIds($id, $phaseIds);
        $protectedJobs = 0;

        try {
            DB::transaction(function () use ($workflow, $workspaceId, $phaseIds, $legacyJobIds, &$protectedJobs) {
                // Re-read and lock the reusable Workflow so two concurrent admin
                // actions cannot leave the workspace with an accidental default gap.
                $lockedWorkflow = WorkflowTemplate::query()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($workflow->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedWorkflow->is_default) {
                    $replacement = WorkflowTemplate::query()
                        ->where('workspace_id', $workspaceId)
                        ->whereKeyNot($lockedWorkflow->id)
                        ->orderByDesc('is_active')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    if ($replacement) {
                        // Normalize any older/corrupt cloud data with multiple default
                        // flags, then promote one deterministic replacement.
                        WorkflowTemplate::query()
                            ->where('workspace_id', $workspaceId)
                            ->whereKeyNot($lockedWorkflow->id)
                            ->update(['is_default' => false]);

                        $replacement->update(['is_default' => true, 'is_active' => true]);

                        if (Schema::hasTable('workflows')) {
                            Workflow::query()
                                ->whereKey($replacement->id)
                                ->where('is_snapshot', false)
                                ->update(['is_active' => true]);
                        }
                    }
                }

                if ($legacyJobIds) {
                    $protectedJobs = app(JobWorkflowSnapshotService::class)->snapshotJobs($legacyJobIds, $lockedWorkflow->id);
                }

                if ($phaseIds) {
                    WorkflowPhase::query()->whereIn('id', $phaseIds)->delete();
                }

                if (Schema::hasTable('workflows')) {
                    Workflow::query()->whereKey($lockedWorkflow->id)->where('is_snapshot', false)->delete();
                }

                $lockedWorkflow->delete();
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw ValidationException::withMessages([
                    'workflow' => 'FlowTrack could not safely detach every linked Job. Nothing was deleted. Refresh and try again.',
                ]);
            }
            throw $exception;
        }

        $this->invalidateBoardWorkflowCache($workspaceId, $id);

        return [
            'workflow_name' => (string) $workflow->name,
            'job_count' => $protectedJobs,
            'task_count' => 0,
        ];
    }

    public function savePhase(WorkflowTemplate $workflow, array $data, ?WorkflowPhase $phase = null, bool $authorize = true): WorkflowPhase
    {
        if ($authorize) $this->assertAction('edit');
        if (!empty($data['task_pack_id'])) {
            TaskPack::query()
                ->where('workspace_id', $this->workspaceId())
                ->where('is_snapshot', false)
                ->findOrFail((int) $data['task_pack_id']);
        }
        // Required documents now belong to Task Pack items. The phase form no
        // longer edits this legacy field, so preserve an existing value during
        // edits (and copies) rather than silently deleting old setup data.
        $documentCategoryId = array_key_exists('document_category_id', $data)
            ? ($data['document_category_id'] ?: null)
            : ($phase?->document_category_id ?: null);
        $document = $documentCategoryId ? MasterRecord::find($documentCategoryId) : null;

        // The phase modal does not expose sequence while editing. Preserve the
        // existing position for edits and append new phases to the end. This
        // keeps savePhase() safe for both the UI and service-level callers.
        if (!array_key_exists('sequence', $data) || $data['sequence'] === null || $data['sequence'] === '') {
            $data['sequence'] = $phase
                ? (int) $phase->sequence
                : (((int) $workflow->phases()->max('sequence')) + 1);
        }

        $phaseColor = null;
        if (Schema::hasColumn('workflow_phases', 'color')) {
            $phaseColor = MasterColor::normalize((string) ($data['color'] ?? $phase?->color ?? ''));
            if (! $phaseColor) {
                throw ValidationException::withMessages(['phaseColor' => 'Choose a valid phase color.']);
            }
        }

        $payload = [
            'workflow_template_id' => $workflow->id,
            'task_pack_id' => $data['task_pack_id'] ?? null,
            'document_category_id' => $documentCategoryId,
            'name' => trim($data['name']),
            'short_name' => trim($data['short_name']),
            'sequence' => (int) $data['sequence'],
            // These controls are automatic in FlowTrack now. Keeping the values
            // enforced in the service also protects imports/copies/API callers
            // from accidentally creating a phase with different behavior.
            'allow_job_start' => true,
            'is_skippable' => true,
            'requires_approval' => (bool) ($data['requires_approval'] ?? false),
            'auto_advance_on_ready' => true,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'entry_condition' => blank($data['entry_condition'] ?? null) ? null : trim($data['entry_condition']),
            'exit_condition' => blank($data['exit_condition'] ?? null) ? null : trim($data['exit_condition']),
        ];
        if (Schema::hasColumn('workflow_phases', 'color')) $payload['color'] = $phaseColor;
        if (Schema::hasColumn('workflow_phases', 'workflow_id')) $payload['workflow_id'] = $workflow->id;
        if (Schema::hasColumn('workflow_phases', 'can_skip')) $payload['can_skip'] = true;
        if (Schema::hasColumn('workflow_phases', 'required_document')) $payload['required_document'] = $document?->name;
        if (Schema::hasColumn('workflow_phases', 'entry_rule')) $payload['entry_rule'] = blank($data['entry_condition'] ?? null) ? null : trim($data['entry_condition']);
        if (Schema::hasColumn('workflow_phases', 'exit_rule')) $payload['exit_rule'] = blank($data['exit_condition'] ?? null) ? null : trim($data['exit_condition']);

        $savedPhase = WorkflowPhase::query()->updateOrCreate(['id' => $phase?->id], $payload);

        // Phase color is presentation configuration, not historical workflow state.
        // Keep existing Order snapshot phases visually in sync with their source
        // setup phase so every phase label/row uses the configured master color.
        if (Schema::hasColumn('workflow_phases', 'color') && $phaseColor) {
            WorkflowPhase::query()
                ->where('source_workflow_phase_id', $savedPhase->id)
                ->update(['color' => $phaseColor]);
        }

        $this->invalidateBoardWorkflowCache($this->workspaceId(), (int) $workflow->id);

        return $savedPhase;
    }

    public function move(WorkflowPhase $phase, int $direction): void
    {
        $this->assertAction('edit');
        $workflowId = (int) ($phase->workflow_template_id ?: $phase->workflow_id);
        DB::transaction(function () use ($phase, $direction, $workflowId) {
            $targetSequence = $phase->sequence + $direction;
            if ($targetSequence < 1) return;
            $target = WorkflowPhase::where('workflow_template_id', $workflowId)->where('sequence', $targetSequence)->first();
            if (!$target) return;
            $original = $phase->sequence;
            $phase->update(['sequence' => 9999]);
            $target->update(['sequence' => $original]);
            $phase->update(['sequence' => $targetSequence]);
        });
        $this->invalidateBoardWorkflowCache($this->workspaceId(), $workflowId);
    }

    public function delete(WorkflowPhase $phase): void
    {
        $this->assertAction('delete');
        $workflowId = (int) ($phase->workflow_template_id ?: $phase->workflow_id);
        $jobIds = FlowJob::withTrashed()
            ->where(function ($query) use ($phase) {
                $query->where('workflow_phase_id', $phase->id)
                    ->orWhere('started_from_phase_id', $phase->id);
            })
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($phase, $jobIds, $workflowId) {
            if ($jobIds) app(JobWorkflowSnapshotService::class)->snapshotJobs($jobIds, $workflowId);


            $sequence = $phase->sequence;
            $phase->delete();
            WorkflowPhase::where('workflow_template_id', $workflowId)
                ->where('sequence', '>', $sequence)
                ->orderBy('sequence')
                ->get()
                ->each(fn ($row) => $row->update(['sequence' => $row->sequence - 1]));
        });
        $this->invalidateBoardWorkflowCache($this->workspaceId(), $workflowId);
    }

    public function syncLegacy(): void
    {
        if (!Schema::hasTable('workflows') || !Schema::hasTable('workflow_templates')) return;
        $workspaceId = $this->workspaceId();
        foreach (Workflow::query()->where('is_snapshot', false)->orderBy('id')->get() as $legacy) {
            WorkflowTemplate::firstOrCreate(['id' => $legacy->id], [
                'workspace_id' => $workspaceId,
                'code' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $legacy->slug ?: $legacy->name), 0, 20)) ?: 'WF'.$legacy->id,
                'name' => $legacy->name,
                'description' => $legacy->description,
                'is_active' => $legacy->is_active,
                'is_default' => false,
                'version' => 1,
            ]);
        }
        if (!WorkflowTemplate::where('workspace_id', $workspaceId)->where('is_default', true)->exists()) {
            WorkflowTemplate::where('workspace_id', $workspaceId)->where('is_active', true)->orderBy('id')->first()?->update(['is_default' => true]);
        }
        app(MasterDataService::class)->syncLegacy();
        if (Schema::hasColumn('workflow_phases', 'workflow_id')) {
            foreach (WorkflowPhase::query()->whereNotNull('workflow_id')->whereHas('workflow', fn ($workflow) => $workflow->where('is_snapshot', false))->get() as $phase) {
                $changes = [];
                if (!$phase->workflow_template_id) $changes['workflow_template_id'] = $phase->workflow_id;
                if (Schema::hasColumn('workflow_phases','can_skip')) $changes['is_skippable'] = (bool) $phase->can_skip;
                if (Schema::hasColumn('workflow_phases','entry_rule') && blank($phase->entry_condition)) $changes['entry_condition'] = $phase->entry_rule;
                if (Schema::hasColumn('workflow_phases','exit_rule') && blank($phase->exit_condition)) $changes['exit_condition'] = $phase->exit_rule;
                if (Schema::hasColumn('workflow_phases','required_document') && !$phase->document_category_id && filled($phase->required_document)) {
                    $changes['document_category_id'] = MasterRecord::where('workspace_id',$workspaceId)->where('type','document_category')->where('name',$phase->required_document)->value('id');
                }
                if ($changes) $phase->update($changes);
            }
        }
        $this->invalidateBoardWorkflowCache($workspaceId);
    }
    private function workflowPhases(int $workflowId)
    {
        return WorkflowPhase::query()
            ->where(function ($query) use ($workflowId) {
                $query->where('workflow_template_id', $workflowId);
                if (Schema::hasColumn('workflow_phases', 'workflow_id')) {
                    $query->orWhere('workflow_id', $workflowId);
                }
            })
            ->orderBy('sequence')
            ->get(['id', 'name', 'sequence']);
    }

    private function workflowLinkedJobIds(int $workflowId, array $phaseIds): array
    {
        $ids = FlowJob::withTrashed()
            ->where(function ($query) use ($workflowId, $phaseIds) {
                $query->where('workflow_id', $workflowId);
                if ($phaseIds) {
                    $query->orWhereIn('workflow_phase_id', $phaseIds)
                        ->orWhereIn('started_from_phase_id', $phaseIds);
                }
            })
            ->pluck('id');

        if ($phaseIds) {
            $ids = $ids
                ->merge(Task::withTrashed()->whereIn('workflow_phase_id', $phaseIds)->pluck('flow_job_id'));

            if (Schema::hasTable('flow_job_phase_histories')) {
                $ids = $ids->merge(
                    DB::table('flow_job_phase_histories')
                        ->whereIn('workflow_phase_id', $phaseIds)
                        ->pluck('flow_job_id')
                );
            }
        }

        return $ids
            ->map(fn ($jobId) => (int) $jobId)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function invalidateBoardWorkflowCache(int $workspaceId, ?int $workflowId = null): void
    {
        Cache::forget(BoardService::workflowOptionsCacheKey($workspaceId));

        $ordersStageKey = OrderListPrototypeService::stageDefinitionCacheKey($workspaceId);
        Cache::forget($ordersStageKey);
        if (DB::transactionLevel() > 0) {
            // A concurrent request could repopulate the cache before a workflow
            // transaction commits. Clear it once more after commit so Orders
            // never keep a pre-edit stage definition for the full TTL.
            DB::afterCommit(fn (): bool => Cache::forget($ordersStageKey));
        }

        if ($workflowId) {
            Cache::forget(BoardService::workflowPhaseCacheKey($workflowId));
        }
    }

    private function assertAction(string $action): void
    {
        $user = auth()->user();
        abort_unless($user && app(AccessControlService::class)->can($user, 'workflow', $action), 403);
    }

}
