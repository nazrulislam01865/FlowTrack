@props([
    'task',
    'mentionUsers'=>collect(),
    'taskProgress',
    'taskStatuses'=>collect(),
    'priorities'=>collect(),
    'taskFlags'=>collect(),
    'displayTimezone'=>'UTC',
    'availableDocuments'=>collect(),
    'activityTab'=>'all',
    'activityPage'=>1,
    'focusComment'=>null,
    'taskDocumentUploads'=>[],
    'showTaskDocumentPicker'=>false,
    'editMode'=>false,
    'taskDetailSectionsReady'=>[],
    'orderHoldContext'=>[],
])
@php
    $job = $task->job;
    $taskOrderIsOnHold = (bool) ($orderHoldContext['isOnHold'] ?? false);
    $taskOrderHold = is_array($orderHoldContext['hold'] ?? null) ? $orderHoldContext['hold'] : null;
    $checklistReady = (bool) ($taskDetailSectionsReady['checklist'] ?? false);
    $attachmentsReady = (bool) ($taskDetailSectionsReady['attachments'] ?? false);
    $activityReady = (bool) ($taskDetailSectionsReady['activity'] ?? false);
    $done = $checklistReady && $task->relationLoaded('checklistItems') ? $task->checklistItems->where('is_completed',true)->count() : 0;
    $total = $checklistReady && $task->relationLoaded('checklistItems') ? $task->checklistItems->count() : 0;
    $checkTotal = max(1, $total);
    $taskDocumentName = $task->documentCategory?->name ?: $task->setupTemplate?->documentCategory?->name;
    $taskDocumentInstructions = trim((string) ($task->setupTemplate?->document_instructions ?? ''));
    $accessControl = app(\App\Services\AccessControlService::class);
    $mayModerateTaskActivity = $accessControl->isAdministrator(auth()->user());
    $mayEditTask = $accessControl->canEditVisibleTask(auth()->user(), $task);
    // A held Order makes Task Details strictly view-only for every role,
    // including administrators. Release hold is intentionally available only
    // from the parent Order so task work cannot bypass the Order-level lock.
    $canModerateTaskActivity = $mayModerateTaskActivity && ! $taskOrderIsOnHold;
    $canEditTask = $mayEditTask && ! $taskOrderIsOnHold;
    $canAssignTask = $accessControl->canAssignTask(auth()->user(), $task) && ! $taskOrderIsOnHold;
    $canCheck = $canEditTask;
    // Task Details permissions must be driven by the actual task authorization,
    // not by how the page was opened. A normal View action can still be an
    // editable task for its assignee/creator/admin, so attachment upload must
    // remain available in that case.
    $canUploadDocument = $canEditTask && $accessControl->can(auth()->user(), 'documents', 'create');
    $canLinkDocument = $canEditTask && $accessControl->can(auth()->user(), 'documents', 'link');
    $canManageDocuments = $canUploadDocument || $canLinkDocument;
    $canDeleteDocument = $canEditTask && $accessControl->can(auth()->user(), 'documents', 'delete');
    $effectiveDescription = $task->description ?: $task->setupTemplate?->description;
    $effectiveStartDate = $task->start_date ?: \App\Support\UserLocalTime::localize($task->created_at);
    $completedOn = $task->completed_at?->copy()->timezone($displayTimezone);
    $masterData = app(\App\Services\MasterDataService::class);
    $currentStatusColor = $masterData->colorFor('order_task_status', (string) $task->status);
    $currentPriorityColor = $masterData->displayColorFor('priority', (string) $task->priority);
    $currentTaskFlag = app(\App\Services\OrderTaskFlagService::class)->labelForTask($task) ?: '';
    $currentTaskFlagColor = $currentTaskFlag !== '' ? $masterData->colorFor('order_task_flag', $currentTaskFlag) : null;
    $currentOrderFlag = $job ? (app(\App\Services\OrderTaskFlagService::class)->labelForOrder($job) ?: '') : '';
    $currentOrderFlagColor = $currentOrderFlag !== '' ? $masterData->colorFor('order_flag', $currentOrderFlag) : null;
    $isProductionMonitorTask = app(\App\Services\OrderWorkflowActionService::class)->automationKey($task) === 'PROD_ISSUE';
    $productionMonitorActivity = $job && $job->relationLoaded('latestProductionMonitorActivity')
        ? $job->latestProductionMonitorActivity
        : null;
    $productionMonitorMeta = is_array($productionMonitorActivity?->meta) ? $productionMonitorActivity->meta : [];
    $productionMonitorActivityTaskId = (int) ($productionMonitorMeta['task_id'] ?? 0);
    $productionMonitorActivityMatchesTask = $productionMonitorActivity
        && ($productionMonitorActivityTaskId === 0 || $productionMonitorActivityTaskId === (int) $task->id);
    $productionMonitorDate = $productionMonitorActivityMatchesTask
        ? trim((string) ($productionMonitorMeta['supplier_delivery_date'] ?? ''))
        : '';
    if ($productionMonitorDate === '' && $job?->supplier_delivery_date) {
        $productionMonitorDate = $job->supplier_delivery_date->format('Y-m-d');
    }
    $productionMonitorNote = $productionMonitorActivityMatchesTask
        ? trim(app(\App\Services\RichTextService::class)->plainText((string) ($productionMonitorMeta['production_issue_note'] ?? '')))
        : '';
    $timeline = collect();
    $activityPerPage = 30;
    $timelineTotal = 0;
    $timelinePages = 1;
    $timelineCurrentPage = 1;
    if ($activityReady && $task->relationLoaded('comments') && $task->relationLoaded('activities')) {
        $commentEvents = $task->comments->map(fn($comment)=>(object)[
            'id'=>(int)$comment->id,'kind'=>'comment','event'=>'task.comment','user'=>$comment->user,'body'=>$comment->body,'created_at'=>$comment->created_at,
        ]);
        $activityEvents = $task->activities->reject(fn($activity)=>$activity->event==='task.comment')->map(fn($activity)=>(object)[
            'id'=>(int)$activity->id,'kind'=>'activity','event'=>$activity->event,'user'=>$activity->user,'body'=>$activity->description,'created_at'=>$activity->created_at,
        ]);
        $timeline = $commentEvents->concat($activityEvents)->sortByDesc(fn($entry) => sprintf('%020d-%020d', $entry->created_at?->getTimestamp() ?? 0, $entry->id ?? 0))->values();
        if($activityTab==='comments') $timeline = $timeline->where('kind','comment')->values();
        if($activityTab==='history') $timeline = $timeline->where('kind','activity')->values();
        $timelineTotal = $timeline->count();
        $timelinePages = max(1, (int) ceil($timelineTotal / $activityPerPage));
        $timelineCurrentPage = min(max(1, (int) $activityPage), $timelinePages);
        $timeline = $timeline->forPage($timelineCurrentPage, $activityPerPage)->values();
    }
