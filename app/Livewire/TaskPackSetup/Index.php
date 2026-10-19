<?php

namespace App\Livewire\TaskPackSetup;

use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;

use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\WorkflowPhase;
use App\Services\TaskPackService;
use App\Support\MasterColor;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    public ?int $selectedPackId = null;
    public bool $showPackModal = false;
    public bool $showItemModal = false;
    public bool $packsReady = false;
    public ?int $editPackId = null;
    public ?int $editItemId = null;

    public string $packCode = '';
    public string $packName = '';
    public string $packDescription = '';
    public bool $packActive = true;

    public string $itemTitle = '';
    public string $itemDescription = '';
    public string $itemColor = '#2563EB';
    public ?int $defaultAssigneeId = null;
    public ?int $defaultDepartmentId = null;
    public ?int $priorityId = null;
    public ?int $documentCategoryId = null;
    public int $dueOffsetDays = 1;
    public bool $itemRequired = true;
    public bool $showPackDeleteModal = false;
    public ?int $deletePackId = null;
    public array $packDeleteImpact = [];

    public function mount(): void
    {
        // The list is loaded after the page shell renders.
    }

    public function loadTaskPacks(): void
    {
        $this->packsReady = true;
    }

    public function selectPack(int $id): void { $this->selectedPackId = $id; $this->resetValidation(); }

    public function openPack(?int $id = null): void
    {
        abort_unless(auth()->user()?->canModule('taskpacks', $id ? 'edit' : 'create'), 403);
        $this->showPackModal = true; $this->editPackId = $id; $this->resetValidation();
        if ($id) {
            $p = TaskPack::where('is_snapshot', false)->findOrFail($id);
            $this->packCode = (string) $p->code; $this->packName = $p->name; $this->packDescription = (string) $p->description; $this->packActive = (bool) $p->is_active;
        } else {
            $this->reset(['packCode','packName','packDescription']); $this->packActive = true;
        }
    }

    public function closePack(): void { $this->showPackModal = false; $this->resetValidation(); }

    public function savePack(): void
    {
        $data = $this->validate([
            'packCode' => ['required','string','max:40'], 'packName' => ['required','string','max:255'], 'packDescription' => ['nullable','string','max:5000'], 'packActive' => ['boolean'],
        ]);
        $pack = app(\App\Actions\Setup\SaveTaskPackAction::class)->execute(['code'=>$data['packCode'],'name'=>$data['packName'],'description'=>$data['packDescription'],'is_active'=>$data['packActive']], $this->editPackId);
        $this->selectedPackId = $pack->id; $this->showPackModal = false; session()->flash('success','Task Pack saved.');
        app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Task Pack updated', $pack->name.' was saved.', 'update', null, null, auth()->user());
    }

    public function togglePack(int $id): void
    {
        $this->packsReady = true;
        try { $pack = TaskPack::where('is_snapshot', false)->findOrFail($id); app(\App\Actions\Setup\ToggleTaskPackAction::class)->execute($id); session()->flash('success','Task Pack status updated.'); app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Task Pack status updated', $pack->name.' status was changed.', 'update', null, null, auth()->user()); }
        catch (ValidationException $e) { $this->addError('pack', collect($e->errors())->flatten()->first()); }
    }

    public function requestDeletePack(int $id): void
    {
        $this->packsReady = true;
        $this->resetValidation('pack');

        try {
            $this->packDeleteImpact = app(TaskPackService::class)->packDeleteImpact($id);
            $this->deletePackId = $id;
            $this->showPackDeleteModal = true;
        } catch (ValidationException $e) {
            $this->addError('pack', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            report($e);
            $this->addError('pack', 'FlowTrack could not check this Task Pack safely. Please refresh the page and try again.');
        }
    }

    public function closePackDelete(): void
    {
        $this->showPackDeleteModal = false;
        $this->deletePackId = null;
        $this->packDeleteImpact = [];
    }

    public function confirmDeletePack(): void
    {
        if (!$this->deletePackId) {
            $this->closePackDelete();
            $this->addError('pack', 'The Task Pack selected for deletion is no longer available. Please try again.');
            return;
        }

        $this->packsReady = true;

        try {
            $result = app(\App\Actions\Setup\DeleteTaskPackAction::class)->execute($this->deletePackId);
            $this->closePackDelete();
            $this->selectedPackId = TaskPack::query()
                ->where('workspace_id', app(TaskPackService::class)->workspaceId())
                ->where('is_snapshot', false)
                ->orderBy('name')
                ->value('id');

            $message = $result['pack_name'].' permanently deleted. Existing Jobs and Tasks were preserved in their own snapshots.';
            if ($result['job_count'] > 0) {
                $message .= ' '.$result['job_count'].' older Job'.($result['job_count'] === 1 ? '' : 's').' were detached from reusable setup data first.';
            }
            if ($result['mapped_phase_count'] > 0) {
                $message .= ' '.$result['mapped_phase_count'].' Workflow phase'.($result['mapped_phase_count'] === 1 ? '' : 's').' kept their place with the Task Pack mapping removed.';
            }

            session()->flash('success', $message);

            try {
                app(\App\Services\NotificationService::class)->notifyUser(
                    auth()->user(),
                    'Task Pack permanently deleted',
                    $message,
                    'update',
                    null,
                    null,
                    auth()->user(),
                );
            } catch (\Throwable $notificationError) {
                // Deletion has already completed. A notification failure must
                // never make the user think the delete itself failed.
                report($notificationError);
            }
        } catch (ValidationException $e) {
            $this->closePackDelete();
            $this->addError('pack', collect($e->errors())->flatten()->first());
        } catch (\Throwable $e) {
            $this->closePackDelete();
            report($e);
            $this->addError(
                'pack',
                'This Task Pack could not be deleted right now. Your Jobs and Tasks were not intentionally removed. Please refresh and try again.'
            );
        }
    }

    public function openItem(?int $id = null): void
    {
        abort_unless(auth()->user()?->canModule('taskpacks', 'edit'), 403);
        abort_unless($this->selectedPackId, 422);
        $this->showItemModal = true; $this->editItemId = $id; $this->resetValidation();
        if ($id) {
            $i = TaskPackItem::where('task_pack_id',$this->selectedPackId)->findOrFail($id);
            $this->itemTitle=$i->title; $this->itemDescription=(string)$i->description; $this->itemColor=MasterColor::normalize((string)($i->color ?? '')) ?: '#2563EB'; $this->defaultAssigneeId=$i->default_assignee_id; $this->defaultDepartmentId=$i->default_department_id; $this->priorityId=$i->priority_id; $this->documentCategoryId=$i->document_category_id; $this->dueOffsetDays=(int)$i->due_offset_days; $this->itemRequired=(bool)$i->is_required;
        } else {
            $this->reset(['itemTitle','itemDescription','defaultAssigneeId','defaultDepartmentId','priorityId','documentCategoryId']); $this->itemColor='#2563EB'; $this->dueOffsetDays=1; $this->itemRequired=true;
        }
    }

    public function closeItem(): void { $this->showItemModal=false; $this->resetValidation(); }

    public function saveItem(): void
    {
        $data = $this->validate([
            'itemTitle'=>['required','string','max:255'], 'itemDescription'=>['nullable','string','max:5000'], 'itemColor'=>['required','regex:/^#[0-9A-Fa-f]{6}$/'], 'defaultAssigneeId'=>['nullable','exists:users,id'],
            'defaultDepartmentId'=>['nullable','exists:master_records,id'], 'priorityId'=>['nullable','exists:master_records,id'], 'documentCategoryId'=>['nullable','exists:master_records,id'],
            'dueOffsetDays'=>['required','integer','min:0','max:3650'], 'itemRequired'=>['boolean'],
        ]);
        $pack=TaskPack::where('is_snapshot', false)->findOrFail($this->selectedPackId);
        app(\App\Actions\Setup\SaveTaskPackItemAction::class)->execute($pack,[
            'title'=>$data['itemTitle'],'description'=>$data['itemDescription'],'color'=>MasterColor::normalize($data['itemColor']) ?: '#2563EB','default_assignee_id'=>$data['defaultAssigneeId'],'default_department_id'=>$data['defaultDepartmentId'],
            'priority_id'=>$data['priorityId'],'document_category_id'=>$data['documentCategoryId'],'due_offset_days'=>$data['dueOffsetDays'],'is_required'=>$data['itemRequired'],
        ],$this->editItemId);
        $this->showItemModal=false; session()->flash('success','Task Pack item saved.');
        app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Task Pack task updated', $data['itemTitle'].' was saved.', 'update', null, null, auth()->user());
    }

    public function deleteItem(int $id): void
    {
        $this->packsReady = true;
        try { app(\App\Actions\Setup\DeleteTaskPackItemAction::class)->execute($id); session()->flash('success','Task Pack item deleted.'); app(\App\Services\NotificationService::class)->notifyUser(auth()->user(), 'Task Pack task deleted', 'A Task Pack task was deleted.', 'update', null, null, auth()->user()); }
        catch (ValidationException $e) { $this->addError('item', collect($e->errors())->flatten()->first()); }
    }

    public function moveItem(int $id, int $direction): void { app(\App\Actions\Setup\MoveTaskPackItemAction::class)->execute($id, $direction); }

    public function render()
    {
        if (!$this->packsReady) {
            return view('livewire.task-pack-setup.index', $this->emptyPageData());
        }

        // The destructive confirmation is its own render branch. While it is
        // open we do not reload every Task Pack and every item/relationship.
        if ($this->showPackDeleteModal) {
            return view('livewire.task-pack-setup.index', $this->emptyPageData());
        }

        return view('livewire.task-pack-setup.index', $this->taskPackListData());
    }

    private function taskPackListData(): array
    {
        $service = app(TaskPackService::class);
        $packs = $service->all();

        if (!$this->selectedPackId && $packs->isNotEmpty()) {
            $this->selectedPackId = $packs->first()->id;
        }

        $selected = $packs->firstWhere('id', $this->selectedPackId);
        $packIds = $packs->pluck('id');

        return [
            'packs' => $packs,
            'selected' => $selected,
            'totalPacks' => $packs->count(),
            'activePacks' => $packs->where('is_active', true)->count(),
            'configuredTasks' => $packs->sum(fn ($pack) => $pack->items->count()),
            'mappedPhases' => $packIds->isEmpty() ? 0 : WorkflowPhase::whereIn('task_pack_id', $packIds)->count(),
        ];
    }

    private function emptyPageData(): array
    {
        return [
            'packs' => collect(),
            'selected' => null,
            'totalPacks' => 0,
            'activePacks' => 0,
            'configuredTasks' => 0,
            'mappedPhases' => 0,
        ];
    }
}
