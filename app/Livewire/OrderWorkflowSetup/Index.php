<?php

namespace App\Livewire\OrderWorkflowSetup;

use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Services\MasterDataService;
use App\Services\OrderWorkflowSetupService;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;

    public ?int $workflowId = null;
    public string $workflowName = OrderWorkflowSetupService::WORKFLOW_NAME;
    public array $stages = [];
    public array $departmentOptions = [];
    public array $documentCategoryOptions = [];

    public bool $showStageModal = false;
    public ?int $editingStageIndex = null;
    public string $editingStageColor = '#2d72d9';
    public array $editingTasks = [];
    public bool $dirty = false;

    public function mount(): void
    {
        $this->applyState(app(OrderWorkflowSetupService::class)->load());
    }

    public function openStageEditor(int $index): void
    {
        abort_unless(auth()->user()?->canModule('workflow', 'edit'), 403);
        abort_unless(isset($this->stages[$index]), 404);

        $this->editingStageIndex = $index;
        $this->editingStageColor = (string) $this->stages[$index]['color'];
        $this->editingTasks = array_values($this->stages[$index]['tasks'] ?? []);
        $this->showStageModal = true;
        $this->resetValidation();
    }

    public function closeStageEditor(): void
    {
        $this->showStageModal = false;
        $this->editingStageIndex = null;
        $this->editingTasks = [];
        $this->resetValidation();
    }

    public function addTask(): void
    {
        abort_unless($this->showStageModal, 422);
        $this->editingTasks[] = [
            'id' => null,
            'title' => 'New task',
            'description' => '',
            'team' => '',
            'default_department_id' => null,
            'due_offset_days' => 0,
            'is_required' => true,
            'document_enabled' => false,
            'document_type' => '',
            'document_category_id' => null,
            'document_required_before_completion' => false,
            'allow_multiple_documents' => false,
            'document_instructions' => '',
        ];
    }

    public function removeTask(int $index): void
    {
        abort_unless($this->showStageModal && isset($this->editingTasks[$index]), 422);
        if (count($this->editingTasks) <= 1) {
            $this->addError('editingTasks', 'Each Order stage must contain at least one task.');
            return;
        }
        array_splice($this->editingTasks, $index, 1);
        $this->editingTasks = array_values($this->editingTasks);
        $this->resetValidation('editingTasks');
    }

    public function moveTask(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (!isset($this->editingTasks[$index], $this->editingTasks[$target])) return;

        [$this->editingTasks[$index], $this->editingTasks[$target]] = [$this->editingTasks[$target], $this->editingTasks[$index]];
        $this->editingTasks = array_values($this->editingTasks);
    }

    public function updatedEditingTasks(mixed $value, string $key): void
    {
        if (!str_ends_with($key, '.document_enabled')) return;
        $index = (int) explode('.', $key)[0];
        if (!isset($this->editingTasks[$index])) return;

        if (!$this->editingTasks[$index]['document_enabled']) {
            $this->editingTasks[$index]['document_category_id'] = null;
            $this->editingTasks[$index]['document_required_before_completion'] = false;
            $this->editingTasks[$index]['allow_multiple_documents'] = false;
            $this->editingTasks[$index]['document_instructions'] = '';
        } else {
            $this->editingTasks[$index]['document_required_before_completion'] = true;
        }
    }

    public function saveStageEditor(): void
    {
        abort_unless(auth()->user()?->canModule('workflow', 'edit'), 403);
        abort_unless($this->editingStageIndex !== null && isset($this->stages[$this->editingStageIndex]), 422);

        $this->validate($this->editorRules());
        $this->validateDocumentRows($this->editingTasks, 'editingTasks');
        if ($this->getErrorBag()->isNotEmpty()) return;

        $this->stages[$this->editingStageIndex]['color'] = strtolower($this->editingStageColor);
        $this->stages[$this->editingStageIndex]['tasks'] = array_values($this->editingTasks);
        $this->dirty = true;
        $stageName = (string) $this->stages[$this->editingStageIndex]['name'];
        $this->closeStageEditor();
        session()->flash('success', $stageName.' updated. Save the workflow to publish the changes.');
    }

    public function resetWorkflow(): void
    {
        abort_unless(auth()->user()?->canModule('workflow', 'edit'), 403);
        $state = app(OrderWorkflowSetupService::class)->defaultState();
        $this->stages = $state['stages'];
        $this->dirty = true;
        $this->closeStageEditor();
        session()->flash('success', 'Order workflow reset to the prototype defaults. Save the workflow to publish it.');
    }

    public function saveWorkflow(): void
    {
        abort_unless(auth()->user()?->canModule('workflow', 'edit'), 403);
        $this->validate($this->workflowRules());

        foreach ($this->stages as $stageIndex => $stage) {
            $this->validateDocumentRows($stage['tasks'] ?? [], "stages.$stageIndex.tasks");
        }
        if ($this->getErrorBag()->isNotEmpty()) return;

        $state = app(\App\Actions\Setup\SaveOrderWorkflowAction::class)->execute($this->workflowId, $this->stages);
        $syncedOrderCount = (int) ($state['synced_order_count'] ?? 0);
        $this->applyState($state);
        $this->dirty = false;
        $message = 'Order workflow saved. '.$syncedOrderCount.' active Order'.($syncedOrderCount === 1 ? '' : 's').' synchronized with the seven-stage configuration.';
        session()->flash('success', $message);
        app(\App\Services\NotificationService::class)->notifyUser(
            auth()->user(),
            'Order workflow updated',
            $message,
            'update',
            null,
            null,
            auth()->user(),
        );
    }

    public function render()
    {
        return view('livewire.order-workflow-setup.index');
    }

    private function applyState(array $state): void
    {
        $this->workflowId = $state['workflow_id'] ? (int) $state['workflow_id'] : null;
        $this->workflowName = (string) ($state['workflow_name'] ?: OrderWorkflowSetupService::WORKFLOW_NAME);
        $this->stages = array_values($state['stages'] ?? []);
        $this->departmentOptions = array_values($state['departments'] ?? []);
        $this->documentCategoryOptions = array_values($state['document_categories'] ?? []);
    }

    private function editorRules(): array
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        return [
            'editingStageColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'editingTasks' => ['required', 'array', 'min:1', 'max:80'],
            'editingTasks.*.title' => ['required', 'string', 'max:255'],
            'editingTasks.*.description' => ['nullable', 'string', 'max:5000'],
            'editingTasks.*.default_department_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', 'department')->where('status', 'active')->whereNull('deleted_at'))],
            'editingTasks.*.due_offset_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'editingTasks.*.is_required' => ['boolean'],
            'editingTasks.*.document_enabled' => ['boolean'],
            'editingTasks.*.document_category_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', 'document_category')->where('status', 'active')->whereNull('deleted_at'))],
            'editingTasks.*.document_required_before_completion' => ['boolean'],
            'editingTasks.*.allow_multiple_documents' => ['boolean'],
            'editingTasks.*.document_instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function workflowRules(): array
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        return [
            'stages' => ['required', 'array', 'size:7'],
            'stages.*.key' => ['required', 'string', 'max:20'],
            'stages.*.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'stages.*.tasks' => ['required', 'array', 'min:1', 'max:80'],
            'stages.*.tasks.*.title' => ['required', 'string', 'max:255'],
            'stages.*.tasks.*.description' => ['nullable', 'string', 'max:5000'],
            'stages.*.tasks.*.default_department_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', 'department')->where('status', 'active')->whereNull('deleted_at'))],
            'stages.*.tasks.*.due_offset_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'stages.*.tasks.*.is_required' => ['boolean'],
            'stages.*.tasks.*.document_enabled' => ['boolean'],
            'stages.*.tasks.*.document_category_id' => ['nullable', 'integer', Rule::exists('master_records', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)->where('type', 'document_category')->where('status', 'active')->whereNull('deleted_at'))],
            'stages.*.tasks.*.document_required_before_completion' => ['boolean'],
            'stages.*.tasks.*.allow_multiple_documents' => ['boolean'],
            'stages.*.tasks.*.document_instructions' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function validateDocumentRows(array $rows, string $prefix): void
    {
        foreach ($rows as $index => $row) {
            if (!empty($row['document_enabled']) && empty($row['document_category_id']) && blank($row['document_type'] ?? null)) {
                $this->addError("$prefix.$index.document_category_id", 'Choose the document type for this task.');
            }
        }
    }
}
