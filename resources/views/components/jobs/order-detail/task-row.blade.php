@props(['job', 'task', 'mode' => 'locked', 'displayCode' => null, 'taskStatuses' => collect(), 'context' => [], 'overviewTaskLinkFormTaskId' => null])
@php
    $permissions = data_get($context, 'taskPermissions.'.(int) $task->id, []);
    $orderOnHold = (bool) ($context['isOnHold'] ?? false);
    $taskEditPermission = (bool) data_get($permissions, 'edit', false);
    $taskAssignPermission = (bool) data_get($permissions, 'assign', false);
    $taskDeletePermission = (bool) data_get($permissions, 'delete', false);
    $documentUploadPermission = (bool) ($context['canUploadDocument'] ?? false);
    $documentLinkPermission = (bool) ($context['canLinkDocument'] ?? false);
    $documentDeletePermission = (bool) ($context['canDeleteDocument'] ?? false);
    // A held Order is view-only. Keep the underlying permission values above so
    // we can still render a clear locked action for users who would normally be
    // able to perform the task, but never expose a mutating control while held.
    $canEditTask = $taskEditPermission && ! $orderOnHold;
    $canAssignTask = $taskAssignPermission && ! $orderOnHold;
    $canDeleteTask = $taskDeletePermission && ! $orderOnHold;
    $canUploadDocument = $documentUploadPermission && ! $orderOnHold;
    $canLinkDocument = $documentLinkPermission && ! $orderOnHold;
    $canDeleteDocument = $documentDeletePermission && ! $orderOnHold;
    $canExportDocument = (bool) ($context['canExportDocument'] ?? false);
    $taskDocuments = $job->documents->where('task_id', $task->id);
    if (! (bool) config('flowtrack.order_tracking_enabled', false)) {
        $taskDocuments = $taskDocuments->reject(
            fn ($document) => $document->category === \App\Models\Document::CATEGORY_ARTWORK_TRACKING_PDF
        );
    }
    $taskDocuments = $taskDocuments->sortByDesc('created_at')->values();
    $taskLinks = \App\Support\JobDetailPresenter::taskLinks($job, $task);
    $automationKey = app(\App\Services\OrderWorkflowActionService::class)->automationKey($task);
    $isArtworkUploadTask = $automationKey === 'ART_PREPARE_UPLOAD';
    // Purchase Order upload tasks use the same document presentation style as
    // artwork uploads: show the uploaded file card with uploader, timestamp,
    // latest marker and document actions.
    $isPurchaseOrderUploadTask = $automationKey === 'NEW_UPLOAD_PO';
    $isProductionEstimatedDeliveryTask = $automationKey === 'PROD_SET_ESTIMATED_DELIVERY';
    $isProductionMonitorTask = $automationKey === 'PROD_ISSUE';
    $isRegularOptionalTask = \App\Support\OrderTaskRequirement::isRegularOptional($task);
    $artworkRevisionNotes = $task->relationLoaded('artworkRevisionNotes') ? $task->artworkRevisionNotes : collect();
    $revisionReferenceDocumentIds = $artworkRevisionNotes
        ->map(function ($revisionNote) {
            $referenceDocument = $revisionNote->relationLoaded('referenceDocument')
                ? $revisionNote->getRelation('referenceDocument')
                : null;

            return (int) ($referenceDocument?->id ?? 0);
        })
        ->filter()
        ->unique()
        ->values();
    // Selective Artwork revision is file-specific: accepted files keep their
    // previous version while only replaced files increment. Use the hydrated
    // current set instead of assuming every current file shares max(version).
    $latestArtworkDocuments = $isArtworkUploadTask
        ? ($task->relationLoaded('currentArtworkDocuments')
            ? collect($task->getRelation('currentArtworkDocuments'))->sortBy('id')->values()
            : app(\App\Services\DocumentService::class)->currentArtworkDocuments($task, $taskDocuments))
        : collect();
    $latestArtworkDocument = $latestArtworkDocuments->last();
    $resourceDocuments = $isArtworkUploadTask
        ? $latestArtworkDocuments
        : $taskDocuments
            ->reject(fn ($document) => $revisionReferenceDocumentIds->contains((int) $document->id))
            ->values();
    $isCurrentPhase = (int) $task->workflow_phase_id === (int) $job->workflow_phase_id;
    $status = $mode === 'locked' && !\App\Support\OrderDetailPresenter::isCompletedTask($task)
        ? 'Locked'
        : trim((string) ($task->status ?: ($mode === 'active' ? 'Ready' : 'Completed')));
    $statusLower = strtolower($status);
    $statusClass = $mode === 'done' ? 'done' : ($mode === 'active' ? ((str_contains($statusLower, 'wait') || str_contains($statusLower, 'issue') || str_contains($statusLower, 'pending')) ? 'wait' : 'active') : '');
    $displayStatus = $isProductionEstimatedDeliveryTask && $mode === 'active' && ! $job->estimated_delivery_date
        ? 'Required'
        : $status;
    if ($isProductionEstimatedDeliveryTask && $mode === 'active' && ! $job->estimated_delivery_date) $statusClass = 'wait';

    // A normal optional task can be actionable in parallel with the next
    // required task. Present its untouched Not Started/Locked state as Ready
    // once the previous required work has been completed.
    if ($isRegularOptionalTask && $mode === 'active' && \App\Support\BoardLaneResolver::isNotStarted((string) $task->status)) {
        $displayStatus = 'Ready';
        $statusClass = 'active';
    }
    $supplierAssigned = $task->relationLoaded('supplierAssignment') && $task->supplierAssignment !== null;
    $assigneeName = $supplierAssigned
        ? 'Supplier'.($task->assignee ? ' + '.$task->assignee->name : '')
        : ($task->assignee?->name ?: 'Unassigned');
    $assigneeInitials = collect(preg_split('/\s+/', trim($assigneeName)))->filter()->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('');
    $isCancelled = strcasecmp((string) $job->status, 'Cancelled') === 0;
    $showProductionMonitorInline = $isProductionMonitorTask
        && $mode === 'active'
        && $isCurrentPhase
        && $canEditTask
        && ! $isCancelled
        && ! $orderOnHold;
    $productionMonitorInitialDate = $job->supplier_delivery_date?->format('Y-m-d') ?? '';
    $productionMonitorErrorPrefix = 'productionMonitor.'.(int) $task->id;
    $productionMonitorSavedDetails = (array) data_get($context, 'productionMonitorDetails.'.(int) $task->id, []);
    $showProductionMonitorSummary = $isProductionMonitorTask && $mode === 'done';
    $displayDueDate = $isProductionEstimatedDeliveryTask ? $job->estimated_delivery_date : $task->due_date;
    $dueDisplay = $displayDueDate?->format('M j, Y') ?? ($isProductionMonitorTask ? '-' : ($isProductionEstimatedDeliveryTask ? 'Set date' : 'Set due date'));
    $canInlineEditDueDate = $canEditTask
        && ! $isCancelled
        && ! $showProductionMonitorInline
        && (! $isProductionEstimatedDeliveryTask || $mode === 'done');
    $dueEditorKey = $isProductionEstimatedDeliveryTask ? 'job-'.$job->id.'-estimated-delivery-date' : 'task-'.$task->id.'-due-date';
    $dueEditorLabel = $isProductionEstimatedDeliveryTask ? 'estimated delivery date' : 'task due date';
    if ($showProductionMonitorInline) {
        $displayStatus = 'In Progress';
        $statusClass = 'active';
    }
    $documentCategoryName = (string) ($task->documentCategory?->name ?: $task->setupTemplate?->documentCategory?->name ?: '');
    $requiresDocument = (bool) ($task->document_category_id || $task->setupTemplate?->document_category_id);
    $requiredBeforeCompletion = (bool) ($task->setupTemplate?->document_required_before_completion ?? false);
    $workflowAction = data_get($context, 'taskActions.'.(int) $task->id, []);
    $workflowActionLabel = (string) ($workflowAction['label'] ?? 'Take action');
    $workflowActionType = (string) ($workflowAction['type'] ?? 'workflow');
    $workflowEmailStatus = (array) data_get($context, 'workflowEmailStatuses.'.(int) $task->id, []);
    $workflowInvoice = (array) data_get($context, 'workflowInvoices.'.(int) $task->id, []);
    $workflowInvoiceId = (int) ($workflowInvoice['id'] ?? 0);
    $workflowInvoicePdfName = trim((string) ($workflowInvoice['pdf_name'] ?? ''));
    $workflowInvoiceDisplayName = $workflowInvoicePdfName !== ''
        ? $workflowInvoicePdfName
        : (($workflowInvoice['invoice_number'] ?? 'Invoice').'.pdf');
    $workflowInvoiceCreatorName = trim((string) ($workflowInvoice['creator_name'] ?? '')) ?: 'FlowTrack';
    $workflowInvoicePreparedAt = $workflowInvoice['prepared_at'] ?? null;
    $emailResendFeedback = (array) data_get($context, 'workflowEmailResendFeedback.'.(int) $task->id, []);
    $emailResendFeedbackType = strtolower(trim((string) ($emailResendFeedback['type'] ?? '')));
    $emailResendFeedbackMessage = trim((string) ($emailResendFeedback['message'] ?? ''));
    $emailResendFeedbackStatus = strtolower(trim((string) ($emailResendFeedback['email_status'] ?? '')));
    $isArtworkEmailTask = $automationKey === 'ART_SEND_ORDER_TEAM';
    $isInvoiceEmailTask = $automationKey === 'BILL_SEND';
    $isTrackedEmailTask = $isArtworkEmailTask || $isInvoiceEmailTask;
    $emailDeliveryStatus = strtolower(trim((string) ($workflowEmailStatus['status'] ?? '')));
    if (in_array($emailResendFeedbackStatus, ['sent', 'failed', 'not_sent'], true)) $emailDeliveryStatus = $emailResendFeedbackStatus;
    // Completed legacy rows may predate delivery tracking. Show an explicit
    // Not Sent state instead of silently hiding email status.
    if ($isTrackedEmailTask && $mode === 'done' && $emailDeliveryStatus === '') $emailDeliveryStatus = 'not_sent';
    $emailDeliveryFailed = $isTrackedEmailTask && $emailDeliveryStatus === 'failed';
    $emailDeliverySent = $isTrackedEmailTask && $emailDeliveryStatus === 'sent';
    $emailDeliveryNotSent = $isTrackedEmailTask && $emailDeliveryStatus === 'not_sent';
    $emailCanResendIfUnlocked = $isTrackedEmailTask
        && $mode === 'done'
        && $taskEditPermission
        && (bool) ($workflowEmailStatus['resendable'] ?? ! empty($workflowEmailStatus['to_emails'] ?? []));
    $emailCanResend = $emailCanResendIfUnlocked && ! $orderOnHold;
    $canAddCompletedTaskDocumentsIfUnlocked = $automationKey === 'NEW_UPLOAD_PO'
        && $taskEditPermission
        && ($documentUploadPermission || $documentLinkPermission);
    $emailResourceLabel = $isInvoiceEmailTask ? 'invoice' : 'artwork';
    $taskColor = \App\Support\MasterColor::normalize((string) ($task->setupTemplate?->color ?? $task->template?->color ?? ''))
        ?: \App\Support\MasterColor::normalize((string) ($task->phase?->color ?? ''))
        ?: '#2563EB';
