<section class="panel ft-inquiry-taskflow-panel">
    <header class="panelhead"><div><h2>Inquiry Taskflow</h2><p>Task status can be changed at any time, including reopening a completed task.</p></div><div class="task-control-row"><span class="task-count-pill">{{ $totalTasks }} Tasks</span><span class="manage-badge">Taskflow</span>@if($canAddInquiryTask)<button class="primary ft-inquiry-taskflow-add" type="button" wire:click="openAddTaskForm">＋ Add Task</button>@endif</div></header>
    <div class="ft-inquiry-task-grid-head" aria-hidden="true">
        <span>#</span><span>Task</span><span>Assignee</span><span>Due date</span><span>Status</span><span>Files</span><span>Action</span>
    </div>
    <div class="ft-inquiry-task-list">
        @forelse($inquiry->tasks as $i => $task)
            @php
                $state = $task->completed_at ? 'done' : ($task->started_at ? 'active' : 'wait');
                $taskLinkCount = $task->relationLoaded('links') ? $task->links->count() : 0;
                $taskSubmissionCount = (int) $task->documents_count + (int) $taskLinkCount;
                $submissionOk = !$task->requires_submission || $taskSubmissionCount > 0;
                $submissionDoneLabel = (int) $task->documents_count > 0 ? '✓ File submitted' : '✓ Link submitted';
                $completionNeedsRequiredSubmission = (bool) $task->requires_submission && !$submissionOk;
                $taskAccess = app(\App\Services\AccessControlService::class);
                $canChangeStatusThisTask = !$inquiry->result && (bool) ($inquiryTaskUi[(int) $task->id]['canEdit'] ?? false);
                // Editing a task and assigning a task are independent matrix permissions.
                $canEditTaskFields = $canChangeStatusThisTask;
                $canAssignThisTask = !$inquiry->result && $taskAccess->canAssignInquiryTask(auth()->user(), $task);
                $taskDocumentLimitReached = (int) $task->documents_count >= \App\Models\InquiryTask::MAX_DOCUMENTS;
                $canAttachFileThisTask = !$inquiry->result && $canChangeStatusThisTask && $canCreateDocuments && !$taskDocumentLimitReached;
                $canDeleteTaskDocuments = !$inquiry->result && $canChangeStatusThisTask && $canDeleteDocuments;
                $canAttachThisTask = $canAttachFileThisTask; // legacy alias used by the modal/resource block.
                $canEditThisTask = $state !== 'done' && $canChangeStatusThisTask;
                $taskDeepLinked = (int)($selectedTaskId ?? 0) === (int)$task->id;
                $canCompleteThisTask = !$task->completed_at && $task->started_at !== null;
                $configuredTaskColor = \App\Support\MasterColor::normalize((string) ($task->sourceTaskPackItem?->color ?? '')) ?: '#2563EB';
            @endphp
            <div class="ft-inquiry-task-row {{ $state }} {{ $taskDeepLinked ? 'is-highlighted' : '' }}" style="{{ \App\Support\MasterColor::style($configuredTaskColor) }}border-left:4px solid var(--ft-master-color,#2563EB)" wire:key="inquiry-task-row-{{ $task->id }}">
                <div class="ft-inquiry-task-step"><span>{{ $state === 'done' ? '✓' : $i + 1 }}</span></div>
                <div class="ft-inquiry-task-copy">
                    <strong>{{ $task->title }}</strong>
                    <div class="ft-rich-text-content ft-inquiry-task-description">@if($task->description)<x-ui.mention-text :text="$task->description" />@else No instructions added. @endif</div>
                    @if($task->requires_submission)<span class="reqfile {{ $submissionOk ? 'ok' : '' }}">{{ $submissionOk ? $submissionDoneLabel : '□ Required file or link' }}</span>@endif
                </div>

                <div class="ft-inquiry-assignee-inline ft-inline-edit-shell"
                    wire:key="inquiry-task-assignee-{{ $task->id }}-{{ (int) ($task->assignee_id ?? 0) }}"
                    x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-task-'.$task->id.'-assignee'), label: 'task assignee', value: @js($task->assignee_id ?? ''), display: @js($task->assignee?->name ?? 'Unassigned'), avatarUrl: @js($task->assignee?->profileImageUrl() ?? '') })"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    x-on:click.outside="if (editing) cancelEdit()"
                    x-on:ft-inline-remote-cancel.stop="cancelEdit()"
                    x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateTaskAssigneeInline({{ $task->id }}, draftValue), { avatarUrl: String($event.detail?.avatarUrl ?? '') })">
                    <div class="ft-inquiry-inline-display-row">
                        <div x-show="!editing" class="ft-inquiry-assignee-display">
                            <span class="ft-inline-avatar-slot"><x-ui.inline-live-avatar :size="28" /></span>
                            <span class="ft-inquiry-assignee-name" x-text="display">{{ $task->assignee?->name ?? 'Unassigned' }}</span>
                        </div>
                        @if($canAssignThisTask)<button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-inline-edit-button" title="Edit assignee" aria-label="Edit task assignee" x-on:click.stop="openRemotePicker($event.currentTarget)">✎</button>@endif
                    </div>
                    @if($canAssignThisTask)
                        <div x-cloak x-show="editing" class="ft-inquiry-assignee-picker">
                            <x-ui.inline-remote-user :value="$task->assignee_id ?? ''" :selected-label="$task->assignee?->name ?? 'Unassigned'" parent-type="inquiry" :parent-id="$inquiry->id" trigger-class="ft-task-inline-input" variant="compact" :menu-width="260" />
                        </div>
                        <x-ui.inline-save-state compact />
                    @endif
                </div>

                <div class="ft-inquiry-task-date ft-inline-edit-shell"
                    x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-task-'.$task->id.'-due-date'), label: 'task due date', value: @js($task->due_date?->format('Y-m-d') ?? ''), display: @js($task->due_date?->format('M j, Y') ?? 'Set due date') })"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }">
                    <div class="ft-inquiry-inline-display-row" x-show="!editing">
                        <span class="ft-inquiry-inline-value" x-text="display">{{ $task->due_date?->format('M j, Y') ?? 'Set due date' }}</span>
                        @if($canEditTaskFields)<button :disabled="status === 'saving'" type="button" class="ft-inline-edit-button" title="Edit due date" aria-label="Edit task due date" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.inquiryDue.showPicker ? $refs.inquiryDue.showPicker() : $refs.inquiryDue.focus())">✎</button>@endif
                    </div>
                    @if($canEditTaskFields)
                        <input x-ref="inquiryDue" x-cloak x-show="editing" x-model="draftValue" class="ft-inquiry-inline-input" type="date"
                            x-on:keydown.escape.prevent="cancelEdit()"
                            x-on:blur="if (editing) cancelEdit()"
                            x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateTaskDueInline({{ $task->id }}, draftValue))">
                        <x-ui.inline-save-state compact />
                    @endif
                </div>
                <div class="ft-inquiry-task-status-resources">
                <div class="task-status-cell">
                    <span
                        class="ft-task-inline-status-shell ft-inline-edit-shell"
                        wire:key="inquiry-task-status-{{ $task->id }}-{{ md5((string) $task->status.'|'.($task->completed_at?->getTimestamp() ?? 'open')) }}"
                        x-data="window.FlowTrack.ui.inlineEdit({ key: @js('inquiry-task-'.$task->id.'-status'), label: 'task status', value: @js($task->status), display: @js($task->status) })"
                        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                    >
                        @php
                            $taskStatusColor = app(\App\Services\MasterDataService::class)->colorFor('inquiry_task_status', (string) $task->status);
                        @endphp
                        <select
                            data-master-color-select
                            class="ft-inline-task-status {{ $taskStatusColor ? 'ft-master-color' : \App\Support\JobDetailPresenter::taskStatusClass((string) $task->status) }}"
                            style="{{ \App\Support\MasterColor::style($taskStatusColor) }}"
                            x-model="draftValue"
                            x-on:change="const select=$event.target; const next=select.value; const needsRequiredSubmission=(select.selectedOptions?.[0]?.dataset?.completes === '1' && @js($completionNeedsRequiredSubmission)); if(needsRequiredSubmission){ draftValue=value; select.value=value; window.FlowTrack.ui.masterColor?.applySelect(select); $wire.requestTaskCompletionFile({{ $task->id }}); return; } window.FlowTrack.ui.masterColor?.applySelect(select); commit(next, selectedLabel($event), async () => { const result=await $wire.updateTaskStatusInline({{ $task->id }}, draftValue); if(result?.inquiryStatus) inquiryStatus=result.inquiryStatus; if(result?.inquiryColor) inquiryStatusColor=result.inquiryColor; if(result && Object.prototype.hasOwnProperty.call(result,'inquiryStartValue')){ inquiryStartValue=result.inquiryStartValue || ''; inquiryStartDisplay=result.inquiryStartDisplay || '—'; window.dispatchEvent(new CustomEvent('flowtrack-inquiry-started',{detail:{value:inquiryStartValue,display:inquiryStartDisplay}})); } return result; }).then(() => window.FlowTrack.ui.masterColor?.applyAll(document))"
                            :disabled="status === 'saving'"
                            @disabled(!$canChangeStatusThisTask)
                            aria-label="Change {{ $task->title }} status"
                        >
                            @if(!$inquiryTaskStatusOptions->contains(fn ($statusOption) => strcasecmp((string) $statusOption, (string) $task->status) === 0))
                                <option value="{{ $task->status }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('inquiry_task_status', (string) $task->status) }}" data-completes="{{ ($inquiryTaskStatusCompletion[(string) $task->status] ?? false) ? '1' : '0' }}" selected>{{ $task->status }}</option>
                            @endif
                            @foreach($inquiryTaskStatusOptions as $statusOption)
                                <option value="{{ $statusOption }}" data-color="{{ app(\App\Services\MasterDataService::class)->colorFor('inquiry_task_status', $statusOption) }}" data-completes="{{ ($inquiryTaskStatusCompletion[(string) $statusOption] ?? false) ? '1' : '0' }}">{{ $statusOption }}</option>
                            @endforeach
                        </select>
                        @if($canChangeStatusThisTask && ($task->needs_attention || ($inquiryTaskUi[(int) $task->id]['statusNeedsAttention'] ?? false)))
                            <button type="button" class="ft-inquiry-task-flag-icon" wire:click.stop="openTaskAttentionReason({{ $task->id }})" title="{{ $task->attention_reason ? 'View or update flag reason' : 'Add flag reason' }}" aria-label="Flag reason for {{ $task->title }}">
                                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 21V4"></path><path d="M7 5h10l-2 4 2 4H7"></path></svg>
                            </button>
                        @endif
                        @if($canChangeStatusThisTask)<x-ui.inline-save-state compact />@endif
                    </span>
                </div>
                <div class="ft-inquiry-task-files">
                    @if($canAttachFileThisTask || $canChangeStatusThisTask)
                        <div class="ft-inquiry-task-add-actions" aria-label="Add task resource">
                            @if($canAttachFileThisTask)
                                <button
                                    class="ft-inquiry-task-add-icon"
                                    type="button"
                                    wire:click="openTaskDocumentModal({{ $task->id }})"
                                    title="Add file"
                                    aria-label="Add file to {{ $task->title }}"
                                >
                                    <span class="ft-inquiry-task-add-plus">+</span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h6"/></svg>
                                </button>
                            @endif
                            @if($canChangeStatusThisTask)
                                <button
                                    class="ft-inquiry-task-add-icon {{ (int)$taskLinkFormTaskId === (int)$task->id ? 'is-active' : '' }}"
                                    type="button"
                                    wire:click="openTaskLinkForm({{ $task->id }})"
                                    title="Add link"
                                    aria-label="Add external link to {{ $task->title }}"
                                >
                                    <span class="ft-inquiry-task-add-plus">+</span>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                </button>
                            @endif
                        </div>
                    @endif
                    <span class="ft-inquiry-task-resource-count"><b>{{ $task->documents_count }}</b> file{{ $task->documents_count === 1 ? '' : 's' }}@if($taskLinkCount > 0) · <b>{{ $taskLinkCount }}</b> link{{ $taskLinkCount === 1 ? '' : 's' }}@endif</span>
                </div>
                </div>
                <div class="ft-inquiry-task-action">
                    @if($state === 'done')
                        <div class="ft-inquiry-complete-block">
                            <span class="ft-inquiry-complete-state">✓ Completed</span>
                            @if($task->completed_at)
                                <span class="ft-inquiry-completed-at">
                                    <span>{{ \App\Support\UserLocalTime::format($task->completed_at, 'M j, Y') }}</span>
                                    <span>{{ \App\Support\UserLocalTime::format($task->completed_at, 'g:i A') }}</span>
                                </span>
                            @endif
                        </div>
                    @elseif($canCompleteThisTask)
                        <button class="ft-inquiry-action-button primary-action" type="button" wire:click="completeTaskInline({{ $task->id }})" wire:loading.attr="disabled" wire:target="completeTaskInline({{ $task->id }})" @disabled(!$canEditThisTask || !$submissionOk)>{{ !$submissionOk ? 'File/link required' : 'Complete' }}</button>
                    @else
                        <button class="ft-inquiry-action-button" type="button" disabled>{{ $task->status ?: $inquiryDefaultTaskStatus }}</button>
                    @endif
                </div>
            </div>

            @if((int)$taskLinkFormTaskId === (int)$task->id || $task->documents->isNotEmpty() || $task->links->isNotEmpty())
                <div class="ft-inquiry-task-document-list ft-inquiry-task-resource-list" wire:key="inquiry-task-resources-{{ $task->id }}-{{ (int) $task->documents_count }}-{{ $taskLinkCount }}">
                    @if((int)$taskLinkFormTaskId === (int)$task->id && $canChangeStatusThisTask)
                        <form class="ft-inquiry-task-link-form" wire:submit.prevent="saveTaskLink({{ $task->id }})" wire:key="inquiry-task-link-form-{{ $task->id }}">
                            <div class="ft-inquiry-task-link-input-wrap">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                <input
                                    type="text"
                                    inputmode="url"
                                    wire:model="taskLinkUrl"
                                    placeholder="Paste link, e.g. https://drive.google.com/..."
                                    autocomplete="url"
                                    autofocus
                                    aria-label="External link"
                                >
                            </div>
                            <div class="ft-inquiry-task-link-form-actions">
                                <button class="secondary" type="button" wire:click="cancelTaskLinkForm">Cancel</button>
                                <button class="primary" type="submit" wire:loading.attr="disabled" wire:target="saveTaskLink({{ $task->id }})">Add</button>
                            </div>
                            @error('taskLinkUrl')<div class="ft-inquiry-task-link-error">{{ $message }}</div>@enderror
                        </form>
                    @endif

                    @foreach($task->documents as $taskDocument)
                        <div class="ft-inquiry-task-document-row" wire:key="inquiry-task-document-{{ $taskDocument->id }}">
                            <span class="ft-inquiry-task-file-type">{{ strtoupper(pathinfo($taskDocument->name, PATHINFO_EXTENSION) ?: 'FILE') }}</span>
                            <div class="ft-inquiry-task-file-copy">
                                <b title="{{ $taskDocument->name }}">{{ $taskDocument->name }}</b>
                                @if($taskDocument->note)<span class="ft-inquiry-task-file-note">{{ $taskDocument->note }}</span>@endif
                                <small>{{ $taskDocument->created_at ? \App\Support\UserLocalTime::format($taskDocument->created_at, 'M j, Y, g:i A') : '—' }}</small>
                            </div>
                            <div class="ft-inquiry-task-file-actions">
                                <a href="{{ route('inquiries.documents.open', $taskDocument) }}" target="_blank" rel="noopener">Open</a>
                                @if($canExportDocuments)<a href="{{ route('inquiries.documents.download', $taskDocument) }}">Download</a>@endif
                                @if($canDeleteTaskDocuments)
                                    <button
                                        type="button"
                                        class="ft-inquiry-task-file-remove"
                                        wire:click="deleteTaskDocument({{ $task->id }}, {{ $taskDocument->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteTaskDocument({{ $task->id }}, {{ $taskDocument->id }})"
                                        wire:confirm="{{ $task->completed_at && $task->requires_submission && $taskSubmissionCount === 1 ? 'Remove the final required file/link evidence? The task will reopen to In Progress.' : 'Remove this attachment from the task?' }}"
                                        title="Remove attachment"
                                        aria-label="Remove {{ $taskDocument->name }}"
                                    >×</button>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @foreach($task->links as $taskLink)
                        <div class="ft-inquiry-task-link-row" wire:key="inquiry-task-link-{{ $taskLink->id }}">
                            <span class="ft-inquiry-task-link-type" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                            </span>
                            <div class="ft-inquiry-task-link-copy">
                                <a href="{{ $taskLink->url }}" target="_blank" rel="noopener noreferrer" title="{{ $taskLink->url }}">{{ \Illuminate\Support\Str::limit($taskLink->url, 110) }}</a>
                                <small>{{ $taskLink->created_at ? \App\Support\UserLocalTime::format($taskLink->created_at, 'M j, Y, g:i A') : '—' }}</small>
                            </div>
                            <div class="ft-inquiry-task-link-actions">
                                <a href="{{ $taskLink->url }}" target="_blank" rel="noopener noreferrer" title="Open external link">Open ↗</a>
                                @if($canChangeStatusThisTask)
                                    <button
                                        type="button"
                                        class="ft-inquiry-task-file-remove"
                                        wire:click="deleteTaskLink({{ $task->id }}, {{ $taskLink->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="deleteTaskLink({{ $task->id }}, {{ $taskLink->id }})"
                                        wire:confirm="{{ $task->completed_at && $task->requires_submission && $taskSubmissionCount === 1 ? 'Remove the final required file/link evidence? The task will reopen to In Progress.' : 'Remove this link from the task?' }}"
                                        title="Remove link"
                                        aria-label="Remove link"
                                    >×</button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @empty
            <div class="ft-inquiry-empty-workflow">No taskflow tasks configured.</div>
        @endforelse
    </div>

    @if($showAddTaskForm && $canAddInquiryTask)
        <div class="ft-inquiry-add-task" wire:key="inquiry-add-task-form">
            <div class="ft-inquiry-add-task-head">
                <div><strong>Add taskflow task</strong><span>The task is appended after the existing taskflow. If the taskflow was already complete, this new task becomes active.</span></div>
                <button class="ft-inquiry-add-task-close" type="button" wire:click="cancelAddTask" aria-label="Close add task form">×</button>
            </div>
            <div class="ft-inquiry-add-task-grid">
                <label class="ft-inquiry-add-task-field ft-inquiry-add-task-field-wide"><span>Task name *</span><input type="text" wire:model="newTaskName" placeholder="Task name"></label>
                <div
                    class="ft-inquiry-add-task-field ft-inquiry-add-task-assignee"
                    x-data
                    x-on:ft-inline-remote-selected.stop="$wire.setInquiryAddTaskSelector('newTaskAssigneeId', String($event.detail?.value ?? ''))"
                >
                    <span>Assignee</span>
                    <x-ui.inline-remote-user
                        :value="$newTaskAssigneeId ?? ''"
                        :selected-label="$newTaskAssigneeLabel"
                        context="task-assignee"
                        parent-type="inquiry"
                        :parent-id="$inquiry->id"
                        search-placeholder="Search assignee…"
                        trigger-class="ft-task-inline-input"
                        variant="compact"
                        :menu-width="260"
                        :fixed-menu="true"
                        wire:key="inquiry-add-task-assignee-{{ $newTaskAssigneeId ?: 'none' }}"
                    />
                    @error('newTaskAssigneeId')<small class="ft-inquiry-add-task-error">{{ $message }}</small>@enderror
                </div>
                <label class="ft-inquiry-add-task-field"><span>Due date</span><input type="date" wire:model="newTaskDueDate" onclick="this.showPicker && this.showPicker()"></label>
                <label class="ft-inquiry-add-task-field ft-inquiry-add-task-field-wide"><span>Instructions</span><textarea data-rich-text wire:model="newTaskDescription" placeholder="Describe what must be completed for this task or paste screenshots here."></textarea></label>
                <label class="ft-inquiry-add-task-field"><span>Submission</span><select wire:model.live.boolean="newTaskRequiresSubmission"><option value="0">No required submission</option><option value="1">Required file or link</option></select></label>
                @if($newTaskRequiresSubmission)<label class="ft-inquiry-add-task-field"><span>Required submission</span><input type="text" wire:model="newTaskSubmissionLabel" placeholder="Submission name"></label>@endif
            </div>
            @error('newTaskName')<div class="ft-inquiry-add-task-error">{{ $message }}</div>@enderror
            <div class="ft-inquiry-add-task-actions"><button class="secondary" type="button" wire:click="cancelAddTask">Cancel</button><button class="primary" type="button" wire:click="addInquiryTask" wire:loading.attr="disabled" wire:target="addInquiryTask">Add Task</button></div>
        </div>
    @endif
</section>
