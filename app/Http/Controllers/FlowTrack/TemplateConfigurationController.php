<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Enums\MasterDataType;
use App\Modules\OrderFlow\Business\Services\ConfigurationCodeGenerator;
use App\Modules\OrderFlow\Business\Services\MasterRecordDeletionService;
use App\Modules\OrderFlow\Data\Models\MasterRecord;
use App\Modules\OrderFlow\Data\Models\TaskPack;
use App\Modules\OrderFlow\Data\Models\TaskPackItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TemplateConfigurationController extends Controller
{
    public function nextMasterCode(Request $request, ConfigurationCodeGenerator $codes): JsonResponse
    {
        $this->authorizeMasterData($request, 'create');
        $data = $request->validate(['type' => ['required', Rule::enum(MasterDataType::class)]]);
        $type = MasterDataType::from($data['type']);

        return response()->json(['code' => $codes->master($type, $request->user()->currentWorkspaceId())]);
    }

    public function storeMaster(Request $request, ConfigurationCodeGenerator $codes): JsonResponse
    {
        $this->authorizeMasterData($request, 'create');
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $this->validateMaster($request, $workspaceId);
        $type = MasterDataType::from($data['type']);

        $record = DB::transaction(function () use ($workspaceId, $data, $type, $codes): MasterRecord {
            return MasterRecord::create([
                ...$data,
                'workspace_id' => $workspaceId,
                'type' => $type->value,
                'code' => $codes->master($type, $workspaceId),
                'parent_id' => $type === MasterDataType::Product ? ($data['parent_id'] ?? null) : null,
                'sort_order' => $data['sort_order'] ?? (((int) MasterRecord::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('type', $type->value)
                    ->max('sort_order')) + 1),
            ]);
        });

        $record->load('parent:id,name,code');

        return response()->json([
            'record' => $this->mapMaster($record),
            'nextCode' => $codes->master($type, $workspaceId),
        ], 201);
    }

    public function updateMaster(Request $request, MasterRecord $record): JsonResponse
    {
        $this->authorizeMasterData($request, 'edit_all');
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($record->workspace_id === $workspaceId, 404);
        $data = $this->validateMaster($request, $workspaceId);

        if ($data['type'] !== $record->type) {
            throw ValidationException::withMessages(['type' => 'A master-data record cannot be moved to another section.']);
        }

        unset($data['type']);
        $data['parent_id'] = $record->type === MasterDataType::Product->value ? ($data['parent_id'] ?? null) : null;
        $record->update($data);
        $record->load('parent:id,name,code');

        return response()->json(['record' => $this->mapMaster($record)]);
    }

    public function toggleMaster(Request $request, MasterRecord $record): JsonResponse
    {
        $this->authorizeMasterData($request, 'edit_all');
        abort_unless($record->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $record->update(['status' => $record->status === 'active' ? 'inactive' : 'active']);

        return response()->json(['record' => $this->mapMaster($record->fresh('parent:id,name,code'))]);
    }

    public function destroyMaster(Request $request, MasterRecord $record, MasterRecordDeletionService $deletion): JsonResponse
    {
        $this->authorizeMasterData($request, 'delete');
        abort_unless($record->workspace_id === $request->user()->currentWorkspaceId(), 404);

        $name = $record->name;
        $code = $record->code;
        $detached = $deletion->delete($record);

        return response()->json([
            'message' => "{$code} · {$name} was deleted.",
            'detached' => $detached,
        ]);
    }

    public function nextTaskPackCode(Request $request, ConfigurationCodeGenerator $codes): JsonResponse
    {
        $this->authorizeWorkflow($request, 'create');

        return response()->json(['code' => $codes->taskPack($request->user()->currentWorkspaceId())]);
    }

    public function storeTaskPack(Request $request, ConfigurationCodeGenerator $codes): JsonResponse
    {
        $this->authorizeWorkflow($request, 'create');
        $workspaceId = $request->user()->currentWorkspaceId();
        $data = $this->validateTaskPack($request, $workspaceId);

        $pack = DB::transaction(function () use ($workspaceId, $data, $codes): TaskPack {
            $pack = TaskPack::create([
                'workspace_id' => $workspaceId,
                'code' => $codes->taskPack($workspaceId),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ]);

            $this->syncTaskPackItems($pack, $data['items'], false);

            return $pack;
        });

        return response()->json([
            'taskPack' => $this->mapTaskPack($this->loadTaskPack($pack)),
            'nextCode' => $codes->taskPack($workspaceId),
        ], 201);
    }

    public function updateTaskPack(Request $request, TaskPack $taskPack): JsonResponse
    {
        $this->authorizeWorkflow($request, 'edit_all');
        $workspaceId = $request->user()->currentWorkspaceId();
        abort_unless($taskPack->workspace_id === $workspaceId, 404);
        $data = $this->validateTaskPack($request, $workspaceId, $taskPack);

        DB::transaction(function () use ($taskPack, $data): void {
            $taskPack->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ]);

            $this->syncTaskPackItems($taskPack, $data['items'], true);
        });

        return response()->json(['taskPack' => $this->mapTaskPack($this->loadTaskPack($taskPack))]);
    }

    private function validateMaster(Request $request, int $workspaceId): array
    {
        return $request->validate([
            'type' => ['required', Rule::enum(MasterDataType::class)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', MasterDataType::ProductCategory->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function validateTaskPack(Request $request, int $workspaceId, ?TaskPack $taskPack = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'is_active' => ['required', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer', 'exists:task_pack_items,id'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:3000'],
            'items.*.default_assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('workspace_memberships', 'user_id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'active')),
            ],
            'items.*.default_department_id' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', MasterDataType::Department->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'items.*.priority_id' => [
                'nullable',
                'integer',
                Rule::exists('master_records', 'id')->where(fn ($query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('type', MasterDataType::Priority->value)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')),
            ],
            'items.*.due_offset_days' => ['required', 'integer', 'min:0', 'max:365'],
            'items.*.is_required' => ['required', 'boolean'],
        ]);

        $ids = collect($data['items'])->pluck('id')->filter()->map(fn ($id) => (int) $id);
        if ($ids->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'A task-pack item cannot appear more than once.']);
        }

        if ($taskPack === null && $ids->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'New task packs cannot contain existing task IDs.']);
        }

        if ($taskPack !== null && $ids->isNotEmpty()) {
            $validCount = $taskPack->items()->whereIn('id', $ids)->count();
            if ($validCount !== $ids->count()) {
                throw ValidationException::withMessages(['items' => 'One or more tasks do not belong to this task pack.']);
            }
        }

        return $data;
    }

    private function syncTaskPackItems(TaskPack $taskPack, array $items, bool $updating): void
    {
        $keptIds = collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->values();

        if ($updating) {
            $removedItems = $taskPack->items()->when($keptIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $keptIds))->get();
            foreach ($removedItems as $removedItem) {
                if ($removedItem->tasks()->exists()) {
                    throw ValidationException::withMessages([
                        'items' => "The task \"{$removedItem->title}\" has already generated Job tasks and cannot be removed. Keep it in the pack or deactivate the pack.",
                    ]);
                }
                $removedItem->delete();
            }
        }

        foreach (array_values($items) as $index => $itemData) {
            $attributes = [
                'title' => $itemData['title'],
                'description' => $itemData['description'] ?? null,
                'default_assignee_id' => $itemData['default_assignee_id'] ?? null,
                'default_department_id' => $itemData['default_department_id'] ?? null,
                'priority_id' => $itemData['priority_id'] ?? null,
                'due_offset_days' => $itemData['due_offset_days'],
                'is_required' => $itemData['is_required'],
                'sort_order' => $index + 1,
            ];

            if (! empty($itemData['id'])) {
                $taskPack->items()->whereKey($itemData['id'])->firstOrFail()->update($attributes);
            } else {
                $taskPack->items()->create($attributes);
            }
        }
    }

    private function loadTaskPack(TaskPack $taskPack): TaskPack
    {
        return $taskPack->fresh([
            'items' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'items.defaultAssignee:id,name,initials',
            'items.defaultDepartment:id,name,code',
            'items.priority:id,name,code',
        ]);
    }

    private function mapMaster(MasterRecord $record): array
    {
        return [
            'id' => $record->id,
            'code' => $record->code,
            'name' => $record->name,
            'detail' => $record->description ?? '',
            'description' => $record->description ?? '',
            'status' => str($record->status)->title()->toString(),
            'parent_id' => $record->parent_id,
            'parent_name' => $record->parent?->name,
            'sort_order' => (int) $record->sort_order,
        ];
    }

    private function mapTaskPack(TaskPack $taskPack): array
    {
        return [
            'id' => $taskPack->id,
            'code' => $taskPack->code,
            'name' => $taskPack->name,
            'description' => $taskPack->description ?? '',
            'active' => (bool) $taskPack->is_active,
            'is_active' => (bool) $taskPack->is_active,
            'items' => $taskPack->items->map(fn (TaskPackItem $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'description' => $item->description ?? '',
                'default_assignee_id' => $item->default_assignee_id,
                'default_assignee_name' => $item->defaultAssignee?->name,
                'default_department_id' => $item->default_department_id,
                'default_department_name' => $item->defaultDepartment?->name,
                'priority_id' => $item->priority_id,
                'priority_name' => $item->priority?->name,
                'due_offset_days' => (int) $item->due_offset_days,
                'is_required' => (bool) $item->is_required,
                'sort_order' => (int) $item->sort_order,
            ])->values()->all(),
        ];
    }

    private function authorizeMasterData(Request $request, string $action): void
    {
        abort_unless($request->user()?->hasFlowAction('masterdata', $action), 403);
    }

    private function authorizeWorkflow(Request $request, string $action): void
    {
        abort_unless($request->user()?->hasFlowAction('workflow', $action), 403);
    }
}
