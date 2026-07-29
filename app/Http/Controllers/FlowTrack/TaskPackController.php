<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ConfigurationCodeGenerator;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Business\Services\WorkflowAutomationService;
use App\Modules\OrderFlow\Data\Models\FlowJob;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskPackController extends Controller
{
    public function index(Request $request, ConfigurationCodeGenerator $codes, FlowAuthorizationService $authorization): Response
    {
        $workspaceId = $request->user()->currentWorkspaceId();

        return Inertia::render('flowtrack/TaskPackSetup/Index', [
            'taskPacks' => TaskPack::query()
                ->where('workspace_id', $workspaceId)
                ->with(['items.defaultAssignee', 'items.defaultDepartment', 'items.priority', 'items.documentCategory'])
                ->orderBy('name')
                ->get(),
            'memberships' => WorkspaceMembership::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->with('user:id,name')
                ->orderBy('id')
                ->get(),
            'priorities' => MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', MasterDataType::Priority->value)
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'departments' => MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', MasterDataType::Department->value)
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'documentCategories' => MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->where('type', MasterDataType::DocumentCategory->value)
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'nextTaskPackCode' => $codes->taskPack($workspaceId),
            'canManage' => $authorization->allows($request->user(), 'workflow', 'edit_all'),
        ]);
    }
    public function store(Request $request, ConfigurationCodeGenerator $codes): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        TaskPack::create([
            ...$data,
            'workspace_id' => $workspaceId,
            'code' => $codes->taskPack($workspaceId),
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'Task pack created with an automatically generated code.');
    }

    public function update(Request $request, TaskPack $taskPack, WorkflowAutomationService $automation): RedirectResponse
    {
        abort_unless($taskPack->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['required', 'boolean'],
        ]);

        // The generated task-pack code is immutable.
        $taskPack->update($data);
        $this->synchronizeActiveJobs($taskPack, $request, $automation);

        return back()->with('success', 'Task pack updated.');
    }

    public function storeItem(Request $request, TaskPack $taskPack, WorkflowAutomationService $automation): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($taskPack->workspace_id === $workspaceId, 404);
        $data = $this->validateItem($request, $workspaceId);

        $taskPack->items()->create([
            ...$data,
            'sort_order' => ((int) $taskPack->items()->max('sort_order')) + 1,
        ]);
        $this->synchronizeActiveJobs($taskPack, $request, $automation);

        return back()->with('success', 'Task pack item added and active Jobs synchronized.');
    }

    public function updateItem(Request $request, TaskPackItem $item, WorkflowAutomationService $automation): RedirectResponse
    {
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($item->pack->workspace_id === $workspaceId, 404);
        $item->update($this->validateItem($request, $workspaceId));
        $this->synchronizeActiveJobs($item->pack, $request, $automation);

        return back()->with('success', 'Task pack item updated and active Jobs synchronized.');
    }

    public function destroyItem(Request $request, TaskPackItem $item): RedirectResponse
    {
        abort_unless($item->pack->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_if($item->tasks()->exists(), 422, 'This task template has already generated job tasks and cannot be deleted.');
        $item->delete();

        return back()->with('success', 'Task pack item removed.');
    }

    private function synchronizeActiveJobs(TaskPack $taskPack, Request $request, WorkflowAutomationService $automation): void
    {
        FlowJob::query()
            ->where('workspace_id', $taskPack->workspace_id)
            ->where('status', '!=', 'completed')
            ->whereHas('currentPhase', fn ($phase) => $phase->where('task_pack_id', $taskPack->id))
            ->with(['currentPhase', 'owner', 'coordinator'])
            ->orderBy('id')
            ->chunkById(100, function ($jobs) use ($automation, $request): void {
                foreach ($jobs as $job) {
                    $automation->synchronize($job, $request->user(), 'task_pack_configuration', false);
                }
            });
    }

    private function validateItem(Request $request, int $workspaceId): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'default_assignee_id' => ['nullable', 'integer', Rule::exists('workspace_memberships', 'user_id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('status', 'active'))],
            'default_department_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::Department->value)->where('status', 'active'))],
            'priority_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::Priority->value)->where('status', 'active'))],
            'document_category_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', MasterDataType::DocumentCategory->value)->where('status', 'active'))],
            'due_offset_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_required' => ['required', 'boolean'],
        ]);
    }
}