@endphp
<article id="order-task-{{ $task->id }}"
    class="task ft-order-task-row {{ $mode }} {{ $isCancelled ? 'cancelled-task' : '' }} {{ $orderOnHold ? 'is-order-held' : '' }} {{ $isProductionEstimatedDeliveryTask ? 'ft-order-task-row--estimated-delivery' : '' }} {{ $showProductionMonitorInline ? 'ft-order-task-row--production-monitor' : '' }}"
    style="{{ \App\Support\MasterColor::style($taskColor) }}border-left:4px solid var(--ft-master-color,#2563EB)"
    wire:key="order-task-row-{{ $task->id }}"
    @if($showProductionMonitorInline)
        x-data="{ productionSupplierDate: @js($productionMonitorInitialDate), productionIssueNote: '', initialSupplierDate: @js($productionMonitorInitialDate) }"
    @endif
>
    <div class="task-icon ft-order-task-icon">{{ $mode === 'done' ? '✓' : ($mode === 'active' ? '●' : '⌁') }}</div>
    <div class="task-copy ft-order-task-copy">
        <div class="task-code">TASK {{ $displayCode ?: ($task->task_number ?: str_pad((string) $task->id, 3, '0', STR_PAD_LEFT)) }}</div>
        <div class="task-title">
            {{ $task->title }}
            @if($isProductionEstimatedDeliveryTask)
                <span class="ft-order-required-task-badge">Required</span>
            @elseif($isRegularOptionalTask)
                <span class="ft-order-optional-task-badge">Optional</span>
            @endif
        </div>
        @if($task->description || $task->setupTemplate?->description)<div class="task-description">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($task->description ?: $task->setupTemplate?->description)), 105) }}</div>@endif
    </div>

    <div class="ft-order-task-assignee-inline ft-inline-edit-shell"
        x-data="window.FlowTrack.ui.inlineEdit({ key:@js('task-'.$task->id.'-assignee'), label:'task assignee', value:@js($supplierAssigned ? 'supplier' : ($task->assignee_id ?? '')), display:@js($assigneeName), avatarUrl:@js($task->assignee?->profileImageUrl() ?? '') })"
        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
        x-on:click.outside="if(editing) cancelEdit()"
        x-on:ft-inline-remote-cancel.stop="cancelEdit()"
        x-on:task-assignee-updated.window="if (Number($event.detail?.taskId) === Number({{ $task->id }})) syncConfirmed(String($event.detail?.assigneeId ?? ''), String($event.detail?.assigneeName ?? 'Unassigned'), { avatarUrl:String($event.detail?.avatarUrl ?? '') })"
        x-on:ft-inline-remote-selected.stop="commit(String($event.detail?.value ?? ''), String($event.detail?.label ?? 'Unassigned'), () => $wire.updateTaskAssigneeFromJob({{ $task->id }}, draftValue), { avatarUrl:String($event.detail?.avatarUrl ?? '') })">
        <div class="ft-order-inline-display-row">
            @if($canAssignTask && !$isCancelled)
                <button
                    x-ref="assigneeAnchor"
                    :disabled="status === 'saving'"
                    type="button"
                    class="ft-order-assignee-display ft-order-inline-name-trigger"
                    :class="{ 'is-open': editing }"
                    title="Edit assignee"
                    aria-label="Edit task assignee"
                    x-on:click.stop="openRemotePicker($refs.assigneeAnchor)"
                >
                    <span class="ft-inline-avatar-slot"><x-ui.inline-live-avatar :size="28" /></span>
                    <span class="ft-order-assignee-name" x-text="display">{{ $assigneeName }}</span>
                    <span class="ft-order-inline-trigger-icon" aria-hidden="true">✎</span>
                </button>
            @else
                <div class="ft-order-assignee-display">
                    <span class="ft-inline-avatar-slot"><x-ui.inline-live-avatar :size="28" /></span>
                    <span class="ft-order-assignee-name" x-text="display">{{ $assigneeName }}</span>
                </div>
            @endif
        </div>
        @if($canAssignTask && !$isCancelled)
            <div x-cloak x-show="editing" class="ft-order-assignee-picker">
                <x-ui.inline-remote-user :value="$supplierAssigned ? 'supplier' : ($task->assignee_id ?? '')" :selected-label="$assigneeName" parent-type="job" :parent-id="$job->id" variant="compact" :menu-width="300" :allow-supplier="\App\Services\SupplierBridge\SupplierWorkflowProjection::actionKey($automationKey, (int) ($task->phase?->sequence ?? 0)) !== null" external-trigger />
            </div>
            <x-ui.inline-save-state compact />
        @endif

    </div>

    <div class="date ft-order-task-due ft-inline-edit-shell"
        @if($isProductionEstimatedDeliveryTask)
            wire:key="order-estimated-delivery-editor-{{ $task->id }}-{{ $displayDueDate?->format('Ymd') ?? 'unset' }}"
        @endif
        x-data="window.FlowTrack.ui.inlineEdit({ key:@js($dueEditorKey), label:@js($dueEditorLabel), value:@js($displayDueDate?->format('Y-m-d') ?? ''), display:@js($dueDisplay) })"
        :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }">
        <div class="ft-order-inline-display-row" x-show="!editing">
            <span class="ft-order-inline-value" x-text="display">{{ $dueDisplay }}</span>
            @if($canInlineEditDueDate)
                <button :disabled="status === 'saving'" type="button" class="ft-inline-edit-button ft-order-inline-edit-button" title="{{ $isProductionEstimatedDeliveryTask ? 'Edit estimated delivery date' : 'Edit due date' }}" aria-label="{{ $isProductionEstimatedDeliveryTask ? 'Edit estimated delivery date' : 'Edit task due date' }}" x-on:click.stop="if (beginEdit()) $nextTick(() => $refs.orderDue.showPicker ? $refs.orderDue.showPicker() : $refs.orderDue.focus())">✎</button>
            @endif
        </div>
        @if($canInlineEditDueDate)
            <input x-ref="orderDue" x-cloak x-show="editing" x-model="draftValue" class="ft-order-inline-input" type="date"
                x-on:keydown.escape.prevent="cancelEdit()"
                x-on:blur="if (editing) cancelEdit()"
                @if($isProductionEstimatedDeliveryTask)
                    x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateEstimatedDeliveryDateFromJob({{ $task->id }}, draftValue))"
                @else
                    x-on:change="commit($event.target.value, formatDate($event.target.value), () => $wire.updateTaskDueDateFromJob({{ $task->id }}, draftValue))"
                @endif>
            <x-ui.inline-save-state compact />
        @endif
    </div>

    <div class="task-state ft-order-task-state">
        <span class="task-status ft-order-task-status {{ $statusClass }}">{{ $displayStatus }}</span>
        @if($orderOnHold && $mode === 'active')
            <span class="ft-order-task-held-badge"><span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span> Order on hold</span>
        @endif
        @if($isTrackedEmailTask && $mode === 'done')
            @if($emailDeliverySent)
                <span class="ft-order-task-email-status is-sent" title="The latest {{ $emailResourceLabel }} email was sent successfully.">Email Sent</span>
            @elseif($emailDeliveryFailed)
                <span class="ft-order-task-email-status is-failed" title="The {{ $emailResourceLabel }} email did not reach the selected recipients. The completed task can still resend it.">Email Failed</span>
            @elseif($emailDeliveryNotSent)
                <span class="ft-order-task-email-status is-not-sent" title="The task was completed without a successful {{ $emailResourceLabel }} email delivery.">Email Not Sent</span>
            @endif
            @if($emailResendFeedbackMessage !== '')
                <div class="ft-order-task-email-feedback {{ $emailResendFeedbackType === 'success' ? 'is-success' : 'is-error' }}" role="status" aria-live="polite">{{ $emailResendFeedbackMessage }}</div>
            @endif
        @endif
        @if($workflowInvoiceId > 0)
            <div class="card-sub">
                📎 {{ $workflowInvoiceDisplayName }} · Latest
            </div>
        @elseif($taskDocuments->isNotEmpty())
            @php $latestTaskDocument = $isArtworkUploadTask ? $latestArtworkDocument : $taskDocuments->first(); @endphp
            <div class="card-sub">
                📎 {{ $latestTaskDocument->name }}
                @if($isArtworkUploadTask)
                    · Latest
                    @if($latestArtworkDocuments->count() > 1)
                        · +{{ $latestArtworkDocuments->count() - 1 }} file{{ $latestArtworkDocuments->count() === 2 ? '' : 's' }}
                    @endif
                @elseif($taskDocuments->count() > 1)
                    +{{ $taskDocuments->count() - 1 }}
                @endif
            </div>
        @elseif($taskLinks->isNotEmpty())
            <div class="card-sub">↗ {{ $taskLinks->count() }} external link{{ $taskLinks->count() === 1 ? '' : 's' }}</div>
        @elseif($requiresDocument)
            <div class="card-sub">📎 {{ $documentCategoryName ?: 'Document' }}{{ $requiredBeforeCompletion ? ' required' : '' }}</div>
        @endif
    </div>

    <div class="task-actions ft-order-task-actions">
        @if($showProductionMonitorInline)
            <button
                type="button"
                class="btn small primary ft-production-monitor-save"
                x-on:click.stop="$wire.saveProductionMonitorTask({{ $task->id }}, productionSupplierDate, productionIssueNote)"
                wire:loading.attr="disabled"
                wire:target="saveProductionMonitorTask"
            >
                <span wire:loading.remove wire:target="saveProductionMonitorTask">Save</span>
                <span wire:loading wire:target="saveProductionMonitorTask">Saving...</span>
            </button>
            <button
                type="button"
                class="btn small ft-production-monitor-cancel"
                x-on:click.stop="productionSupplierDate = initialSupplierDate; productionIssueNote = ''; $wire.clearProductionMonitorErrors({{ $task->id }})"
            >Cancel</button>
        @elseif($isCancelled)
            <button type="button" class="btn small" disabled>Blocked</button>
        @elseif($orderOnHold)
            @if($mode === 'active' && $isCurrentPhase && $taskEditPermission)
                <button
                    type="button"
                    class="btn small ft-order-task-hold-locked-action"
                    x-on:click.prevent.stop="showHoldBlocked(@js($workflowActionLabel))"
                    title="Order is on hold. Release the hold before performing this task."
                >
                    <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                    <span>{{ $workflowActionLabel }}</span>
                </button>
            @elseif($mode === 'done')
                @if($canAddCompletedTaskDocumentsIfUnlocked)
                    <button type="button" class="btn small ft-order-task-hold-locked-action" x-on:click.prevent.stop="showHoldBlocked('Add other documents')" title="Order is on hold. Release the hold before adding documents.">
                        <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                        <span>Add other documents</span>
                    </button>
                @elseif($emailCanResendIfUnlocked)
                    <button type="button" class="btn small ft-order-task-hold-locked-action" x-on:click.prevent.stop="showHoldBlocked('Resend')" title="Order is on hold. Release the hold before resending.">
                        <span class="ft-order-hold-pause-icon" aria-hidden="true"><i></i><i></i></span>
                        <span>Resend</span>
                    </button>
                @endif
                <button type="button" class="btn small" wire:click="viewTask({{ $task->id }})">View</button>
            @else
                <button type="button" class="btn small" wire:click="viewTask({{ $task->id }})">View</button>
            @endif
        @elseif($mode === 'active' && $isCurrentPhase)
            @if($canEditTask)
                @if(($workflowActionType === 'document' || ($requiresDocument && $requiredBeforeCompletion && $taskDocuments->isEmpty() && $taskLinks->isEmpty())) && ($canUploadDocument || $canLinkDocument))
                    <button type="button" class="btn small primary" wire:click="openOrderWorkflowAction({{ $task->id }})" wire:loading.attr="disabled" wire:target="openOrderWorkflowAction({{ $task->id }})">{{ $workflowActionLabel }}</button>
                @else
                    <button type="button" class="btn small primary" wire:click="openOrderWorkflowAction({{ $task->id }})" wire:loading.attr="disabled" wire:target="openOrderWorkflowAction({{ $task->id }})">{{ $workflowActionLabel }}</button>
                @endif
            @endif
        @elseif($mode === 'done')
            @if($automationKey === 'NEW_UPLOAD_PO' && $canEditTask && ($canUploadDocument || $canLinkDocument))
                <button type="button" class="btn small" wire:click="openOverviewTaskDocumentModal({{ $task->id }})">Add other documents</button>
            @elseif($isTrackedEmailTask && $canEditTask)
                @if($emailCanResend)
                    @php $resendMethod = $isInvoiceEmailTask ? 'resendCompletedInvoiceEmail' : 'resendCompletedArtworkEmail'; @endphp
                    <button type="button" class="btn small primary ft-order-task-resend-email" wire:click="{{ $resendMethod }}({{ $task->id }})" wire:loading.attr="disabled" wire:target="{{ $resendMethod }}({{ $task->id }})">
                        <span wire:loading.remove wire:target="{{ $resendMethod }}({{ $task->id }})">Resend</span>
                        <span wire:loading wire:target="{{ $resendMethod }}({{ $task->id }})">Sending...</span>
                    </button>
                @endif
                <button type="button" class="btn small" wire:click="viewTask({{ $task->id }})">View</button>
            @else
                <button type="button" class="btn small" wire:click="viewTask({{ $task->id }})">View</button>
            @endif
        @endif
    </div>

    @if($showProductionMonitorInline)
        <x-jobs.order-detail.production-monitor-inline
            :task="$task"
            :initial-date="$productionMonitorInitialDate"
            :error-prefix="$productionMonitorErrorPrefix"
        />
    @elseif($showProductionMonitorSummary)
        <x-jobs.order-detail.production-monitor-summary
            :task="$task"
            :details="$productionMonitorSavedDetails"
            :can-edit="$canEditTask && ! $isCancelled"
        />
    @endif
