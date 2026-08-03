<?php

namespace App\Http\Controllers\FlowTrack\Api;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkflowController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $this->authorizeWorkflow($request, 'create');
        $workspaceId = $request->user()->currentWorkspaceId();
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);
        $data = $this->validateWorkflow($request, $workspaceId);

        $workflow = DB::transaction(function () use ($workspaceId, $data): WorkflowTemplate {
            $shouldActivate = (bool) ($data['is_active'] ?? false) && ! empty($data['base_workflow_id']);
            $workflow = WorkflowTemplate::create([
                'workspace_id' => $workspaceId,
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => false,
                'is_default' => false,
                'version' => 1,
            ]);

            if ($baseId = $data['base_workflow_id'] ?? null) {
                $base = WorkflowTemplate::query()
                    ->where('workspace_id', $workspaceId)
                    ->with(['phases' => fn ($query) => $query->orderBy('sequence')])
                    ->findOrFail($baseId);

                foreach ($base->phases as $phase) {
                    $workflow->phases()->create([
                        ...$phase->only([
                            'task_pack_id',
                            'document_category_id',
                            'name',
                            'short_name',
                            'sequence',
                            'allow_job_start',
                            'is_skippable',
                            'auto_advance_on_ready',
                            'is_active',
                            'entry_condition',
                            'exit_condition',
                        ]),
                        'requires_approval' => false,
                    ]);
                }
            }

            if ($shouldActivate) {
                $this->assertPublishable($workflow);
                $workflow->update(['is_active' => true]);
            }

            return $workflow;
        });

        return response()->json([
            'workflow' => $this->mapWorkflow($this->loadWorkflow($workflow)),
            'message' => 'Workflow created successfully.',
        ], 201);
    }

    public function update(Request $request, WorkflowTemplate $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, 'edit_all');
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($workflow->workspace_id === $workspaceId, 404);
        $request->merge(['code' => strtoupper((string) $request->input('code'))]);

        $data = $this->validateWorkflow($request, $workspaceId, $workflow);
        DB::transaction(function () use ($workflow, $data): void {
            $workflow->update([
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? $workflow->is_active,
            ]);

            if ($workflow->is_active) {
                $this->assertPublishable($workflow);
            }
        });

        return response()->json([
            'workflow' => $this->mapWorkflow($this->loadWorkflow($workflow)),
            'message' => 'Workflow details updated.',
        ]);
    }

    public function storePhase(Request $request, WorkflowTemplate $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, 'create');
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($workflow->workspace_id === $workspaceId, 404);

        $data = $this->validatePhase($request, $workspaceId);
        $phase = $workflow->phases()->create([
            ...$data,
            'requires_approval' => false,
            'sequence' => ((int) $workflow->phases()->max('sequence')) + 1,
        ]);

        return response()->json([
            'phase' => $this->mapPhase($phase->fresh(['taskPack.items', 'documentCategory'])),
            'workflow' => $this->mapWorkflow($this->loadWorkflow($workflow)),
            'message' => 'Workflow phase added.',
        ], 201);
    }

    public function updatePhase(Request $request, WorkflowPhase $phase, WorkflowAutomationService $automation): JsonResponse
    {
        $this->authorizeWorkflow($request, 'edit_all');
        $workspaceId = $request->user()->currentWorkspaceId();
        $phase->loadMissing('workflow');
        abort_unless($phase->workflow?->workspace_id === $workspaceId, 404);

        $data = $this->validatePhase($request, $workspaceId);
        DB::transaction(function () use ($phase, $data): void {
            $phase->update([...$data, 'requires_approval' => false]);
            if ($phase->workflow->is_active) {
                $this->assertPublishable($phase->workflow);
            }
        });

        FlowJob::query()
            ->where('workspace_id', $workspaceId)
            ->where('current_phase_id', $phase->id)
            ->where('status', '!=', 'completed')
            ->each(fn (FlowJob $job) => $automation->synchronize($job, $request->user(), 'workflow_configuration'));

        return response()->json([
            'phase' => $this->mapPhase($phase->fresh(['taskPack.items', 'documentCategory'])),
            'workflow' => $this->mapWorkflow($this->loadWorkflow($phase->workflow)),
            'message' => 'Workflow phase updated.',
        ]);
    }

    public function destroyPhase(Request $request, WorkflowPhase $phase): JsonResponse
    {
        $this->authorizeWorkflow($request, 'delete');
        $phase->loadMissing('workflow');
        abort_unless($phase->workflow?->workspace_id === $request->user()->currentWorkspaceId(), 404);

        if ($phase->workflow->phases()->count() <= 2) {
            throw ValidationException::withMessages([
                'phase' => 'A workflow must contain at least two phases.',
            ]);
        }

        if ($phase->tasks()->exists() || $phase->jobHistories()->exists()) {
            throw ValidationException::withMessages([
                'phase' => 'This phase is already used by Jobs and cannot be deleted. Disable it instead.',
            ]);
        }

        $workflow = $phase->workflow;
        DB::transaction(function () use ($phase, $workflow): void {
            $phase->delete();
            $this->normalizeSequence($workflow);
            if ($workflow->is_active) {
                $this->assertPublishable($workflow);
            }
        });

        return response()->json([
            'workflow' => $this->mapWorkflow($this->loadWorkflow($workflow)),
            'message' => 'Workflow phase removed.',
        ]);
    }

    public function reorder(Request $request, WorkflowTemplate $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, 'manage');
        abort_unless($workflow->workspace_id === $request->user()->currentWorkspaceId(), 404);

        $phaseCount = $workflow->phases()->count();
        $data = $request->validate([
            'phase_ids' => ['required', 'array', 'size:'.$phaseCount],
            'phase_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('workflow_phases', 'id')->where('workflow_template_id', $workflow->id),
            ],
        ]);

        DB::transaction(function () use ($data, $workflow): void {
            $temporaryOffset = ((int) $workflow->phases()->max('sequence')) + 1000;

            foreach ($data['phase_ids'] as $index => $phaseId) {
                WorkflowPhase::query()->whereKey($phaseId)->update(['sequence' => $temporaryOffset + $index]);
            }
            foreach ($data['phase_ids'] as $index => $phaseId) {
                WorkflowPhase::query()->whereKey($phaseId)->update(['sequence' => $index + 1]);
            }
        });

        return response()->json([
            'workflow' => $this->mapWorkflow($this->loadWorkflow($workflow)),
            'message' => 'Workflow sequence updated.',
        ]);
    }

    public function publish(Request $request, WorkflowTemplate $workflow): JsonResponse
    {
        $this->authorizeWorkflow($request, 'manage');
        abort_unless($workflow->workspace_id === $request->user()->currentWorkspaceId(), 404);

        $this->assertPublishable($workflow);

        $workflow->update([
            'is_active' => true,
            'version' => ((int) $workflow->version) + 1,
        ]);

        return response()->json([
            'workflow' => $this->mapWorkflow($this->loadWorkflow($workflow)),
            'message' => 'Workflow published successfully.',
        ]);
    }

    private function validateWorkflow(Request $request, int $workspaceId, ?WorkflowTemplate $workflow = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:40',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('workflow_templates', 'code')
                    ->where('workspace_id', $workspaceId)
                    ->ignore($workflow?->id),
            ],
            'description' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['sometimes', 'boolean'],
            'base_workflow_id' => [
                'nullable',
                'integer',
                Rule::exists('workflow_templates', 'id')->where('workspace_id', $workspaceId),
            ],
        ]);
    }

    private function validatePhase(Request $request, int $workspaceId): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:50'],
            'task_pack_id' => [
                'nullable',
                'integer',
                Rule::exists('task_packs', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)),
            ],
            'document_category_id' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', MasterDataType::DocumentCategory->value)
                    ->whereNull('deleted_at')),
            ],
            'allow_job_start' => ['required', 'boolean'],
            'is_skippable' => ['required', 'boolean'],
            'auto_advance_on_ready' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'entry_condition' => ['nullable', 'string', 'max:255'],
            'exit_condition' => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function loadWorkflow(WorkflowTemplate $workflow): WorkflowTemplate
    {
        return $workflow->fresh([
            'phases' => fn ($query) => $query->orderBy('sequence'),
            'phases.taskPack.items',
            'phases.documentCategory',
        ]);
    }

    private function mapWorkflow(WorkflowTemplate $workflow): array
    {
        return [
            'id' => $workflow->id,
            'code' => $workflow->code,
            'name' => $workflow->name,
            'description' => $workflow->description ?? '',
            'active' => (bool) $workflow->is_active,
            'isActive' => (bool) $workflow->is_active,
            'isDefault' => (bool) $workflow->is_default,
            'version' => (int) $workflow->version,
            'phases' => $workflow->phases->map(fn (WorkflowPhase $phase) => $this->mapPhase($phase))->values()->all(),
        ];
    }

    private function mapPhase(WorkflowPhase $phase): array
    {
        return [
            'id' => $phase->id,
            'name' => $phase->name,
            'short' => $phase->short_name,
            'shortName' => $phase->short_name,
            'taskPack' => $phase->taskPack?->name ?? '',
            'taskPackId' => $phase->task_pack_id,
            'taskCount' => $phase->taskPack?->items?->count() ?? 0,
            'document' => $phase->documentCategory?->name ?? '',
            'documentCategoryId' => $phase->document_category_id,
            'allowStart' => (bool) $phase->allow_job_start,
            'skippable' => (bool) $phase->is_skippable,
            'autoAdvance' => (bool) $phase->auto_advance_on_ready,
            'active' => (bool) $phase->is_active,
            'entry' => $phase->entry_condition ?? '',
            'exit' => $phase->exit_condition ?? '',
            'sequence' => (int) $phase->sequence,
        ];
    }


    private function assertPublishable(WorkflowTemplate $workflow): void
    {
        $activePhases = $workflow->phases()->where('is_active', true)->orderBy('sequence')->get();
        if ($activePhases->count() < 2) {
            throw ValidationException::withMessages([
                'workflow' => 'An active workflow must contain at least two active phases.',
            ]);
        }
        if (! $activePhases->contains(fn (WorkflowPhase $phase) => $phase->allow_job_start)) {
            throw ValidationException::withMessages([
                'workflow' => 'At least one active phase must allow a Job to start.',
            ]);
        }
    }

    private function normalizeSequence(WorkflowTemplate $workflow): void
    {
        $phaseIds = $workflow->phases()->orderBy('sequence')->pluck('id');
        $offset = ((int) $workflow->phases()->max('sequence')) + 1000;

        foreach ($phaseIds as $index => $phaseId) {
            WorkflowPhase::query()->whereKey($phaseId)->update(['sequence' => $offset + $index]);
        }
        foreach ($phaseIds as $index => $phaseId) {
            WorkflowPhase::query()->whereKey($phaseId)->update(['sequence' => $index + 1]);
        }
    }

    private function authorizeWorkflow(Request $request, string $action): void
    {
        abort_unless($request->user()?->hasFlowAction('workflow', $action), 403);
    }
}
