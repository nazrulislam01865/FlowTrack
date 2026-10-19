<?php

namespace App\Livewire\TaskPackSetup;

use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;

use App\Models\MasterRecord;
use App\Models\TaskPack;
use App\Models\User;
use App\Services\FilterOptionService;
use App\Services\MasterDataService;
use App\Services\TaskPackService;
use App\Support\MasterColor;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Form extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    public ?int $taskPackId = null;
    public string $packCode = '';
    public string $packName = '';
    public string $packDescription = '';
    public string $packStatus = 'active';
    public array $tasks = [];
    public bool $optionsReady = false;

    public function mount(?int $taskPackId = null): void
    {
        $this->taskPackId = $taskPackId;
        if ($taskPackId) {
            $pack = TaskPack::query()
                ->where('workspace_id', app(TaskPackService::class)->workspaceId())
                ->where('is_snapshot', false)
                ->with([
                    'items.defaultAssignee:id,name',
                    'items.defaultDepartment:id,name',
                    'items.documentCategory:id,name',
                ])
                ->findOrFail($taskPackId);

            $this->packCode = (string) $pack->code;
            $this->packName = (string) $pack->name;
            $this->packDescription = (string) $pack->description;
            $this->packStatus = $pack->is_active ? 'active' : 'inactive';
            $this->tasks = $pack->items->map(fn ($item) => [
                'id' => $item->id,
                'automation_key' => (string) ($item->automation_key ?? ''),
                'title' => (string) $item->title,
                'description' => (string) $item->description,
                'color' => MasterColor::normalize((string) ($item->color ?? '')) ?: '#2563EB',
                'default_assignee_id' => $item->default_assignee_id,
                'default_assignee_type' => (string) ($item->default_assignee_type ?: 'user'),
                'default_assignee_label' => (string) ($item->defaultAssignee?->name ?: 'Unassigned'),
                'default_department_id' => $item->default_department_id,
                'default_department_label' => (string) ($item->defaultDepartment?->name ?: 'No department default'),
                'priority_id' => $item->priority_id,
                'document_category_id' => $item->document_category_id,
                'document_category_label' => (string) ($item->documentCategory?->name ?: 'No task-specific file'),
                'due_offset_days' => (int) $item->due_offset_days,
                'standard_duration_value' => (float) ($item->standard_duration_value ?: 8),
                'standard_duration_unit' => (string) ($item->standard_duration_unit ?: 'TPD-001'),
                'timer_start_rule' => (string) ($item->timer_start_rule ?: 'TPS-001'),
                'timer_stop_rule' => (string) ($item->timer_stop_rule ?: 'TPE-001'),
                'work_calendar' => (string) ($item->work_calendar ?: 'TPW-001'),
                'set_due_from_standard_duration' => $item->set_due_from_standard_duration === null ? true : (bool) $item->set_due_from_standard_duration,
                'allow_efficiency_override' => (bool) $item->allow_efficiency_override,
                'is_required' => (bool) $item->is_required,
            ])->values()->all();
        } else {
            $this->packCode = app(TaskPackService::class)->nextCode();
            $this->tasks = [$this->blankTask()];
        }

        if (!$this->tasks) {
            $this->tasks = [$this->blankTask()];
        }
    }

    public function addTask(): void
    {
        $this->tasks[] = $this->blankTask();
    }

    public function loadTaskPackOptions(): void
    {
        if ($this->optionsReady) return;

        app(TaskPackService::class)->ensureTaskPackMasterDataDefaults();
        $this->optionsReady = true;

        // Older Task Packs can still contain the pre-master-data efficiency
        // values (business_hours, status_in_progress, etc.) or a code that was
        // later retired in Master Data. Native <select> controls visually fall
        // back to their first option in that situation, but Livewire keeps the
        // stale value in component state. Saving then reports "The selected
        // option is invalid" even when the user only changed Required task.
        // Normalize those references as soon as the real option sets are loaded
        // so what the user sees is exactly what will be persisted.
        $this->normalizeEfficiencyOptionValues();
    }

    public function loadCreateSection(string $section): void
    {
        if ($section === 'task-options') {
            $this->loadTaskPackOptions();
            return;
        }

        abort(422, 'Unknown Create Task Pack section.');
    }

    public function setTaskPackAssignee(string $property, mixed $value): void
    {
        abort_unless(auth()->user()?->canModule('taskpacks', $this->taskPackId ? 'edit' : 'create'), 403);
        abort_unless(preg_match('/^tasks\.(\d+)\.default_assignee_id$/', $property, $matches) === 1, 422);

        $index = (int) $matches[1];
        abort_unless(array_key_exists($index, $this->tasks), 422);

        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            $this->tasks[$index]['default_assignee_id'] = null;
            $this->tasks[$index]['default_assignee_label'] = 'Unassigned';
            $this->resetValidation("tasks.$index.default_assignee_id");
            return;
        }

        abort_unless(ctype_digit($raw), 422);
        $assignee = User::query()->where('is_active', true)->findOrFail((int) $raw);

        $this->tasks[$index]['default_assignee_id'] = (int) $assignee->id;
        $this->tasks[$index]['default_assignee_label'] = (string) $assignee->name;
        $this->resetValidation("tasks.$index.default_assignee_id");
    }

    public function setTaskPackDepartment(string $property, mixed $value): void
    {
        abort_unless(auth()->user()?->canModule('taskpacks', $this->taskPackId ? 'edit' : 'create'), 403);
        abort_unless(preg_match('/^tasks\.(\d+)\.default_department_id$/', $property, $matches) === 1, 422);

        $index = (int) $matches[1];
        abort_unless(array_key_exists($index, $this->tasks), 422);

        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            $this->tasks[$index]['default_department_id'] = null;
            $this->tasks[$index]['default_department_label'] = 'No department default';
            $this->resetValidation("tasks.$index.default_department_id");
            return;
        }

        abort_unless(ctype_digit($raw), 422);

        $department = MasterRecord::query()
            ->where('workspace_id', app(TaskPackService::class)->workspaceId())
            ->where('type', 'department')
            ->where('status', 'active')
            ->findOrFail((int) $raw);

        $this->tasks[$index]['default_department_id'] = (int) $department->id;
        $this->tasks[$index]['default_department_label'] = (string) $department->name;
        $this->resetValidation("tasks.$index.default_department_id");
    }

    public function setTaskPackDocumentCategory(string $property, mixed $value): void
    {
        abort_unless(auth()->user()?->canModule('taskpacks', $this->taskPackId ? 'edit' : 'create'), 403);
        abort_unless(preg_match('/^tasks\.(\d+)\.document_category_id$/', $property, $matches) === 1, 422);

        $index = (int) $matches[1];
        abort_unless(array_key_exists($index, $this->tasks), 422);

        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            $this->tasks[$index]['document_category_id'] = null;
            $this->tasks[$index]['document_category_label'] = 'No task-specific file';
            $this->resetValidation("tasks.$index.document_category_id");
            return;
        }

        abort_unless(ctype_digit($raw), 422);

        $documentCategory = MasterRecord::query()
            ->where('workspace_id', app(TaskPackService::class)->workspaceId())
            ->where('type', 'document_category')
            ->where('status', 'active')
            ->findOrFail((int) $raw);

        $this->tasks[$index]['document_category_id'] = (int) $documentCategory->id;
        $this->tasks[$index]['document_category_label'] = (string) $documentCategory->name;
        $this->resetValidation("tasks.$index.document_category_id");
    }

    public function removeTask(int $index): void
    {
        if (!array_key_exists($index, $this->tasks)) return;
        if (filled($this->tasks[$index]['automation_key'] ?? null)) {
            $this->addError('tasks', 'Core Order automation tasks cannot be removed. Edit their task settings instead.');
            return;
        }
        if (!empty($this->tasks[$index]['id'])) {
            abort_unless(auth()->user()?->canModule('taskpacks', 'delete'), 403);
        }
        array_splice($this->tasks, $index, 1);
        $this->tasks = array_values($this->tasks);
        if (!$this->tasks) $this->tasks[] = $this->blankTask();
        $this->resetValidation();
    }

    public function moveTask(int $index, int $direction): void
    {
        $target = $index + $direction;
        if (!isset($this->tasks[$index], $this->tasks[$target])) return;
        if (filled($this->tasks[$index]['automation_key'] ?? null) && filled($this->tasks[$target]['automation_key'] ?? null)) {
            $this->addError('tasks', 'Core Order automation tasks must keep their relative order.');
            return;
        }
        [$this->tasks[$index], $this->tasks[$target]] = [$this->tasks[$target], $this->tasks[$index]];
        $this->tasks = array_values($this->tasks);
    }

    public function save(): void
    {
        // If the user submits before the below-the-fold option area reached the
        // viewport, hydrate the bounded reference sets just-in-time and continue.
        if (!$this->optionsReady) {
            $this->loadTaskPackOptions();
        }

        $workspaceId = app(TaskPackService::class)->workspaceId();

        // Validate scalar shape first. Reference integrity is validated below in
        // three bounded queries instead of executing one EXISTS query for every
        // wildcard field on every Task Pack row.
        $data = $this->validate([
            'packName' => ['required','string','max:255'],
            'packDescription' => ['nullable','string','max:5000'],
            'packStatus' => ['required','in:active,inactive'],
            'tasks' => ['required','array','min:1'],
            'tasks.*.id' => ['nullable','integer'],
            'tasks.*.title' => ['required','string','max:255'],
            'tasks.*.description' => ['nullable','string','max:5000'],
            'tasks.*.color' => ['required','regex:/^#[0-9A-Fa-f]{6}$/'],
            'tasks.*.default_assignee_id' => ['nullable','integer'],
            'tasks.*.default_assignee_type' => ['required','in:user,supplier'],
            'tasks.*.default_department_id' => ['nullable','integer'],
            'tasks.*.priority_id' => ['nullable','integer'],
            'tasks.*.document_category_id' => ['nullable','integer'],
            'tasks.*.due_offset_days' => ['nullable','integer','min:0','max:3650'],
            'tasks.*.standard_duration_value' => ['required','numeric','min:0.01','max:10000'],
            'tasks.*.standard_duration_unit' => ['required','string','max:40'],
            'tasks.*.timer_start_rule' => ['required','string','max:40'],
            'tasks.*.timer_stop_rule' => ['required','string','max:40'],
            'tasks.*.work_calendar' => ['required','string','max:40'],
            'tasks.*.set_due_from_standard_duration' => ['boolean'],
            'tasks.*.allow_efficiency_override' => ['boolean'],
            'tasks.*.is_required' => ['boolean'],
        ]);

        $this->validateTaskReferences($data['tasks'], $workspaceId);

        $savedPack = app(\App\Actions\Setup\SaveTaskPackWithItemsAction::class)->execute([
            'code' => $this->packCode,
            'name' => $data['packName'],
            'description' => $data['packDescription'],
            'is_active' => $data['packStatus'] === 'active',
        ], $data['tasks'], $this->taskPackId);

        session()->flash('success', $this->taskPackId ? 'Task Pack updated.' : 'Task Pack created.');
        app(\App\Services\NotificationService::class)->notifyUser(
            auth()->user(),
            $this->taskPackId ? 'Task Pack updated' : 'Task Pack created',
            $savedPack->name.' · '.$savedPack->items->count().' configured task'.($savedPack->items->count() === 1 ? '' : 's').'.',
            'update',
            null,
            null,
            auth()->user(),
        );
        $this->redirectRoute('task-pack.setup', navigate: true);
    }

    private function normalizeEfficiencyOptionValues(): void
    {
        $master = app(MasterDataService::class);

        $definitions = [
            'standard_duration_unit' => [
                'type' => 'task_pack_duration_unit',
                'preferred' => 'TPD-001',
                'legacy' => [
                    'business_hours' => 'TPD-001',
                    'calendar_hours' => 'TPD-002',
                    'business_days' => 'TPD-003',
                    'calendar_days' => 'TPD-004',
                ],
            ],
            'timer_start_rule' => [
                'type' => 'task_pack_timer_start',
                'preferred' => 'TPS-001',
                'legacy' => ['status_in_progress' => 'TPS-001'],
            ],
            'timer_stop_rule' => [
                'type' => 'task_pack_timer_stop',
                'preferred' => 'TPE-001',
                'legacy' => ['status_completed' => 'TPE-001'],
            ],
            'work_calendar' => [
                'type' => 'task_pack_work_calendar',
                'preferred' => 'TPW-001',
                'legacy' => ['workspace_hours' => 'TPW-001'],
            ],
        ];

        foreach ($definitions as $field => $definition) {
            $options = $master->active($definition['type']);
            $validCodes = $options
                ->pluck('code')
                ->map(fn ($code) => trim((string) $code))
                ->filter()
                ->values();

            if ($validCodes->isEmpty()) continue;

            $fallback = $validCodes->contains($definition['preferred'])
                ? $definition['preferred']
                : (string) $validCodes->first();

            foreach ($this->tasks as $index => $task) {
                $current = trim((string) ($task[$field] ?? ''));
                $mapped = $definition['legacy'][$current] ?? $current;

                if ($mapped === '' || ! $validCodes->contains($mapped)) {
                    $mapped = $fallback;
                }

                $this->tasks[$index][$field] = $mapped;
                $this->resetValidation("tasks.$index.$field");
            }
        }
    }

    private function validateTaskReferences(array $tasks, int $workspaceId): void
    {
        $errors = [];

        $assigneeIds = collect($tasks)
            ->pluck('default_assignee_id')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $validAssigneeIds = $assigneeIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $assigneeIds)->pluck('id')->map(fn ($id) => (int) $id);
        $validAssigneeLookup = $validAssigneeIds->flip();

        $masterIdFields = [
            'default_department_id' => 'department',
            'priority_id' => 'priority',
            'document_category_id' => 'document_category',
        ];
        $allMasterIds = collect($masterIdFields)
            ->flatMap(function (string $type, string $field) use ($tasks) {
                return collect($tasks)
                    ->filter(fn ($task) => $field !== 'default_department_id' || ($task['default_assignee_type'] ?? 'user') === 'user')
                    ->pluck($field)->filter(fn ($id) => filled($id))->map(fn ($id) => (int) $id);
            })
            ->unique()
            ->values();
        $validMasterRows = $allMasterIds->isEmpty()
            ? collect()
            : MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('id', $allMasterIds)
                ->whereIn('type', array_values($masterIdFields))
                ->get(['id', 'type']);
        $validMasterLookup = $validMasterRows
            ->mapWithKeys(fn (MasterRecord $row) => [$row->type.'|'.(int) $row->id => true]);

        $masterCodeFields = [
            'standard_duration_unit' => 'task_pack_duration_unit',
            'timer_start_rule' => 'task_pack_timer_start',
            'timer_stop_rule' => 'task_pack_timer_stop',
            'work_calendar' => 'task_pack_work_calendar',
        ];
        $allCodes = collect($masterCodeFields)
            ->flatMap(function (string $type, string $field) use ($tasks) {
                return collect($tasks)->pluck($field)->filter(fn ($code) => filled($code))->map(fn ($code) => trim((string) $code));
            })
            ->unique()
            ->values();
        $validCodeRows = $allCodes->isEmpty()
            ? collect()
            : MasterRecord::query()
                ->where('workspace_id', $workspaceId)
                ->whereIn('type', array_values($masterCodeFields))
                ->whereIn('code', $allCodes)
                ->where('status', 'active')
                ->get(['type', 'code']);
        $validCodeLookup = $validCodeRows
            ->mapWithKeys(fn (MasterRecord $row) => [$row->type.'|'.trim((string) $row->code) => true]);

        foreach ($tasks as $index => $task) {
            $assigneeId = filled($task['default_assignee_id'] ?? null) ? (int) $task['default_assignee_id'] : null;
            if ($assigneeId && ! $validAssigneeLookup->has($assigneeId)) {
                $errors["tasks.$index.default_assignee_id"] = 'The selected assignee is invalid.';
            }

            foreach ($masterIdFields as $field => $type) {
                $id = filled($task[$field] ?? null) ? (int) $task[$field] : null;
                if ($field === 'default_department_id' && ($task['default_assignee_type'] ?? 'user') === 'supplier') continue;
                if ($id && ! $validMasterLookup->has($type.'|'.$id)) {
                    $errors["tasks.$index.$field"] = 'The selected option is invalid.';
                }
            }

            foreach ($masterCodeFields as $field => $type) {
                $code = trim((string) ($task[$field] ?? ''));
                if ($code !== '' && ! $validCodeLookup->has($type.'|'.$code)) {
                    $errors["tasks.$index.$field"] = 'The selected option is invalid.';
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function cancel(): void
    {
        $this->redirectRoute('task-pack.setup', navigate: true);
    }

    private function blankTask(): array
    {
        return [
            'id' => null,
            'automation_key' => '',
            'title' => '',
            'description' => '',
            'color' => $this->defaultTaskColor(count($this->tasks)),
            'default_assignee_id' => null,
            'default_assignee_type' => 'user',
            'default_assignee_label' => 'Unassigned',
            'default_department_id' => null,
            'default_department_label' => 'No department default',
            'priority_id' => null,
            'document_category_id' => null,
            'document_category_label' => 'No task-specific file',
            'due_offset_days' => 1,
            'standard_duration_value' => 8.0,
            'standard_duration_unit' => 'TPD-001',
            'timer_start_rule' => 'TPS-001',
            'timer_stop_rule' => 'TPE-001',
            'work_calendar' => 'TPW-001',
            'set_due_from_standard_duration' => true,
            'allow_efficiency_override' => false,
            'is_required' => true,
        ];
    }

    private function defaultTaskColor(int $index): string
    {
        $palette = [
            '#2563EB', '#7C3AED', '#0891B2', '#0F766E',
            '#16A34A', '#CA8A04', '#EA580C', '#DC2626',
            '#DB2777', '#4F46E5', '#0369A1', '#27855A',
        ];

        return $palette[$index % count($palette)];
    }

    public function render()
    {
        $master = app(MasterDataService::class);
        $user = auth()->user();

        $assigneeFilterOptions = $this->optionsReady
            ? app(FilterOptionService::class)->options($user, 'users', 'task-pack-setup', '', null, 5)
            : collect();

        $departmentFilterOptions = $this->optionsReady
            ? app(FilterOptionService::class)->options($user, 'department-records', 'task-pack-setup', '', null, 5)
            : collect();

        $documentFilterOptions = $this->optionsReady
            ? app(FilterOptionService::class)->options($user, 'document-category-records', 'task-pack-setup', '', null, 5)
            : collect();

        return view('livewire.task-pack-setup.form', [
            'assigneeFilterOptions' => $assigneeFilterOptions,
            'departmentFilterOptions' => $departmentFilterOptions,
            'documentFilterOptions' => $documentFilterOptions,
            'priorities' => $this->optionsReady ? $master->active('priority') : collect(),
            'durationUnitOptions' => $this->optionsReady ? $master->active('task_pack_duration_unit') : collect(),
            'timerStartOptions' => $this->optionsReady ? $master->active('task_pack_timer_start') : collect(),
            'timerStopOptions' => $this->optionsReady ? $master->active('task_pack_timer_stop') : collect(),
            'workCalendarOptions' => $this->optionsReady ? $master->active('task_pack_work_calendar') : collect(),
            'canDeleteTaskPack' => (bool) ($user?->canModule('taskpacks', 'delete')),
        ]);
    }
}