</article>

@if((int) $overviewTaskLinkFormTaskId === (int) $task->id && $canEditTask)
    <form class="task-link-form ft-order-task-link-form" wire:submit.prevent="saveOverviewTaskLink({{ $task->id }})" wire:key="order-task-link-form-{{ $task->id }}">
        <input type="url" wire:model="overviewTaskLinkUrl" placeholder="Paste external document link..." autocomplete="url">
        <button type="button" class="btn small" wire:click="cancelOverviewTaskLinkForm">Cancel</button><button type="submit" class="btn primary small">Add link</button>
        @error('overviewTaskLinkUrl')<span class="validation-error">{{ $message }}</span>@enderror
    </form>
@endif

@foreach($artworkRevisionNotes as $revisionNote)
    <x-jobs.order-detail.artwork-revision-card
        :revision-note="$revisionNote"
        :can-export-document="$canExportDocument"
    />
@endforeach

@if($workflowInvoiceId > 0 || $resourceDocuments->isNotEmpty() || $taskLinks->isNotEmpty())
    <div class="task-resources ft-order-task-resources">
        @if($workflowInvoiceId > 0)
            <div
                wire:key="order-task-invoice-{{ $task->id }}-{{ $workflowInvoiceId }}"
                class="ft-order-task-resource-row is-latest-artwork ft-order-task-invoice-resource-row"
            >
                <x-ui.file-type-badge :name="$workflowInvoiceDisplayName" class="ft-order-file-icon" />
                <span>
                    <b>{{ $workflowInvoiceDisplayName }}</b>
                    <small>
                        {{ $workflowInvoiceCreatorName }} · {{ \App\Support\UserLocalTime::format($workflowInvoicePreparedAt, 'M j, Y, g:i A') }} · Latest version
                    </small>
                </span>
                <span class="ft-order-task-resource-actions">
                    <em class="ft-order-artwork-version-state is-latest">Latest</em>
                    <a href="{{ route('invoices.pdf.open', $workflowInvoiceId) }}" target="_blank" rel="noopener">Open</a>
                    <a href="{{ route('invoices.pdf.download', $workflowInvoiceId) }}">Download</a>
                </span>
            </div>
        @endif
        @foreach($resourceDocuments as $document)
