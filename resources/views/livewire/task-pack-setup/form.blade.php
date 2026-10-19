@php
    $masterData = app(\App\Services\MasterDataService::class);
@endphp
<div class="ft-admin-reference ft-taskpack-form-page">
    <div class="ft-admin-form-top">
        <div>
            <div class="ft-admin-breadcrumb">{{ $taskPackId ? 'Edit Task Pack' : 'Add Task Pack' }}</div>
            <h1>{{ $taskPackId ? 'Edit Task Pack' : 'Add Task Pack' }}</h1>
            <p>Build the task sequence on a full page for easier editing.</p>
        </div>
        <a href="{{ route('task-pack.setup') }}" wire:navigate class="ft-admin-back">← Back to Task Pack Setup</a>
    </div>

    <form wire:submit="save" class="ft-admin-form-card" data-ft-feedback-scope="form">
        <div class="ft-admin-form-card-head">
            <h2>{{ $taskPackId ? 'Edit Task Pack' : 'Create Task Pack' }}</h2>
            <p>Build the complete reusable task sequence activated by workflow phases.</p>
        </div>

        <div class="ft-admin-form-body">
            <div class="ft-admin-field">
                <label>Task Pack code</label>
                <div class="ft-admin-locked">{{ $packCode }}</div>
                <small>Automatically generated and permanently locked.</small>
            </div>

            <div class="ft-admin-field">
                <label>Status</label>
                <select wire:model="packStatus">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                @error('packStatus')<div class="validation-error">{{ $message }}</div>@enderror
            </div>

            <div class="ft-admin-field">
                <label>Task Pack name *</label>
                <input type="text" wire:model="packName" placeholder="e.g. Quality Inspection">
                @error('packName')<div class="validation-error">{{ $message }}</div>@enderror
            </div>

            <div class="ft-admin-field">
                <label>Description</label>
                <textarea wire:model="packDescription" rows="3" placeholder="Explain when this Task Pack is used..."></textarea>
                @error('packDescription')<div class="validation-error">{{ $message }}</div>@enderror
            </div>

            <div class="ft-sequence-title-row">
                <div><h2>Task sequence</h2><p>Tasks are created in this order when the phase becomes active.</p></div>
            </div>

            @if(collect($tasks)->contains(fn ($task) => filled($task['automation_key'] ?? null)))
                <div class="ft-workflow-rule-note" style="margin-bottom:12px">
                    <b>Order workflow Task Pack</b>
                    <p>Tasks marked <b>Core Order logic</b> carry the existing Order workflow automation keys. You may edit their title, assignee, department, priority, timing and document setup, but cannot remove them or change their relative order. Extra tasks can be added normally.</p>
                </div>
            @endif

            @error('tasks')<div class="validation-error">{{ $message }}</div>@enderror

            <div class="ft-task-editor-list">
                @foreach($tasks as $index => $task)
                    <section class="ft-task-editor-card ft-task-editor-prototype ft-task-editor-card--colored" style="{{ \App\Support\MasterColor::style($task['color'] ?? '#2563EB') }}" wire:key="task-pack-form-task-{{ $task['id'] ?? 'new-'.$index }}-{{ $index }}">
                        <div class="ft-task-editor-head">
                            <div>
                                <h3>Task {{ $index + 1 }} @if(filled($task['automation_key'] ?? null))<span class="ft-auto-pill automatic">Core Order logic</span>@endif</h3>
                                <p>Sequence {{ $index + 1 }} in this Task Pack @if(filled($task['automation_key'] ?? null))· {{ $task['automation_key'] }}@endif</p>
                            </div>
                            <div class="ft-task-editor-actions" aria-label="Task sequence actions">
                                <button type="button" class="ft-task-move-button" wire:click="moveTask({{ $index }}, -1)" @disabled($index === 0) aria-label="Move task up">↑</button>
                                <button type="button" class="ft-task-move-button" wire:click="moveTask({{ $index }}, 1)" @disabled($index === count($tasks)-1) aria-label="Move task down">↓</button>
                                @if(blank($task['automation_key'] ?? null) && (empty($task['id']) || $canDeleteTaskPack))
                                    <button type="button" class="ft-task-remove-button" wire:click="removeTask({{ $index }})">Remove</button>
                                @elseif(filled($task['automation_key'] ?? null))
                                    <span class="small muted">Protected</span>
                                @endif
                            </div>
                        </div>

                        <div class="ft-admin-field ft-task-prototype-full">
                            <label>Task title *</label>
                            <input type="text" wire:model="tasks.{{ $index }}.title" placeholder="Prepare Artwork">
                            @error("tasks.$index.title")<div class="validation-error">{{ $message }}</div>@enderror
                        </div>

                        <div class="ft-admin-field ft-task-prototype-full">
                            <label>Description</label>
                            <textarea wire:model="tasks.{{ $index }}.description" rows="2" placeholder="Add clear instructions or completion criteria..."></textarea>
                            @error("tasks.$index.description")<div class="validation-error">{{ $message }}</div>@enderror
                        </div>

                        <div class="ft-admin-field ft-task-prototype-full ft-task-color-field" style="{{ \App\Support\MasterColor::style($task['color'] ?? '#2563EB') }}">
                            <label>Task color *</label>
                            <div class="ft-task-color-picker-row">
                                <input class="ft-task-color-picker" type="color" wire:model.live="tasks.{{ $index }}.color" aria-label="Choose color for task {{ $index + 1 }}">
                                <input class="ft-task-color-hex" type="text" maxlength="7" wire:model.blur="tasks.{{ $index }}.color" placeholder="#2563EB" aria-label="Task {{ $index + 1 }} hex color">
                                <span class="ft-task-color-preview"><i></i>Used for this task anywhere it appears.</span>
                            </div>
                            @error("tasks.$index.color")<div class="validation-error">{{ $message }}</div>@enderror
                        </div>

                        @if($optionsReady)
                            <div class="ft-task-prototype-grid" wire:key="task-pack-options-{{ $index }}">
                                <div class="ft-admin-field">
                                    <label for="taskpack-assignee-type-{{ $index }}">Default assignee</label>
                                    <select id="taskpack-assignee-type-{{ $index }}" wire:model.live="tasks.{{ $index }}.default_assignee_type">
                                        <option value="user">Internal user</option>
                                        <option value="supplier">Supplier</option>
                                    </select>
                                    <small>Supplier uses the assigned order product supplier when there is exactly one. Available for the first five Order stages.</small>
                                </div>
                                <div class="ft-admin-field">
                                    <x-ui.search-select
                                        class="ft-taskpack-assignee-filter"
                                        :label="($task['default_assignee_type'] ?? 'user') === 'supplier' ? 'Internal backup assignee (optional)' : 'Internal user'"
                                        property="tasks.{{ $index }}.default_assignee_id"
                                        type="users"
                                        context="task-pack-setup"
                                        action="setTaskPackAssignee"
                                        :value="$task['default_assignee_id'] ?? ''"
                                        placeholder="Unassigned"
                                        :selected-label="$task['default_assignee_label'] ?? 'Unassigned'"
                                        :initial-options="$assigneeFilterOptions"
                                        :menu-width="420"
                                        :fixed-menu="true"
                                        wire:key="task-pack-assignee-{{ $task['id'] ?? 'new-'.$index }}-{{ $index }}-{{ $task['default_assignee_type'] ?? 'user' }}-{{ $task['default_assignee_id'] ?? 'none' }}"
                                    />
                                    @if(($task['default_assignee_type'] ?? 'user') === 'supplier')
                                        <small>This internal user can perform the same task if the supplier does not.</small>
                                    @endif
                                    @error("tasks.$index.default_assignee_id")<div class="validation-error">{{ $message }}</div>@enderror
                                </div>

                                @if(($task['default_assignee_type'] ?? 'user') !== 'supplier')
                                <div class="ft-admin-field">
                                    <x-ui.search-select
                                        class="ft-taskpack-assignee-filter"
                                        label="Default department"
                                        property="tasks.{{ $index }}.default_department_id"
                                        type="department-records"
                                        context="task-pack-setup"
                                        action="setTaskPackDepartment"
                                        :value="$task['default_department_id'] ?? ''"
                                        placeholder="No department default"
                                        :selected-label="$task['default_department_label'] ?? 'No department default'"
                                        :initial-options="$departmentFilterOptions"
                                        :menu-width="420"
                                        :fixed-menu="true"
                                        wire:key="task-pack-department-{{ $task['id'] ?? 'new-'.$index }}-{{ $index }}-{{ $task['default_department_id'] ?? 'none' }}"
                                    />
                                    @error("tasks.$index.default_department_id")<div class="validation-error">{{ $message }}</div>@enderror
                                </div>

                                @endif

                                <div class="ft-admin-field">
                                    <label>Priority</label>
                                    <select data-master-color-select wire:model="tasks.{{ $index }}.priority_id">
                                        <option value="">Use Order priority</option>
                                        @foreach($priorities as $priority)
                                            <option value="{{ $priority->id }}" data-color="{{ $masterData->displayColorFor('priority', $priority->name) }}">{{ $priority->name }}</option>
                                        @endforeach
                                    </select>
                                    @error("tasks.$index.priority_id")<div class="validation-error">{{ $message }}</div>@enderror
                                </div>

                                <div class="ft-admin-field">
                                    <x-ui.search-select
                                        class="ft-taskpack-assignee-filter"
                                        label="Required document"
                                        property="tasks.{{ $index }}.document_category_id"
                                        type="document-category-records"
                                        context="task-pack-setup"
                                        action="setTaskPackDocumentCategory"
                                        :value="$task['document_category_id'] ?? ''"
                                        placeholder="No task-specific file"
                                        :selected-label="$task['document_category_label'] ?? 'No task-specific file'"
                                        :initial-options="$documentFilterOptions"
                                        :menu-width="420"
                                        :fixed-menu="true"
                                        wire:key="task-pack-document-{{ $task['id'] ?? 'new-'.$index }}-{{ $index }}-{{ $task['document_category_id'] ?? 'none' }}"
                                    />
                                    <small>The file must be attached before this task can be completed.</small>
                                    @error("tasks.$index.document_category_id")<div class="validation-error">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        @else
                            @if($loop->first)
                                <div class="ft-taskpack-options-placeholder" wire:key="task-pack-options-loading-trigger-{{ $index }}">
                                    <x-ui.progressive-section-loader section="task-options" :rows="4" message="Loading assignee, department, priority and timer options when needed…" />
                                </div>
                            @else
                                <div class="ft-taskpack-options-placeholder ft-task-prototype-grid" wire:key="task-pack-options-loading-{{ $index }}" role="status" aria-live="polite" aria-busy="true">
                                    @for($field = 0; $field < 4; $field++)
                                        <div><span></span><span></span></div>
                                    @endfor
                                </div>
                            @endif
                        @endif

                        <label class="ft-required-task-check ft-required-task-prototype">
                            <input type="checkbox" wire:model="tasks.{{ $index }}.is_required">
                            <span>Required task</span>
                        </label>

                        <section class="ft-efficiency-standard" aria-label="Time and efficiency standard">
                            <div class="ft-efficiency-config">
                                <header class="ft-efficiency-head">
                                    <span class="ft-efficiency-clock" aria-hidden="true">
                                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                    </span>
                                    <div>
                                        <h4>Time &amp; efficiency standard</h4>
                                        <p>Define the expected active working time for this task.</p>
                                    </div>
                                </header>

                                <div class="ft-efficiency-fields">
                                    <div class="ft-admin-field">
                                        <label>Standard duration *</label>
                                        <div class="ft-duration-control">
                                            <input type="number" min="0.01" max="10000" step="0.01" inputmode="decimal" wire:model.live.debounce.350ms="tasks.{{ $index }}.standard_duration_value">
                                            <select wire:model.live="tasks.{{ $index }}.standard_duration_unit">
                                                @forelse($durationUnitOptions as $option)
                                                    <option value="{{ $option->code }}">{{ $option->name }}</option>
                                                @empty
                                                    <option value="">No duration units configured</option>
                                                @endforelse
                                            </select>
                                        </div>
                                        @error("tasks.$index.standard_duration_value")<div class="validation-error">{{ $message }}</div>@enderror
                                        @error("tasks.$index.standard_duration_unit")<div class="validation-error">{{ $message }}</div>@enderror
                                    </div>

                                    <div class="ft-admin-field">
                                        <label>Timer starts</label>
                                        <select wire:model="tasks.{{ $index }}.timer_start_rule">
                                            @forelse($timerStartOptions as $option)
                                                <option value="{{ $option->code }}">{{ $option->name }}</option>
                                            @empty
                                                <option value="">No timer start rules configured</option>
                                            @endforelse
                                        </select>
                                        @error("tasks.$index.timer_start_rule")<div class="validation-error">{{ $message }}</div>@enderror
                                    </div>

                                    <div class="ft-admin-field">
                                        <label>Timer stops</label>
                                        <select wire:model="tasks.{{ $index }}.timer_stop_rule">
                                            @forelse($timerStopOptions as $option)
                                                <option value="{{ $option->code }}">{{ $option->name }}</option>
                                            @empty
                                                <option value="">No timer stop rules configured</option>
                                            @endforelse
                                        </select>
                                        @error("tasks.$index.timer_stop_rule")<div class="validation-error">{{ $message }}</div>@enderror
                                    </div>

                                    <div class="ft-admin-field">
                                        <label>Work calendar</label>
                                        <select wire:model="tasks.{{ $index }}.work_calendar">
                                            @forelse($workCalendarOptions as $option)
                                                <option value="{{ $option->code }}">{{ $option->taskPackWorkCalendarLabel() }}</option>
                                            @empty
                                                <option value="">No work calendars configured</option>
                                            @endforelse
                                        </select>
                                        @error("tasks.$index.work_calendar")<div class="validation-error">{{ $message }}</div>@enderror
                                    </div>
                                </div>

                                <div class="ft-efficiency-checks ft-efficiency-prototype-options" aria-label="Prototype-only efficiency options">
                                    <label aria-disabled="true" class="ft-disabled-pointer">
                                        <input type="checkbox" checked tabindex="-1" aria-disabled="true">
                                        <span><b>Set the task due date from the standard duration</b><small>Calculated using the selected business calendar.</small></span>
                                    </label>
                                    <label aria-disabled="true" class="ft-disabled-pointer">
                                        <input type="checkbox" tabindex="-1" aria-disabled="true">
                                        <span><b>Allow authorized users to override this benchmark</b></span>
                                    </label>
                                </div>
                            </div>
                        </section>
                    </section>
                @endforeach
            </div>

            <div class="ft-task-sequence-add-row">
                <button type="button" class="ft-add-soft" wire:click="addTask">＋ Add Task</button>
            </div>
        </div>

        <div class="ft-admin-form-footer ft-taskpack-form-footer">
            <p>Changes apply to new tasks created from this Task Pack.</p>
            <button type="button" class="ft-admin-cancel" wire:click="cancel">Cancel</button>
            <button type="submit" class="ft-admin-primary">{{ $taskPackId ? 'Save Task Pack' : 'Create Task Pack' }}</button>
        </div>
        @error('options')<div class="validation-error">{{ $message }}</div>@enderror
    </form>
</div>
