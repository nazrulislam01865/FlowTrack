<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Application\Queries\FlowTrackOptionsQuery;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\WorkflowPhase;
use App\Modules\OrderFlow\Data\Models\WorkflowTemplate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorkflowController extends Controller
{
    public function index(Request $request, FlowTrackOptionsQuery $options): Response
    {
        $workspaceId = $request->user()->currentWorkspaceId();

        return Inertia::render('flowtrack/WorkflowSetup/Index', [
            'workflows' => WorkflowTemplate::query()->where('workspace_id', $workspaceId)->with(['phases.taskPack.items', 'phases.documentCategory'])->orderByDesc('is_default')->orderBy('name')->get(),
            'taskPacks' => TaskPack::query()->where('workspace_id', $workspaceId)->with('items')->orderBy('name')->get(),
            'options' => $options->forWorkspace($request->user()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:40', Rule::unique('workflow_templates', 'code')->where('workspace_id', $workspaceId)],
            'description' => ['nullable', 'string', 'max:3000'],
            'base_workflow_id' => ['nullable', 'integer', Rule::exists('workflow_templates', 'id')->where('workspace_id', $workspaceId)],
        ]);

        DB::transaction(function () use ($workspaceId, $data) {
            $workflow = WorkflowTemplate::create([
                'workspace_id' => $workspaceId,
                'code' => strtoupper($data['code']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => false,
            ]);

            if ($baseId = $data['base_workflow_id'] ?? null) {
                WorkflowTemplate::query()->where('workspace_id', $workspaceId)->findOrFail($baseId)->phases()->orderBy('sequence')->get()->each(fn (WorkflowPhase $phase) => $workflow->phases()->create($phase->only([
                    'task_pack_id', 'document_category_id', 'name', 'short_name', 'sequence', 'allow_job_start', 'is_skippable', 'requires_approval', 'is_active', 'entry_condition', 'exit_condition',
                ])));
            }
        });

        return back()->with('success', 'Workflow created as draft.');
    }


    public function storePhase(Request $request, WorkflowTemplate $workflow): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($workflow->workspace_id === $workspaceId, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:50'],
            'task_pack_id' => ['nullable', 'integer', Rule::exists('task_packs', 'id')->where('workspace_id', $workspaceId)],
            'document_category_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::DocumentCategory->value))],
            'allow_job_start' => ['required', 'boolean'],
            'is_skippable' => ['required', 'boolean'],
            'requires_approval' => ['required', 'boolean'],
            'entry_condition' => ['nullable', 'string', 'max:255'],
            'exit_condition' => ['nullable', 'string', 'max:255'],
        ]);

        $workflow->phases()->create([
            ...$data,
            'sequence' => ((int) $workflow->phases()->max('sequence')) + 1,
            'is_active' => true,
        ]);

        return back()->with('success', 'Workflow phase added.');
    }

    public function updatePhase(Request $request, WorkflowPhase $phase): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($phase->workflow->workspace_id === $workspaceId, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:50'],
            'task_pack_id' => ['nullable', 'integer', Rule::exists('task_packs', 'id')->where('workspace_id', $workspaceId)],
            'document_category_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::DocumentCategory->value))],
            'allow_job_start' => ['required', 'boolean'],
            'is_skippable' => ['required', 'boolean'],
            'requires_approval' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
            'entry_condition' => ['nullable', 'string', 'max:255'],
            'exit_condition' => ['nullable', 'string', 'max:255'],
        ]);
        $phase->update($data);

        return back()->with('success', 'Workflow phase updated.');
    }


    public function destroyPhase(Request $request, WorkflowPhase $phase): RedirectResponse
    {
        abort_unless($phase->workflow->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_if($phase->tasks()->exists() || $phase->jobHistories()->exists() || $phase->approvals()->exists(), 422, 'This phase is already used by jobs and cannot be deleted. Disable it instead.');
        $phase->delete();

        return back()->with('success', 'Workflow phase deleted.');
    }

    public function reorder(Request $request, WorkflowTemplate $workflow): RedirectResponse
    {
        abort_unless($workflow->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $data = $request->validate(['phase_ids' => ['required', 'array'], 'phase_ids.*' => ['integer', Rule::exists('workflow_phases', 'id')->where('workflow_template_id', $workflow->id)]]);

        DB::transaction(function () use ($data, $workflow) {
            $temporaryOffset = ((int) $workflow->phases()->max('sequence')) + 1000;

            foreach ($data['phase_ids'] as $index => $phaseId) {
                WorkflowPhase::whereKey($phaseId)->update(['sequence' => $temporaryOffset + $index]);
            }

            foreach ($data['phase_ids'] as $index => $phaseId) {
                WorkflowPhase::whereKey($phaseId)->update(['sequence' => $index + 1]);
            }
        });

        return back()->with('success', 'Workflow sequence updated.');
    }

    public function publish(Request $request, WorkflowTemplate $workflow): RedirectResponse
    {
        abort_unless($workflow->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $workflow->update(['is_active' => true, 'version' => $workflow->version + 1]);

        return back()->with('success', 'Workflow published for new jobs.');
    }
}