<div
                wire:key="order-task-document-{{ $document->id }}"
                class="ft-order-task-resource-row {{ ($isArtworkUploadTask || $isPurchaseOrderUploadTask) ? 'is-latest-artwork' : '' }}"
            >
                <x-ui.file-type-badge :name="$document->name" class="ft-order-file-icon" />
                <span>
                    <b>{{ $document->name }}</b>
                    <small>
                        {{ $document->uploader?->name ?? 'FlowTrack' }} · {{ \App\Support\UserLocalTime::format($document->created_at, 'M j, Y, g:i A') }}
                        @if($isArtworkUploadTask || $isPurchaseOrderUploadTask) · Latest version @endif
                    </small>
                </span>
                <span class="ft-order-task-resource-actions">
                    @if($isArtworkUploadTask || $isPurchaseOrderUploadTask)
                        <em class="ft-order-artwork-version-state is-latest">Latest</em>
                    @endif
                    <a href="{{ route('documents.open', $document) }}" target="_blank" rel="noopener">Open</a>
                    @if($canExportDocument)<a href="{{ route('documents.download', $document) }}">Download</a>@endif
                    @if($canDeleteDocument)<button type="button" wire:click="deleteJobDocument({{ $document->id }})" wire:confirm="Delete this document link?">×</button>@endif
                </span>
            </div>
        @endforeach
        @foreach($taskLinks as $link)
            <div wire:key="order-task-link-{{ $link->id }}"><span class="file-icon link ft-order-file-icon">↗</span><span><b>{{ \Illuminate\Support\Str::limit($link->url, 90) }}</b><small>{{ $link->created_at ? \App\Support\UserLocalTime::format($link->created_at, 'M j, Y, g:i A') : '—' }}</small></span><span><a href="{{ $link->url }}" target="_blank" rel="noopener noreferrer">Open ↗</a>@if($canEditTask)<button type="button" wire:click="deleteOverviewTaskLink({{ $task->id }}, {{ $link->id }})" wire:confirm="Remove this link?">×</button>@endif</span></div>
        @endforeach
    </div>
@endif
