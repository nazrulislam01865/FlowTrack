@props(['job', 'row'])

@php
    $task = $row['task'];
    $assigneeName = (string) ($row['assignee_name'] ?? ($task->assignee?->name ?: 'Unassigned'));
    $canAssign = (bool) ($row['can_assign'] ?? false);
    $canEdit = (bool) ($row['can_edit'] ?? false);
    $dueValue = $task->due_date?->format('Y-m-d') ?? '';
    $dueLabel = $task->due_date?->format('M j, Y') ?? 'Set due date';
@endphp

<div class="ft-shipment-task__meta">
    <div
        class="ft-shipment-meta-block ft-shipment-meta-block--assignee ft-inline-edit-shell"
        x-data="window.FlowTrack.ui.inlineEdit({ key:@js('shipment-task-'.$task->id.'-assignee'), label:'task assignee', value:@js($task->assignee_id ?? ''), display:@js($assigneeName), avatarUrl:@js($task->assignee?->profileImageUrl() ?? '') })"
        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
        x-on:click.outside="if(editing) cancelEdit()"
        x-on:ft-inline-remote-cancel.stop="cancelEdit()"
        x-on:task-assignee-updated.window="if (Number($event.detail?.taskId) === Number({{ $task->id }})) syncConfirmed(String($event.detail?.assigneeId ?? ''), String($event.detail?.assigneeName ?? 'Unassigned'), { avatarUrl:String($event.detail?.avatarUrl ?? '') })"
        x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateTaskAssigneeFromJob({{ $task->id }}, draftValue), { avatarUrl:String($event.detail?.avatarUrl ?? '') })"
    >
        <span>ASSIGNEE</span>

        @if($canAssign)
            <button
                x-ref="assigneeAnchor"
                :disabled="status === 'saving'"
                type="button"
                class="ft-shipment-inline-assignee"
                :class="{ 'is-open': editing }"
                title="Edit assignee"
                aria-label="Edit task assignee"
                x-on:click.stop="openRemotePicker($refs.assigneeAnchor)"
            >
                <x-ui.inline-live-avatar :size="27" />
                <strong x-text="display">{{ $assigneeName }}</strong>
                <svg class="ft-shipment-inline-edit-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="m4 14.5-.5 2 2-.5L14 7.5 12.5 6 4 14.5Z"/><path d="m11.5 7 1.5-1.5a1.1 1.1 0 0 1 1.6 0l.4.4a1.1 1.1 0 0 1 0 1.6L13.5 9"/></svg>
            </button>
            <div x-cloak x-show="editing" class="ft-shipment-inline-assignee-picker">
                <x-ui.inline-remote-user
                    :value="$task->assignee_id ?? ''"
                    :selected-label="$assigneeName"
                    parent-type="job"
                    :parent-id="$job->id"
                    variant="compact"
                    :menu-width="300"
                    external-trigger
                    :instance-key="'shipment-task-'.$task->id.'-assignee'"
                />
            </div>
            <x-ui.inline-save-state compact />
        @else
            <div class="ft-shipment-inline-assignee ft-shipment-inline-assignee--static">
                <x-ui.inline-live-avatar :size="27" />
                <strong x-text="display">{{ $assigneeName }}</strong>
            </div>
        @endif
    </div>

    <div
        class="ft-shipment-meta-block ft-shipment-meta-block--due ft-inline-edit-shell"
        x-data="window.FlowTrack.ui.inlineEdit({ key:@js('shipment-task-'.$task->id.'-due-date'), label:'task due date', value:@js($dueValue), display:@js($dueLabel) })"
        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
    >
        <span>DUE DATE</span>

        <div class="ft-shipment-inline-due" x-show="!editing">
            <strong x-text="display">{{ $dueLabel }}</strong>
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 3v3M15 3v3M3.5 8h13M4.5 5h11a1 1 0 0 1 1 1v10h-13V6a1 1 0 0 1 1-1Z"/></svg>
            @if($canEdit)
                <button
                    :disabled="status === 'saving'"
                    type="button"
                    class="ft-shipment-inline-edit-button"
                    title="Edit due date"
                    aria-label="Edit task due date"
                    x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.shipmentDue.showPicker ? $refs.shipmentDue.showPicker() : $refs.shipmentDue.focus())"
                >
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m4 14.5-.5 2 2-.5L14 7.5 12.5 6 4 14.5Z"/><path d="m11.5 7 1.5-1.5a1.1 1.1 0 0 1 1.6 0l.4.4a1.1 1.1 0 0 1 0 1.6L13.5 9"/></svg>
                </button>
            @endif
        </div>

        @if($canEdit)
            <input
                x-ref="shipmentDue"
                x-cloak
                x-show="editing"
                x-model="draftValue"
                class="ft-shipment-inline-date-input"
                type="date"
                x-on:keydown.escape.prevent="cancelEdit()"
                x-on:blur="if (editing) cancelEdit()"
                x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateTaskDueDateFromJob({{ $task->id }}, draftValue))"
            >
            <x-ui.inline-save-state compact />
        @endif
    </div>
</div>
