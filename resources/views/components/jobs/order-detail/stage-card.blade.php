@props([
    'job',
    'phase',
    'selected' => false,
    'context' => [],
])
@php
    $state = \App\Support\OrderDetailPresenter::phaseState($job, $phase);
    $progress = \App\Support\OrderDetailPresenter::phaseProgress($job, $phase);
    $phaseTasks = \App\Support\OrderDetailPresenter::phaseTasks($job, $phase)
        ->sortBy(fn ($task) => [(int) ($task->setupTemplate?->sort_order ?? $task->template?->sequence ?? 999999), (int) $task->id])
        ->values();
    $ownerTask = $phaseTasks->first();
    $ownerName = $ownerTask?->assignee?->name ?: \App\Support\OrderDetailPresenter::phaseOwnerName($job, $phase);
    $ownerInitials = \App\Support\OrderDetailPresenter::initials($ownerName);
    $canOpen = in_array($state, ['active', 'completed', 'cancelled'], true);
    $color = \App\Support\MasterColor::normalize((string) ($phase->color ?? '')) ?: '#94a3b8';
    $ownerPermissions = $ownerTask ? data_get($context, 'taskPermissions.'.(int) $ownerTask->id, []) : [];
    $canAssignOwner = $ownerTask && (bool) data_get($ownerPermissions, 'assign', false)
        && strcasecmp((string) $job->status, 'Cancelled') !== 0;
@endphp
<div
    class="stage {{ $state }} {{ $selected ? 'viewing' : '' }}"
    style="--stage:{{ $color }}"
    @if($canOpen)
        wire:click="selectOverviewPhase({{ (int) $phase->id }})"
        role="button"
        tabindex="0"
        x-on:keydown.enter.prevent="$wire.selectOverviewPhase({{ (int) $phase->id }})"
        x-on:keydown.space.prevent="$wire.selectOverviewPhase({{ (int) $phase->id }})"
    @endif
    title="{{ $canOpen ? 'Open '.$phase->name.' tasks.' : \App\Support\OrderDetailPresenter::phaseDependencyLabel($job, $phase) }}"
    wire:key="order-workflow-stage-{{ $phase->id }}"
>
    @if($state === 'completed')<span class="stage-check">✓</span>@endif
    <div class="stage-num">STAGE {{ (int) $phase->sequence }}</div>
    <div class="stage-name">{{ $phase->name }}</div>
    <div class="stage-state">{{ \App\Support\OrderDetailPresenter::phaseStateLabel($job, $phase) }}</div>
    <div class="progress"><i style="width:{{ $progress }}%"></i></div>

    @if($ownerTask)
        <div
            class="stage-owner ft-order-stage-owner-inline ft-inline-edit-shell"
            x-data="window.FlowTrack.ui.inlineEdit({ key:@js('stage-'.$phase->id.'-owner'), label:'stage assignee', value:@js($ownerTask->assignee_id ?? ''), display:@js($ownerName), avatarUrl:@js($ownerTask->assignee?->profileImageUrl() ?? '') })"
            :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
            x-on:click.stop
            x-on:click.outside="if(editing) cancelEdit()"
            x-on:ft-inline-remote-cancel.stop="cancelEdit()"
            x-on:task-assignee-updated.window="if (Number($event.detail?.taskId) === Number({{ (int) $ownerTask->id }})) syncConfirmed(String($event.detail?.assigneeId ?? ''), String($event.detail?.assigneeName ?? 'Unassigned'), { avatarUrl:String($event.detail?.avatarUrl ?? '') })"
            x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateTaskAssigneeFromJob({{ (int) $ownerTask->id }}, draftValue), { avatarUrl:String($event.detail?.avatarUrl ?? '') })"
        >
            <div class="ft-order-inline-display-row">
                @if($canAssignOwner)
                    <button
                        x-ref="stageAssigneeAnchor"
                        :disabled="status === 'saving'"
                        type="button"
                        class="ft-order-assignee-display ft-order-stage-assignee-display ft-order-inline-name-trigger"
                        :class="{ 'is-open': editing }"
                        title="Edit stage assignee"
                        aria-label="Edit {{ $phase->name }} stage assignee"
                        x-on:click.stop="openRemotePicker($refs.stageAssigneeAnchor)"
                    >
                        <span class="ft-inline-avatar-slot"><x-ui.inline-live-avatar :size="21" /></span>
                        <span class="ft-order-assignee-name" x-text="display">{{ $ownerName }}</span>
                        <span class="ft-order-inline-trigger-icon" aria-hidden="true">✎</span>
                    </button>
                @else
                    <span class="ft-order-assignee-display ft-order-stage-assignee-display">
                        <span class="ft-inline-avatar-slot"><x-ui.inline-live-avatar :size="21" /></span>
                        <span class="ft-order-assignee-name" x-text="display">{{ $ownerName }}</span>
                    </span>
                @endif
            </div>
            @if($canAssignOwner)
                <span x-cloak x-show="editing" class="ft-order-stage-assignee-picker">
                    <x-ui.inline-remote-user
                        :value="$ownerTask->assignee_id ?? ''"
                        :selected-label="$ownerName"
                        parent-type="job"
                        :parent-id="$job->id"
                        variant="compact"
                        :menu-width="300"
                        external-trigger
                    />
                </span>
                <x-ui.inline-save-state compact />
            @endif
        </div>
    @else
        <div class="stage-owner"><i class="mini-avatar">{{ $ownerInitials }}</i><span>{{ $ownerName }}</span></div>
    @endif

    <div class="stage-dependency">{{ \App\Support\OrderDetailPresenter::phaseDependencyLabel($job, $phase) }}</div>
</div>
