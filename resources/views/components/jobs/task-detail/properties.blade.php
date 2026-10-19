            <section class="ft-task-property-grid ft-friendly-task-properties">
                <div
                    class="ft-task-property ft-inline-edit-shell"
                    x-data="window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-assignee'), label: 'task assignee', value: @js($task->assignee_id ?? ''), display: @js($task->assignee?->name ?? 'Unassigned'), avatarUrl: @js($task->assignee?->profileImageUrl() ?? '') })"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    x-on:click.outside="if (editing) cancelEdit()"
                    x-on:ft-inline-remote-cancel.stop="cancelEdit()"
                    x-on:task-assignee-updated.window="if (Number($event.detail?.taskId) === Number({{ $task->id }})) syncConfirmed(String($event.detail?.assigneeId ?? ''), String($event.detail?.assigneeName ?? 'Unassigned'), { avatarUrl: String($event.detail?.avatarUrl ?? '') })"
                    x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateSelectedTaskField('assignee_id', draftValue), { avatarUrl: String($event.detail?.avatarUrl ?? '') })"
                >
                    <small>Assignee</small>
                    <div x-show="!editing" class="ft-task-property-display ft-inline-person-live">
                        <x-ui.inline-live-avatar :size="26" />
                        <b class="ft-property-value" x-text="display">{{ $task->assignee?->name ?? 'Unassigned' }}</b>
                        @if($canAssignTask)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit assignee" aria-label="Edit task assignee" x-on:click.stop="openRemotePicker($event.currentTarget)"><x-ui.detail-icon name="edit" /></button>@endif
                    </div>
                    @if($canAssignTask)
                        <div x-cloak x-show="editing" class="ft-task-property-inline-editor ft-task-property-assignee-editor">
                            <x-ui.inline-remote-user
                                :value="$task->assignee_id ?? ''"
                                parent-type="job"
                                :parent-id="$task->flow_job_id"
                                :selected-label="$task->assignee?->name ?? 'Unassigned'"
                                trigger-class="ft-task-property-inline-input"
                                variant="compact"
                                :menu-width="300"
                            />
                        </div>
                        <x-ui.inline-save-state compact />
                    @endif
                </div>
                <div
                    class="ft-task-property ft-inline-edit-shell"
                    x-data="{ ...window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-status'), label: 'task status', value: @js($task->status), display: @js($task->status) }), statusColor: @js($currentStatusColor) }"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    x-on:click.outside="if (editing) cancelEdit()"
                >
                    <small>Status</small>
                    <div x-show="!editing" class="ft-task-property-display"><span class="status-dot {{ $currentStatusColor ? 'ft-master-color-dot' : 'blue' }}" style="{{ \App\Support\MasterColor::style($currentStatusColor) }}" x-bind:style="statusColor ? '--ft-master-color:'+statusColor : ''"></span><b class="ft-property-value" x-text="display">{{ $task->status }}</b>@if($canEditTask)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit status" aria-label="Edit task status" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.status?.showPicker ? $refs.status.showPicker() : $refs.status?.focus())"><x-ui.detail-icon name="edit" /></button>@endif</div>
                    @if($canEditTask)
                        <div x-cloak x-show="editing" class="ft-task-property-inline-editor"><select data-master-color-select x-ref="status" x-model="draftValue" class="ft-task-property-inline-input {{ $currentStatusColor ? 'ft-master-color' : '' }}" style="{{ \App\Support\MasterColor::style($currentStatusColor) }}" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="statusColor=String($event.target.selectedOptions[0]?.dataset?.color || ''); window.FlowTrack.ui.masterColor?.applySelect($event.target); commit($event.target.value, selectedLabel($event), () => $wire.updateSelectedTaskField('status', draftValue)).then(async (ok) => { if (ok && lastResponse?.refresh) await $wire.$refresh(); })">@foreach($taskStatuses as $status)<option value="{{ $status }}" data-color="{{ $masterData->colorFor('order_task_status', $status) }}">{{ $status }}</option>@endforeach</select></div>
                        <x-ui.inline-save-state compact />
                    @endif
                </div>
                <div
                    class="ft-task-property ft-inline-edit-shell"
                    x-data="{ ...window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-priority'), label: 'task priority', value: @js($task->priority), display: @js($task->priority) }), priorityColor: @js($currentPriorityColor) }"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    x-on:click.outside="if (editing) cancelEdit()"
                >
                    <small>Priority</small>
                    <div x-show="!editing" class="ft-task-property-display"><span class="status-dot ft-master-color-dot" style="{{ \App\Support\MasterColor::style($currentPriorityColor) }}" x-bind:style="priorityColor ? '--ft-master-color:'+priorityColor : ''"></span><b class="ft-property-value" x-text="display">{{ $task->priority }}</b>@if($canEditTask)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit priority" aria-label="Edit task priority" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.priority?.showPicker ? $refs.priority.showPicker() : $refs.priority?.focus())"><x-ui.detail-icon name="edit" /></button>@endif</div>
                    @if($canEditTask)
                        <div x-cloak x-show="editing" class="ft-task-property-inline-editor"><select data-master-color-select x-ref="priority" x-model="draftValue" class="ft-task-property-inline-input ft-master-color" style="{{ \App\Support\MasterColor::style($currentPriorityColor) }}" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="const nextColor=String($event.target.selectedOptions[0]?.dataset?.color || ''); window.FlowTrack.ui.masterColor?.applySelect($event.target); commit($event.target.value, selectedLabel($event), () => $wire.updateSelectedTaskField('priority', draftValue)).then(ok => { if(ok) priorityColor=nextColor; });">@foreach($priorities as $priority)<option value="{{ $priority->name }}" data-color="{{ $masterData->displayColorFor('priority', $priority->name) }}">{{ $priority->name }}</option>@endforeach</select></div>
                        <x-ui.inline-save-state compact />
                    @endif
                </div>
                <div class="ft-task-property"><small>Phase</small><div class="ft-task-property-display"><x-ui.phase-label :phase="$task->phase" class="ft-property-value" /></div></div>
                <div
                    class="ft-task-property ft-inline-edit-shell"
                    x-data="window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-start-date'), label: 'task start date', value: @js($effectiveStartDate?->format('Y-m-d') ?? ''), display: @js($effectiveStartDate?->format('M j, Y') ?? 'Not set') })"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    x-on:click.outside="if (editing) cancelEdit()"
                >
                    <small>Start date</small>
                    <div x-show="!editing" class="ft-task-property-display"><x-ui.detail-icon name="calendar" class="ft-calendar-glyph" /><b class="ft-property-value" x-text="display">{{ $effectiveStartDate?->format('M j, Y') ?? 'Not set' }}</b>@if($canEditTask)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit start date" aria-label="Edit task start date" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.start?.showPicker ? $refs.start.showPicker() : $refs.start?.focus())"><x-ui.detail-icon name="edit" /></button>@endif</div>
                    @if($canEditTask)
                        <div x-cloak x-show="editing" class="ft-task-property-inline-editor"><input x-ref="start" x-model="draftValue" class="ft-task-property-inline-input" type="date" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateSelectedTaskField('start_date', draftValue))"></div>
                        <x-ui.inline-save-state compact />
                    @endif
                </div>
                <div
                    class="ft-task-property ft-inline-edit-shell"
                    x-data="window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-due-date'), label: 'task due date', value: @js($task->due_date?->format('Y-m-d') ?? ''), display: @js($task->due_date?->format('M j, Y') ?? 'Not set') })"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    x-on:click.outside="if (editing) cancelEdit()"
                >
                    <small>Due date</small>
                    <div x-show="!editing" class="ft-task-property-display {{ ($task->due_date && \App\Support\UserLocalTime::isDatePast($task->due_date)) && !$task->completed_at ? 'danger-text' : '' }}"><x-ui.detail-icon name="calendar" class="ft-calendar-glyph" /><b class="ft-property-value" x-text="display">{{ $task->due_date?->format('M j, Y') ?? 'Not set' }}</b>@if($canEditTask)<button type="button" :disabled="status === 'saving'" class="ft-property-edit-button" title="Edit due date" aria-label="Edit task due date" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.due?.showPicker ? $refs.due.showPicker() : $refs.due?.focus())"><x-ui.detail-icon name="edit" /></button>@endif</div>
                    @if($canEditTask)
                        <div x-cloak x-show="editing" class="ft-task-property-inline-editor"><input x-ref="due" x-model="draftValue" class="ft-task-property-inline-input" type="date" x-on:keydown.escape.prevent="cancelEdit()" x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateSelectedTaskField('due_date', draftValue))"></div>
                        <x-ui.inline-save-state compact />
                    @endif
                </div>
                <div class="ft-task-property ft-task-completed-property"
                    x-data="{ completedDate: @js($completedOn?->format('M j, Y') ?? '—'), completedTime: @js($completedOn?->format('g:i A') ?? '') }"
                    x-on:task-completion-updated.window="completedDate = $event.detail.completedDate || '—'; completedTime = $event.detail.completedTime || ''">
                    <small>Completed On</small>
                    <div class="ft-task-property-display"><x-ui.detail-icon name="calendar" class="ft-calendar-glyph" /><b class="ft-property-value ft-completed-date-time"><span x-text="completedDate">{{ $completedOn?->format('M j, Y') ?? '—' }}</span><span class="ft-completed-time" x-show="completedTime" x-text="completedTime">{{ $completedOn?->format('g:i A') ?? '' }}</span></b></div>
                </div>
            </section>