@endphp
<div
    {{ $attributes->class('ft-task-detail-page ft-exact-task-detail') }}
    x-data="window.FlowTrack.ui.orderHoldGuard({ held: @js($taskOrderIsOnHold) })"
    x-on:flowtrack:order-held-blocked.window="showHoldBlocked($event.detail?.action ?? '')"
    x-on:click.capture="guardInteraction($event)"
    x-on:focusin.capture="guardInteraction($event)"
    x-on:change.capture="guardInteraction($event)"
    x-on:submit.capture="guardInteraction($event)"
>
    @if(session('success'))<div class="flash">{{ session('success') }}</div>@endif
    <div class="ft-detail-toolbar task-toolbar ft-exact-task-header">
        <div class="ft-task-heading-copy">
            <div class="ft-detail-breadcrumb ft-id-breadcrumb">
                <a href="{{ route('my-work') }}" wire:navigate>My Tasks</a>
                @if($job)
                    <span>/</span><a href="{{ route('jobs.index', ['open'=>$job->id]) }}" wire:navigate>{{ $job->displayOrderNumber() }}</a>
                @endif
                <span>/</span><span>{{ $task->task_number }}</span>
            </div>
            <div class="ft-task-title-line">
                <h1
                    class="ft-editable-task-title ft-inline-edit-shell"
                    x-data="window.FlowTrack.ui.inlineEdit({ key: @js('task-'.$task->id.'-title'), label: 'task title', value: @js($task->title), display: @js($task->title) })"
                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                >
                    <span x-show="!editing" x-text="display">{{ $task->title }}</span>
                    @if($canEditTask)
                        <button x-show="!editing" :disabled="status === 'saving'" type="button" class="ft-pencil ft-detail-edit-button" aria-label="Edit task title" title="Edit task name" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.taskTitle.focus())"><x-ui.detail-icon name="edit" /></button>
                        <input x-ref="taskTitle" x-cloak x-show="editing" x-model="draftValue" type="text" maxlength="255"
                            x-on:keydown.escape.prevent="cancelEdit()"
                            x-on:keydown.enter.prevent="$event.target.blur()"
                            x-on:blur="if (editing) commit(draftValue.trim(), draftValue.trim(), () => $wire.updateSelectedTaskField('title', draftValue.trim()))">
                        <x-ui.inline-save-state />
                    @endif
                </h1>
                @if($task->phase?->name)<span class="ft-task-title-phase">· <x-ui.phase-label :phase="$task->phase" /></span>@endif
            </div>
        </div>
        <div class="ft-detail-actions">
            @if($taskOrderIsOnHold && $mayEditTask && $task->status !== 'Completed')
                <button type="button" class="ft-new-job-btn ft-mark-complete ft-task-held-action" x-on:click.prevent.stop="showHoldBlocked('Mark complete')" title="Order is on hold. Release the hold before completing this task.">
                    <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                    <span>Mark complete</span>
                </button>
            @elseif($canEditTask)
                <button class="ft-new-job-btn ft-mark-complete" wire:click="markTaskComplete" @disabled($task->status==='Completed')>{{ $task->status==='Completed' ? 'Completed' : 'Mark complete' }}</button>
            @endif
            <button class="ft-close-page" wire:click="closeTask" type="button" title="Back to order details" aria-label="Back to order details">×</button>
        </div>
    </div>
    @error('taskCompletion')<div class="validation-error ft-task-completion-error">{{ $message }}</div>@enderror

    @if($taskOrderIsOnHold && $taskOrderHold)
        <div class="ft-task-order-hold-banner" data-order-hold-view-control>
            <div class="ft-task-order-hold-banner__icon" aria-hidden="true"><span class="ft-order-hold-pause-icon"><i></i><i></i></span></div>
            <div class="ft-task-order-hold-banner__copy">
                <strong>Order on hold — task activities are locked</strong>
                <span>{{ $taskOrderHold['holdFromLabel'] ?? 'Order' }} hold · held by {{ $taskOrderHold['heldBy'] ?? 'Unknown user' }}</span>
            </div>
            @if($job)
                <button type="button" wire:click="openJob({{ $job->id }})" data-order-hold-allowed>View order</button>
            @endif
        </div>
    @endif

    <div class="ft-task-detail-layout">
        <main>
            @include('components.jobs.task-detail.properties')

            @include('components.jobs.task-detail.description')

            @if($isProductionMonitorTask && \App\Support\OrderDetailPresenter::isCompletedTask($task))
                <x-jobs.order-detail.production-monitor-summary
                    :task="$task"
                    :details="[
                        'supplierDeliveryDate' => $productionMonitorDate,
                        'productionIssueNote' => $productionMonitorNote,
                    ]"
                    :can-edit="$canEditTask && strcasecmp((string) ($job?->status ?? ''), 'Cancelled') !== 0"
                />
            @endif

            @if($checklistReady)
                @include('components.jobs.task-detail.checklist')
            @else
                <x-ui.progressive-section-loader section="checklist" method="loadDetailSection" key-prefix="task-detail" context-type="task" :context-id="$task->id" :rows="3" message="Loading checklist when needed…" root-margin="320px 0px" />
            @endif

            @if($attachmentsReady)
                @include('components.jobs.task-detail.attachments')
            @else
                <x-ui.progressive-section-loader section="attachments" method="loadDetailSection" key-prefix="task-detail" context-type="task" :context-id="$task->id" :rows="3" message="Loading task attachments when needed…" root-margin="300px 0px" />
            @endif

            @if($activityReady)
                @include('components.jobs.task-detail.activity')
            @else
                <x-ui.progressive-section-loader section="activity" method="loadDetailSection" key-prefix="task-detail" context-type="task" :context-id="$task->id" :rows="4" message="Loading task activity when needed…" root-margin="300px 0px" />
            @endif
        </main>
        <aside>
            @include('components.jobs.task-detail.sidebar')

        </aside>
    </div>

    @if($taskOrderIsOnHold && $taskOrderHold)
        <x-jobs.order-detail.hold-blocked-modal
            :hold="$taskOrderHold"
            :can-release-hold="false"
            :order-id="$job?->id"
            :direct-release="false"
        />
    @endif
</div>
