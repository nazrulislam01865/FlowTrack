@foreach($workGroups as $group)
    <tbody
        class="ft-my-task-order-group"
        wire:key="my-work-order-{{ $group['id'] }}"
        x-data="{ open: true }"
        x-effect="open = groupsExpanded"
    >
        <tr class="ft-my-task-order-summary-row">
            <td colspan="8">
                <div class="ft-my-task-order-summary">
                    <button
                        type="button"
                        class="ft-my-task-collapse"
                        x-on:click="open = !open"
                        x-bind:aria-expanded="open.toString()"
                        aria-label="Toggle {{ $group['number'] }} tasks"
                    >
                        <span x-text="open ? '⌄' : '›'">⌄</span>
                    </button>

                    <div class="ft-my-task-order-identity">
                        @if($group['route'])
                            <a class="order-cell-id" href="{{ $group['route'] }}" wire:navigate>{{ $group['number'] }}</a>
                        @else
                            <span class="order-cell-id">{{ $group['number'] }}</span>
                        @endif
                        <span class="order-cell-ref">{{ $group['title'] }}</span>
                    </div>

                    <div class="ft-my-task-summary-meta">
                        <span><b>Client</b>{{ $group['client'] }}</span>
                        <span>
                            <b>Stage</b>
                            <i class="stage-chip" style="--stage:{{ $group['stageColor'] ?: '#2563EB' }}">{{ $group['stage'] }}</i>
                        </span>
                        <span class="ft-my-task-summary-progress">
                            <b>Progress</b>
                            <i class="row-progress-track"><i style="width:{{ $group['progress'] }}%"></i></i>
                            <strong>{{ $group['progress'] }}%</strong>
                        </span>
                        <span><b>Visible work</b>{{ $group['taskCount'] }} {{ $group['taskCount'] === 1 ? 'task' : 'tasks' }}</span>
                    </div>
                </div>
            </td>
        </tr>

        @foreach($group['tasks'] as $task)
            @php
                $taskRowStyle = \App\Support\MasterColor::taskRowStyle($task['taskColor'] ?? null);
            @endphp
            <tr
                class="order-row ft-my-task-row {{ filled($task['taskColor'] ?? null) ? 'has-task-color' : '' }}"
                style="{{ $taskRowStyle }}"
                wire:key="my-work-task-{{ $task['id'] }}"
                x-show="open"
                x-data="{
                    saving:false,
                    version:@js($task['version']),
                    currentStatus:@js($task['status']),
                    async saveStatus(event){
                        const select=event.currentTarget;
                        const previous=this.currentStatus;
                        const next=select.value;
                        if(next===previous||this.saving)return;
                        this.saving=true;
                        select.disabled=true;
                        try{
                            const result=await $wire.updateTaskStatus({{ $task['id'] }},next,this.version);
                            if(!result?.ok){select.value=previous;window.FlowTrack.ui.masterColor?.applySelect(select);return;}
                            this.currentStatus=result.status||next;
                            this.version=result.version||this.version;
                            if(result.refresh || (result.completed && @js($hideCompleted)))await $wire.$refresh();
                        }catch(error){
                            select.value=previous;
                            window.FlowTrack.ui.masterColor?.applySelect(select);
                        }finally{
                            this.saving=false;
                            select.disabled=false;
                        }
                    }
                }"
                x-bind:class="{ 'is-saving': saving }"
                x-on:my-work-task-version.stop="if ($event.detail?.version) version = String($event.detail.version)"
            >
                <td>
                    <a class="order-cell-id ft-my-task-title" href="{{ $task['route'] }}" wire:navigate>{{ $task['title'] }}</a>
                    <span class="order-cell-ref">{{ $task['number'] }} · {{ $group['number'] }}</span>
                </td>

                <td>
                    <span class="stage-chip" style="--stage:{{ $task['phaseColor'] ?: '#2563EB' }}">{{ $task['phase'] }}</span>
                </td>

                <td>
                    <div
                        class="owner-delivery assignee-editor ft-inline-edit-shell ft-my-task-assignee"
                        wire:key="my-work-task-{{ $task['id'] }}-assignee-{{ $task['assigneeId'] ?: 0 }}"
                        title="{{ $task['assignee'] }}"
                        x-data="window.FlowTrack.ui.inlineEdit({ key: @js('my-work-task-'.$task['id'].'-assignee'), label: 'task assignee', value: @js($task['assigneeId'] ?? ''), display: @js($task['assignee']), avatarUrl: @js($task['assigneeAvatar'] ?? '') })"
                        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                        x-on:click.outside="if (editing) cancelEdit()"
                        x-on:ft-inline-remote-cancel.stop="cancelEdit()"
                        x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateTaskAssignee({{ $task['id'] }}, draftValue, version), { avatarUrl: String($event.detail?.avatarUrl ?? '') }).then(async (ok) => { if (!ok) return; if (lastResponse?.version) $dispatch('my-work-task-version', { version: lastResponse.version }); if (lastResponse?.refresh) await $wire.$refresh(); })"
                    >
                        <div class="ft-my-task-assignee-display" x-show="!editing">
                            <x-ui.inline-live-avatar :size="28" />
                            <span class="ft-my-task-assignee-copy">
                                <b x-text="display">{{ $task['assignee'] }}</b>
                                <small>Task assignee</small>
                            </span>
                            @if($task['canAssign'])
                                <button
                                    x-show="!editing"
                                    :disabled="status === 'saving'"
                                    type="button"
                                    class="ft-inline-edit-button compact ft-my-task-edit-button"
                                    title="Edit assignee"
                                    aria-label="Edit assignee for {{ $task['title'] }}"
                                    x-on:click.stop="openRemotePicker($event.currentTarget)"
                                >✎</button>
                            @endif
                        </div>

                        @if($task['canAssign'])
                            <div x-cloak x-show="editing" class="ft-my-task-assignee-picker">
                                <x-ui.inline-remote-user
                                    :value="$task['assigneeId'] ?? ''"
                                    :selected-label="$task['assignee']"
                                    parent-type="job"
                                    :parent-id="$group['id']"
                                    trigger-class="assignee-picker-trigger"
                                    variant="compact"
                                    :menu-width="300"
                                />
                            </div>
                            <x-ui.inline-save-state compact />
                        @endif
                    </div>
                </td>

                <td>
                    <span
                        class="ft-inline-edit-shell ft-my-task-due {{ $task['dueTone'] }}"
                        x-data="window.FlowTrack.ui.inlineEdit({ key: @js('my-work-task-'.$task['id'].'-due-date'), label: 'task due date', value: @js($task['dueValue']), display: @js($task['dueDisplay']) })"
                        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    >
                        <span x-show="!editing" x-text="display" class="ft-task-inline-display">{{ $task['dueDisplay'] }}</span>
                        @if($task['canEdit'])
                            <button
                                x-show="!editing"
                                :disabled="status === 'saving'"
                                type="button"
                                class="ft-inline-edit-button compact ft-my-task-edit-button"
                                title="Edit due date"
                                aria-label="Edit due date for {{ $task['title'] }}"
                                x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.myWorkDue.showPicker ? $refs.myWorkDue.showPicker() : $refs.myWorkDue.focus())"
                            >✎</button>
                            <input
                                x-ref="myWorkDue"
                                x-cloak
                                x-show="editing"
                                x-model="draftValue"
                                class="ft-task-inline-input"
                                type="date"
                                x-on:keydown.escape.prevent="cancelEdit()"
                                x-on:blur="if (editing) cancelEdit()"
                                x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateTaskDueDate({{ $task['id'] }}, draftValue))"
                            >
                            <x-ui.inline-save-state compact />
                        @endif
                    </span>
                    <span class="order-cell-ref {{ $task['dueTone'] === 'overdue' ? 'ft-my-task-overdue' : '' }}">{{ $task['due'] }}</span>
                </td>

                <td>
                    <select
                        data-master-color-select
                        class="status-select ft-my-task-status {{ $task['statusColor'] ? 'ft-master-color' : '' }}"
                        style="{{ \App\Support\MasterColor::style($task['statusColor']) }}"
                        @if($task['canEdit'])
                            x-on:change="saveStatus($event); window.FlowTrack.ui.masterColor?.applySelect($event.currentTarget)"
                        @else
                            disabled
                        @endif
                        aria-label="Status for {{ $task['title'] }}"
                    >
                        @if(!in_array($task['status'], $statusOptions, true))
                            <option value="{{ $task['status'] }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $task['status']) }}" selected>{{ $task['status'] }}</option>
                        @endif
                        @foreach($statusOptions as $statusOption)
                            <option value="{{ $statusOption }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('order_task_status', $statusOption) }}" @selected($statusOption === $task['status'])>{{ $statusOption }}</option>
                        @endforeach
                    </select>
                </td>

                <td>
                    <span
                        class="ft-my-task-flag {{ $task['flagColor'] ? 'ft-master-color' : '' }}"
                        style="{{ \App\Support\MasterColor::style($task['flagColor']) }}"
                    >{{ $task['flag'] }}</span>
                </td>

                <td>
                    <span class="stage-table-note">{{ $task['updated'] }}</span>
                </td>

                <td>
                    <a class="stage-action ft-my-task-open" href="{{ $task['route'] }}" wire:navigate>Open</a>
                </td>
            </tr>
        @endforeach
    </tbody>
@endforeach
