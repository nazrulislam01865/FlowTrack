@foreach($inquiryGroups as $group)
    <article class="order-group" wire:key="my-work-inquiry-{{ $group['id'] }}" x-data="{ open: true }">
        <header class="order-head">
            <button type="button" class="collapse" x-on:click="open = !open" x-bind:aria-expanded="open.toString()" aria-label="Collapse {{ $group['number'] }}"><span x-text="open ? '⌄' : '›'">⌄</span></button>
            <span class="order-identity">
                @if($group['route'])<a class="order-id" href="{{ $group['route'] }}" wire:navigate>{{ $group['number'] }}</a>@else<span class="order-id">{{ $group['number'] }}</span>@endif
                <span class="order-title">{{ $group['title'] }}</span>
            </span>
            <span class="order-client">{{ $group['client'] }}</span>
            <span class="order-stage">{{ $group['stage'] }}</span>
            <span class="health {{ $group['healthColor'] ? 'ft-master-color' : $group['healthTone'] }}" style="{{ \App\Support\MasterColor::style($group['healthColor'] ?? null) }}">{{ $group['health'] }}</span>
            <span class="order-progress"><i class="progress-track"><i style="width:{{ $group['progress'] }}%"></i></i>{{ $group['progress'] }}%</span>
            <span class="task-count">{{ $group['taskCount'] }} {{ $group['taskCount'] === 1 ? 'task' : 'tasks' }}</span>
        </header>

        <div class="task-rows" x-show="open">
            @foreach($group['tasks'] as $task)
                <div
                    class="task-row"
                    wire:key="my-work-inquiry-task-{{ $task['id'] }}"
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
                                const result=await $wire.updateInquiryTaskStatus({{ $task['id'] }},next,this.version);
                                if(!result?.ok){select.value=previous;window.FlowTrack.ui.masterColor?.applySelect(select);return;}
                                this.currentStatus=result.status||next;
                                this.version=result.version||this.version;
                                if(result.refresh)await $wire.$refresh();
                            }catch(error){select.value=previous;window.FlowTrack.ui.masterColor?.applySelect(select);}
                            finally{this.saving=false;select.disabled=false;}
                        }
                    }"
                    x-bind:class="{ 'saving': saving }"
                >
                    <div class="task-main">
                        <a class="task-link" href="{{ $task['route'] }}" wire:navigate>{{ $task['title'] }}</a>
                        <span class="task-ref">{{ $task['number'] }}</span>
                    </div>
                    <span class="phase">{{ $task['phase'] }}</span>
                    <span class="assignee" title="{{ $task['assignee'] }}">
                        <x-ui.avatar :name="$task['assignee']" :src="$task['assigneeAvatar']" :size="22" />
                        <span class="assignee-name">{{ $task['assignee'] }}</span>
                    </span>
                    <span
                        class="due-editor ft-inline-edit-shell {{ $task['dueTone'] }}"
                        x-data="window.FlowTrack.ui.inlineEdit({ key: @js('my-work-inquiry-task-'.$task['id'].'-due-date'), label: 'inquiry task due date', value: @js($task['dueValue']), display: @js($task['dueDisplay']) })"
                        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    >
                        <span x-show="!editing" x-text="display" class="ft-task-inline-display">{{ $task['dueDisplay'] }}</span>
                        @if($task['canEdit'])
                            <button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-inline-edit-button compact" title="Edit due date" aria-label="Edit due date for {{ $task['title'] }}" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.myInquiryDue.showPicker ? $refs.myInquiryDue.showPicker() : $refs.myInquiryDue.focus())">✎</button>
                            <input x-ref="myInquiryDue" x-cloak x-show="editing" x-model="draftValue" class="ft-task-inline-input" type="date"
                                x-on:keydown.escape.prevent="cancelEdit()"
                                x-on:blur="if (editing) cancelEdit()"
                                x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateInquiryTaskDueDate({{ $task['id'] }}, draftValue))">
                            <x-ui.inline-save-state compact />
                        @endif
                    </span>
                    <select data-master-color-select class="status-select {{ $task['statusColor'] ? 'ft-master-color' : '' }}" style="{{ \App\Support\MasterColor::style($task['statusColor']) }}" @if($task['canEdit']) x-on:change="saveStatus($event); window.FlowTrack.ui.masterColor?.applySelect($event.currentTarget)" @else disabled @endif aria-label="Status for {{ $task['title'] }}">
                        @php $inquiryTaskStatusOptions = app(\App\Services\InquiryService::class)->openTaskStatusOptions((string) $task['status']); @endphp
                        @foreach($inquiryTaskStatusOptions as $statusOption)<option value="{{ $statusOption }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('inquiry_task_status', $statusOption) }}" @selected($statusOption === $task['status'])>{{ $statusOption }}</option>@endforeach
                    </select>
                    <span class="flag {{ $task['flagTone'] }}">{{ $task['flag'] }}</span>
                    <span class="updated">{{ $task['updated'] }}</span>
                    <a class="row-action" href="{{ $task['route'] }}" wire:navigate>Open</a>
                </div>
            @endforeach
        </div>
    </article>
@endforeach
