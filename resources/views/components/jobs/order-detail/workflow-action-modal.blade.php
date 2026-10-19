@props(['job', 'task', 'config' => [], 'step' => 'main', 'payload' => [], 'modalPreview' => [], 'attachment' => null, 'revisionComments' => [], 'revisionAttachments' => [], 'mentionUsers' => collect(), 'emailFallback' => false, 'emailFallbackMessage' => '', 'emailFallbackAttempts' => 0])
@php
    $variant = (string) ($config['variant'] ?? 'confirm');
    $title = (string) ($config['title'] ?? 'Task action');
    $copy = (string) ($config['copy'] ?? 'Confirm this workflow action.');
    $choices = collect($config['choices'] ?? ['confirm' => 'Confirm']);
    $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
    $automationKey = $workflowActions->automationKey($task);
    $latestArtworkTask = $job->tasks->first(fn($candidate) => $workflowActions->automationKey($candidate) === 'ART_PREPARE_UPLOAD');
    $artworkDocs = $latestArtworkTask
        ? $job->documents->where('task_id', $latestArtworkTask->id)->sortBy('created_at')->values()
        : collect();
    // Order Details/List action hosts preload the current Artwork relation before
    // rendering this component. Keep Blade presentation-only: never fall back to
    // DocumentService here because that can perform database work during a rerender.
    $latestArtworkDocuments = ($latestArtworkTask && $latestArtworkTask->relationLoaded('currentArtworkDocuments'))
        ? collect($latestArtworkTask->getRelation('currentArtworkDocuments'))->sortBy('id')->values()
        : collect();
    $latestArtwork = $latestArtworkDocuments->last();
    $currentArtworkVersions = $latestArtworkDocuments
        ->pluck('version')
        ->map(fn($version) => max(1, (int) $version))
        ->unique()
        ->sort()
        ->values();
    $artworkVersionLabel = $currentArtworkVersions->isEmpty()
        ? 'V1'
        : $currentArtworkVersions->map(fn($version) => 'V'.$version)->implode(' · ');
    $currentArtworkDocumentIds = $latestArtworkDocuments->pluck('id')->map(fn($id) => (int) $id);
    // Preserve the existing Previous Versions behavior: every non-current artwork
    // stays visible here, including normal full re-uploads. Cancellation metadata
    // only changes the status label; it must not narrow the historical list.
    $archivedArtworkDocuments = $artworkDocs
        ->reject(fn($document) => $currentArtworkDocumentIds->contains((int) $document->id))
        ->sortByDesc('id')
        ->values();
    $cancelledArtworkDocumentIds = ($latestArtworkTask && $job->relationLoaded('artworkCancellationActivities'))
        ? collect($job->getRelation('artworkCancellationActivities'))
            ->filter(fn($activity) => (int) data_get($activity->meta, 'target_task_id', 0) === (int) $latestArtworkTask->id)
            ->flatMap(fn($activity) => collect(data_get($activity->meta, 'document_ids', [])))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
        : collect();
    $activeItems = \App\Support\OrderDetailPresenter::activeItems($job);
    $firstItem = $activeItems->first();
    $productName = (string) ($firstItem?->product_name ?: $job->product ?: 'Order product');
    // activeItems() can return a lightweight stdClass for legacy orders that
    // still use the order-level product fields. Only Eloquent-backed items
    // expose relationLoaded(), so guard that call before checking supplier.
    $firstItemHasLoadedSupplier = is_object($firstItem)
        && method_exists($firstItem, 'relationLoaded')
        && $firstItem->relationLoaded('supplier');
    $supplierName = $firstItemHasLoadedSupplier
        ? (string) ($firstItem->supplier?->name ?: 'Supplier')
        : 'Supplier';
    $orderNumber = $job->displayOrderNumber();
    $clientName = (string) ($job->client?->name ?: 'Client');
    $ownerName = (string) ($job->owner?->name ?: $job->coordinator?->name ?: 'FlowTrack');
    $orderTotal = (float) $activeItems->sum(fn($item) => (float) ($item->unit_price ?? 0) * (int) ($item->quantity ?? 0));
    // Expensive email/PDF preview preparation is owned by the Livewire action
    // handler and never by Blade. The first modal render carries metadata only;
    // wire:init completes the exact email body after the dialog is visible.
    $emailHandoffPreview = (array) ($modalPreview['email_handoff_preview'] ?? []);
    $invoiceEmailPreview = (array) ($modalPreview['invoice_email_preview'] ?? []);
    $workflowInvoice = (array) ($modalPreview['workflow_invoice'] ?? []);
    $emailPreviewPending = in_array($variant, ['purchase_order_email', 'artwork_email', 'invoice_send'], true)
        && ! (bool) ($modalPreview['preview_loaded'] ?? false);

    // Keep all Billing preparation logic inside this single top-level PHP block.
    // Avoid nested PHP directive blocks inside the long Blade branch chain so
    // the template keeps one simple, balanced control structure during Livewire
    // renders and no directive source can leak into the modal markup.
    $workflowRemoteAreaCharge = max(0, (float) ($payload['remote_area_charge'] ?? 0));
    $invoiceRecipientOptions = collect();
    $invoiceToEmail = '';
    $invoiceMatchedToUser = null;
    $invoiceToQuery = '';
    $invoiceToSuggestions = collect();
    $invoiceToIsValidEmail = false;
    $invoiceNoSystemMatch = false;
    $invoiceCcEmails = '';
    $invoiceCcParts = collect();
    $invoiceCcQuery = '';
    $invoiceCcPrefix = collect();
    $invoiceCcExactMatch = false;
    $invoiceCcSuggestions = collect();
    $invoiceCcSuggestionValues = collect();

    if ($variant === 'invoice_send') {
        $invoiceRecipientOptions = collect($invoiceEmailPreview['recipient_options'] ?? []);
        $invoiceToEmail = trim((string) ($payload['to_email'] ?? ''));
        $invoiceMatchedToUser = $invoiceRecipientOptions->first(
            fn ($option) => mb_strtolower(trim((string) ($option['email'] ?? ''))) === mb_strtolower($invoiceToEmail)
        );
        $invoiceToQuery = mb_strtolower($invoiceToEmail);
        $invoiceToSuggestions = $invoiceToQuery === '' || $invoiceMatchedToUser
            ? collect()
            : $invoiceRecipientOptions
                ->filter(function ($option) use ($invoiceToQuery) {
                    return str_contains(mb_strtolower((string) ($option['name'] ?? '')), $invoiceToQuery)
                        || str_contains(mb_strtolower((string) ($option['email'] ?? '')), $invoiceToQuery);
                })
                ->take(6)
                ->values();
        $invoiceToIsValidEmail = $invoiceToEmail !== '' && filter_var($invoiceToEmail, FILTER_VALIDATE_EMAIL);
        $invoiceNoSystemMatch = $invoiceToQuery !== ''
            && ! $invoiceMatchedToUser
            && $invoiceToSuggestions->isEmpty()
            && ! $invoiceToIsValidEmail;

        $invoiceCcEmails = trim((string) ($payload['cc_emails'] ?? ''));
        $invoiceCcParts = collect(preg_split('/[,;]+/', $invoiceCcEmails) ?: [])
            ->map(fn ($value) => trim((string) $value));
        $invoiceCcQuery = mb_strtolower((string) ($invoiceCcParts->last() ?? ''));
        $invoiceCcPrefix = $invoiceCcParts
            ->slice(0, max(0, $invoiceCcParts->count() - 1))
            ->filter()
            ->values();
        $invoiceCcExactMatch = $invoiceRecipientOptions->contains(
            fn ($option) => mb_strtolower(trim((string) ($option['email'] ?? ''))) === $invoiceCcQuery
        );
        $invoiceCcSuggestions = $invoiceCcQuery === '' || $invoiceCcExactMatch
            ? collect()
            : $invoiceRecipientOptions
                ->reject(fn ($option) => $invoiceMatchedToUser && (int) $option['id'] === (int) $invoiceMatchedToUser['id'])
                ->filter(function ($option) use ($invoiceCcQuery) {
                    return str_contains(mb_strtolower((string) ($option['name'] ?? '')), $invoiceCcQuery)
                        || str_contains(mb_strtolower((string) ($option['email'] ?? '')), $invoiceCcQuery);
                })
                ->reject(function ($option) use ($invoiceCcPrefix) {
                    $email = mb_strtolower(trim((string) ($option['email'] ?? '')));
                    return $invoiceCcPrefix->contains(fn ($value) => mb_strtolower((string) $value) === $email);
                })
                ->take(6)
                ->values();

        $invoiceCcSuggestionValues = $invoiceCcSuggestions->mapWithKeys(function ($option) use ($invoiceCcPrefix) {
            $value = $invoiceCcPrefix
                ->concat([(string) ($option['email'] ?? '')])
                ->filter()
                ->unique(fn ($email) => mb_strtolower(trim((string) $email)))
                ->implode(', ');

            return [(int) ($option['id'] ?? 0) => $value];
        });
    }
    $emailServiceEnabled = (bool) (($variant === 'invoice_send' ? $invoiceEmailPreview : $emailHandoffPreview)['email_service_enabled'] ?? true);
    if (! $emailServiceEnabled && in_array($variant, ['purchase_order_email', 'artwork_email'], true)) {
        $title = $variant === 'artwork_email' ? 'Complete Artwork Handoff' : 'Complete Purchase Order Handoff';
        $copy = 'Email sending is currently disabled. Choose the intended recipients, then complete the handoff and send the file manually.';
    } elseif (! $emailServiceEnabled && $variant === 'invoice_send') {
        $title = 'Complete Send Invoice';
        $copy = 'Email sending is currently disabled. Confirm the intended recipient and complete the task now; the invoice can be resent from the completed task later.';
    }
    $emailFallbackDocumentId = (int) ($emailHandoffPreview['document_id'] ?? 0);
    $emailFallbackDocument = $emailFallbackDocumentId > 0
        ? $job->documents->firstWhere('id', $emailFallbackDocumentId)
        : null;
    $emailFallbackAttachmentLabel = $variant === 'artwork_email' ? 'Artwork' : 'Purchase Order';
    $artworkHandoffCommentHistory = ($variant === 'artwork_email' && $job->relationLoaded('workflowEmailActivities'))
        ? collect($job->getRelation('workflowEmailActivities'))
            ->filter(fn ($activity) => (int) data_get($activity->meta, 'task_id', 0) === (int) $task->id)
            ->filter(fn ($activity) => in_array((string) $activity->event, [
                'job.artwork_emailed_to_order_team',
                'job.workflow_email_skipped',
            ], true))
            ->sortByDesc('id')
            ->map(fn ($activity) => [
                'id' => (int) $activity->id,
                'comment' => trim((string) data_get($activity->meta, 'customer_comment', '')),
                'created_at' => $activity->created_at,
            ])
            ->filter(fn (array $entry) => $entry['comment'] !== '')
            ->values()
        : collect();
    $revisionMentionUsers = collect($mentionUsers)->values();
    $selectedRevisionDocumentIds = collect($payload['revision_document_ids'] ?? [])
        ->map(fn($id) => (int) $id)
        ->filter(fn($id) => $id > 0)
        ->unique()
        ->values();
    $selectedCancellationDocumentIds = collect($payload['cancel_artwork_document_ids'] ?? [])
        ->map(fn($id) => (int) $id)
        ->filter(fn($id) => $id > 0)
        ->unique()
        ->values();
    $selectedCancellationProductIds = collect($payload['cancel_product_item_ids'] ?? [])
        ->map(fn($id) => (int) $id)
        ->filter(fn($id) => $id > 0)
        ->unique()
        ->values();
    $cancellableItems = $activeItems
        ->filter(fn($item) => (int) ($item->id ?? 0) > 0 && filled($item->product_name ?? null))
        ->values();


    // Only the main Artwork preview/handoff screens need the large landscape
    // layout. Revision/issue follow-up steps must stay on the normal compact
    // dialog size even when they originate from an Artwork task.
    $isArtworkPreviewModal = $step === 'main'
        && in_array($variant, ['artwork_review', 'artwork_email', 'client_erp'], true);
    // Billing and Payment render validation inside a two-column form. Give
    // those dialogs a stable validation layout so field errors never resize
    // or horizontally shift the popup after submit.
    $usesStableFinanceValidation = $step === 'main'
        && in_array($variant, ['invoice_prepare', 'payment'], true);
    $modalWide = in_array($variant, ['courier_label', 'shipment_info', 'invoice_send'], true);
    // Every artwork revision dialog uses the same compact prototype shell.
    // The copy/labels still vary by task, but layout and controls stay consistent.
    $isArtworkRevisionRequest = $step === 'revision';
    $isArtworkCancellationRequest = $step === 'cancel_artwork';

    if ($step === 'sample') {
        $title = 'Is a Sample or Swatch Required?';
        $copy = 'The artwork is approved. Decide whether supplier sample approval is required before Production.';
    } elseif ($step === 'revision') {
        $title = $automationKey === 'ART_INTERNAL_REVIEW' ? 'Request Artwork Revision' : 'Client Revision Request';
        $copy = $automationKey === 'ART_INTERNAL_REVIEW'
            ? 'Select one or more artworks. Add the required change and any supporting files under each selected artwork.'
            : 'Select one or more artworks and record the client feedback for each file before restarting the approval cycle.';
    } elseif ($step === 'cancel_artwork') {
        $title = 'Cancel Artwork';
        $copy = 'Select the artwork to remove from the active Order. You can also remove the related product at the same time.';
    } elseif ($step === 'issue') {
        $title = $automationKey === 'QC_CHECK' ? 'Report QC Issue' : 'Report Production Issue';
        $copy = 'Describe the issue before notifying the supplier and blocking progression.';
    }
@endphp
{{-- Workflow action dialogs close only through their explicit controls.
     Native selects/date pickers and embedded PDF viewers can finish a pointer
     interaction outside the dialog; backdrop-click dismissal made those normal
     interactions close the invoice modal unexpectedly. --}}
<div class="ft-order-task-document-modal-backdrop" wire:key="order-workflow-action-modal-{{ $task->id }}-{{ $step }}" @if($emailPreviewPending) wire:init="loadOrderWorkflowActionEmailPreview" @endif>
    <section
        class="ft-order-task-document-modal ft-order-workflow-action-modal {{ $isArtworkPreviewModal ? 'ft-order-workflow-action-modal--artwork-preview' : ($modalWide ? 'ft-order-workflow-action-modal--wide' : '') }} {{ $usesStableFinanceValidation ? 'ft-order-workflow-action-modal--stable-finance-validation' : '' }} {{ ($isArtworkRevisionRequest || $isArtworkCancellationRequest) ? 'ft-order-workflow-action-modal--artwork-revision-request' : '' }} {{ $isArtworkCancellationRequest ? 'ft-order-workflow-action-modal--artwork-cancellation-request' : '' }}"
        data-ft-feedback-scope="form"
        role="dialog"
        aria-modal="true"
        aria-labelledby="order-workflow-action-modal-title"
    >
        <header class="ft-order-task-document-modal-head">
            <div><h2 id="order-workflow-action-modal-title">{{ $title }}</h2><p>{{ $copy }}</p></div>
            <button type="button" wire:click="closeOrderWorkflowAction" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction" aria-label="Close">×</button>
        </header>

        <div class="ft-order-task-document-modal-body ft-prototype-action-body">
            @if($step === 'sample')
                <div class="ft-prototype-choice-grid">
                    <button type="button" wire:click="submitOrderWorkflowAction('sample_no')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">
                        <span class="ft-prototype-choice-icon">→</span>
                        <strong>No</strong>
                        <small>Go directly to Production</small>
                    </button>
                    <button type="button" wire:click="submitOrderWorkflowAction('sample_yes')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">
                        <span class="ft-prototype-choice-icon">✓</span>
                        <strong>Yes</strong>
                        <small>Activate Sample Approval</small>
                    </button>
                </div>
            @elseif($step === 'cancel_artwork')
                <div class="ft-artwork-cancellation">
                    <section class="ft-artwork-cancellation-section">
                        <div class="ft-artwork-revision-selector-head">
                            <div>
                                <strong>Select artwork to cancel</strong>
                                <span>The selected files will leave the current artwork set but remain in Order history. At least one current artwork must remain.</span>
                            </div>
                            @if($selectedCancellationDocumentIds->isNotEmpty())
                                <em>{{ $selectedCancellationDocumentIds->count() }} selected</em>
                            @endif
                        </div>

                        <div class="ft-artwork-revision-selector-list">
                            @forelse($latestArtworkDocuments as $cancelDocument)
                                <div
                                    class="ft-artwork-revision-selector-item {{ $selectedCancellationDocumentIds->contains((int) $cancelDocument->id) ? 'is-selected' : '' }}"
                                    wire:key="artwork-cancel-item-{{ $task->id }}-{{ (int) $cancelDocument->id }}"
                                >
                                    <div class="ft-artwork-revision-selector-summary">
                                        <label class="ft-artwork-revision-selector-choice">
                                            <input
                                                type="checkbox"
                                                wire:model.live="orderWorkflowActionPayload.cancel_artwork_document_ids"
                                                value="{{ (int) $cancelDocument->id }}"
                                            >
                                            <span class="ft-artwork-revision-selector-check" aria-hidden="true">✓</span>
                                            <x-ui.file-type-badge :extension="strtoupper(pathinfo((string) $cancelDocument->name, PATHINFO_EXTENSION) ?: 'FILE')" size="sm" />
                                            <span class="ft-artwork-revision-selector-copy">
                                                <b title="{{ $cancelDocument->name }}">{{ $cancelDocument->name }}</b>
                                                <small>Current artwork</small>
                                            </span>
                                        </label>
                                        <a href="{{ route('documents.open', $cancelDocument) }}" target="_blank" rel="noopener">View</a>
                                    </div>
                                </div>
                            @empty
                                <div class="ft-artwork-revision-selector-empty">No current artwork files are available to cancel.</div>
                            @endforelse
                        </div>
                        @error('orderWorkflowActionPayload.cancel_artwork_document_ids')<p class="validation-error">{{ $message }}</p>@enderror
                    </section>

                    @if($selectedCancellationDocumentIds->isNotEmpty())
                        <section class="ft-artwork-cancellation-section ft-artwork-cancellation-products">
                            <div class="ft-artwork-cancellation-section-head">
                                <div>
                                    <strong>Remove product with artwork <span>(optional)</span></strong>
                                    <small>Select only the Order product lines that should be removed with the cancelled artwork. At least one active Order product must remain.</small>
                                </div>
                                @if($selectedCancellationProductIds->isNotEmpty())
                                    <em>{{ $selectedCancellationProductIds->count() }} selected</em>
                                @endif
                            </div>

                            @if($cancellableItems->isNotEmpty())
                                <div class="ft-artwork-cancellation-product-list">
                                    @foreach($cancellableItems as $cancelItem)
                                        <label
                                            class="ft-artwork-cancellation-product {{ $selectedCancellationProductIds->contains((int) $cancelItem->id) ? 'is-selected' : '' }}"
                                            wire:key="artwork-cancel-product-{{ $task->id }}-{{ (int) $cancelItem->id }}"
                                        >
                                            <input
                                                type="checkbox"
                                                wire:model.live="orderWorkflowActionPayload.cancel_product_item_ids"
                                                value="{{ (int) $cancelItem->id }}"
                                            >
                                            <span class="ft-artwork-revision-selector-check" aria-hidden="true">✓</span>
                                            <span class="ft-artwork-cancellation-product-copy">
                                                <b>{{ $cancelItem->product_name ?: 'Order product' }}</b>
                                                <small>{{ number_format((int) ($cancelItem->quantity ?? 0)) }} units{{ filled($cancelItem->category_name ?? null) ? ' · '.trim((string) $cancelItem->category_name) : '' }}</small>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            @else
                                <div class="ft-artwork-revision-selector-empty">No removable Order product lines are available.</div>
                            @endif
                            @error('orderWorkflowActionPayload.cancel_product_item_ids')<p class="validation-error">{{ $message }}</p>@enderror
                        </section>

                        <label class="ft-artwork-cancellation-reason">
                            <span>Cancellation reason <b>*</b></span>
                            <textarea
                                wire:model="orderWorkflowActionComment"
                                rows="3"
                                maxlength="2000"
                                placeholder="Why is this artwork being removed from the Order?"
                            ></textarea>
                        </label>
                        @error('orderWorkflowActionComment')<p class="validation-error">{{ $message }}</p>@enderror

                        <div class="ft-artwork-cancellation-note">
                            <span aria-hidden="true">i</span>
                            <p>The artwork file and any selected products stay in history for audit. This action does not cancel the whole Order.</p>
                        </div>
                    @endif
                </div>
            @elseif($step === 'revision')
                <div class="ft-artwork-revision-selector">
                    <div class="ft-artwork-revision-selector-head">
                        <div>
                            <strong>Which artwork needs revision?</strong>
                            <span>Select one or more files. Each selected artwork opens its own required change and supporting attachments.</span>
                        </div>
                        @if($selectedRevisionDocumentIds->isNotEmpty())
                            <em>{{ $selectedRevisionDocumentIds->count() }} selected</em>
                        @endif
                    </div>

                    <div class="ft-artwork-revision-selector-list">
                        @forelse($latestArtworkDocuments as $revisionDocument)
                            @php
                                $revisionDocumentId = (int) $revisionDocument->id;
                                $revisionExtension = strtoupper(pathinfo((string) $revisionDocument->name, PATHINFO_EXTENSION) ?: 'FILE');
                                $revisionSelected = $selectedRevisionDocumentIds->contains($revisionDocumentId);
                                $documentAttachments = collect($revisionAttachments[$revisionDocumentId] ?? $revisionAttachments[(string) $revisionDocumentId] ?? [])->filter()->values();
                                $documentAttachmentDetails = $documentAttachments->map(function ($file) {
                                    $name = $file->getClientOriginalName();
                                    return [
                                        'name' => $name,
                                        'type' => strtoupper((string) pathinfo($name, PATHINFO_EXTENSION)) ?: 'FILE',
                                        'size' => $file->getSize() >= 1048576
                                            ? number_format($file->getSize() / 1048576, 1).' MB'
                                            : number_format(max(1, (int) ceil($file->getSize() / 1024))).' KB',
                                    ];
                                });
                            @endphp
                            <div
                                class="ft-artwork-revision-selector-item {{ $revisionSelected ? 'is-selected' : '' }}"
                                wire:key="artwork-revision-request-item-{{ $task->id }}-{{ $revisionDocumentId }}"
                            >
                                <div class="ft-artwork-revision-selector-summary">
                                    <label class="ft-artwork-revision-selector-choice">
                                        <input
                                            type="checkbox"
                                            wire:model.live="orderWorkflowActionPayload.revision_document_ids"
                                            value="{{ $revisionDocumentId }}"
                                        >
                                        <span class="ft-artwork-revision-selector-check" aria-hidden="true">✓</span>
                                        <x-ui.file-type-badge :extension="$revisionExtension" size="sm" />
                                        <span class="ft-artwork-revision-selector-copy">
                                            <b title="{{ $revisionDocument->name }}">{{ $revisionDocument->name }}</b>
                                            <small>Artwork</small>
                                        </span>
                                    </label>
                                    <a href="{{ route('documents.open', $revisionDocument) }}" target="_blank" rel="noopener">View</a>
                                </div>

                                @if($revisionSelected)
                                    <div class="ft-artwork-revision-item-details">
                                        <label class="ft-artwork-revision-item-change">
                                            <span>{{ $automationKey === 'ART_INTERNAL_REVIEW' ? 'Required change' : 'Client feedback' }} <b>*</b></span>
                                            <textarea
                                                wire:model="orderWorkflowActionRevisionComments.{{ $revisionDocumentId }}"
                                                rows="3"
                                                autocomplete="off"
                                                placeholder="{{ $automationKey === 'ART_INTERNAL_REVIEW' ? 'Describe the required artwork changes...' : 'Record the client feedback...' }}"
                                            ></textarea>
                                        </label>
                                        @error('orderWorkflowActionRevisionComments.'.$revisionDocumentId)<p class="validation-error">{{ $message }}</p>@enderror

                                        <div
                                            class="ft-artwork-revision-item-support"
                                            x-data="{ uploading: false, progress: 0 }"
                                            x-on:livewire-upload-start="uploading = true; progress = 0"
                                            x-on:livewire-upload-progress="progress = Math.max(0, Math.min(100, Number($event.detail.progress) || 0))"
                                            x-on:livewire-upload-finish="progress = 100; window.setTimeout(() => { uploading = false; progress = 0 }, 350)"
                                            x-on:livewire-upload-error="uploading = false; progress = 0"
                                            x-on:livewire-upload-cancel="uploading = false; progress = 0"
                                        >
                                            <div class="ft-artwork-revision-item-support-head">
                                                <div>
                                                    <strong>Supporting attachments <span>(optional)</span></strong>
                                                    <small>Add marked-up artwork, screenshots, or reference documents for this file.</small>
                                                </div>
                                                @if($documentAttachments->isNotEmpty())
                                                    <em>{{ $documentAttachments->count() }} file{{ $documentAttachments->count() === 1 ? '' : 's' }}</em>
                                                @endif
                                            </div>

                                            @if($documentAttachmentDetails->isNotEmpty())
                                                <div class="ft-artwork-revision-support-files">
                                                    @foreach($documentAttachmentDetails as $index => $supportFile)
                                                        <div class="ft-order-attachment-selected-file" wire:key="artwork-revision-support-{{ $task->id }}-{{ $revisionDocumentId }}-{{ $index }}-{{ md5($supportFile['name']) }}">
                                                            <x-ui.file-type-badge :extension="$supportFile['type']" size="sm" />
                                                            <span class="ft-order-attachment-selected-copy">
                                                                <strong title="{{ $supportFile['name'] }}">{{ $supportFile['name'] }}</strong>
                                                                <small>{{ $supportFile['type'] }} · {{ $supportFile['size'] }} · Ready</small>
                                                            </span>
                                                            <button
                                                                type="button"
                                                                wire:click="removeOrderWorkflowActionRevisionAttachment({{ $revisionDocumentId }}, {{ $index }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="orderWorkflowActionRevisionAttachments.{{ $revisionDocumentId }},removeOrderWorkflowActionRevisionAttachment({{ $revisionDocumentId }}, {{ $index }})"
                                                            >Remove</button>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <label class="ft-order-task-document-dropzone ft-order-attachment-dropzone ft-artwork-revision-support-dropzone {{ $documentAttachments->isNotEmpty() ? 'is-compact' : '' }}" data-file-dropzone>
                                                <input
                                                    type="file"
                                                    multiple
                                                    wire:model="orderWorkflowActionRevisionAttachments.{{ $revisionDocumentId }}"
                                                    accept="{{ \App\Support\AttachmentUpload::accept() }}"
                                                    aria-label="Choose supporting files for {{ $revisionDocument->name }}"
                                                >
                                                <svg class="ft-order-attachment-upload-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 16l-4-4-4 4M12 12v9M20.4 17.5A5 5 0 0 0 18 8.2 7 7 0 0 0 4.3 10.8 4.5 4.5 0 0 0 5.5 19H7"/></svg>
                                                @if($documentAttachments->isNotEmpty())
                                                    <strong>Choose supporting files</strong>
                                                    <b>Drag &amp; drop or <span>browse</span></b>
                                                @else
                                                    <strong>Drag &amp; drop files here</strong>
                                                    <b>or choose from your computer</b>
                                                    <span class="ft-order-attachment-browse">Browse files</span>
                                                @endif
                                                <small data-drop-status>{{ \App\Support\AttachmentUpload::helperText(400) }} · Up to 50 files</small>
                                            </label>

                                            <div
                                                class="ft-create-attachment-progress"
                                                x-cloak
                                                x-show="uploading"
                                                x-transition.opacity.duration.120ms
                                                aria-live="polite"
                                            >
                                                <div class="ft-create-attachment-progress-meta">
                                                    <span>Uploading attachment{{ $documentAttachments->count() === 1 ? '' : 's' }}...</span>
                                                    <b x-text="`${Math.round(progress)}%`">0%</b>
                                                </div>
                                                <div
                                                    class="ft-create-attachment-progress-track"
                                                    role="progressbar"
                                                    aria-label="Supporting attachment upload progress"
                                                    aria-valuemin="0"
                                                    aria-valuemax="100"
                                                    x-bind:aria-valuenow="Math.round(progress)"
                                                >
                                                    <span x-bind:style="`width: ${progress}%`"></span>
                                                </div>
                                            </div>
                                            @error('orderWorkflowActionRevisionAttachments.'.$revisionDocumentId)<p class="validation-error">{{ $message }}</p>@enderror
                                            @error('orderWorkflowActionRevisionAttachments.'.$revisionDocumentId.'.*')<p class="validation-error">{{ $message }}</p>@enderror
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="ft-artwork-revision-selector-empty">No current artwork files are available to revise.</div>
                        @endforelse
                    </div>
                    @error('orderWorkflowActionPayload.revision_document_ids')<p class="validation-error">{{ $message }}</p>@enderror

                    @if($automationKey === 'ART_INTERNAL_REVIEW' && $selectedRevisionDocumentIds->isNotEmpty())
                        <div class="ft-artwork-revision-visibility-note">
                            <span aria-hidden="true">▢</span>
                            Supporting attachments will be visible to the artwork assignee and other relevant team members.
                        </div>
                    @endif
                </div>
            @elseif($step === 'issue')
                <div class="ft-prototype-form-grid">
                    <label class="ft-prototype-field"><span>Issue category</span><select wire:model="orderWorkflowActionPayload.issue_category"><option>Fabric color variance</option><option>Print color mismatch</option><option>Incorrect dimensions</option><option>Damaged items</option><option>Other</option></select>@error('orderWorkflowActionPayload.issue_category')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Supplier</span><input value="{{ $supplierName }}" disabled></label>
                </div>
                <label class="ft-prototype-field"><span>Description</span><textarea wire:model="orderWorkflowActionComment" rows="5" placeholder="Describe the issue and corrective action required..."></textarea>@error('orderWorkflowActionComment')<p class="validation-error">{{ $message }}</p>@enderror</label>
                <div class="ft-prototype-file-placeholder"><strong>Screenshot / photo</strong><span>Supporting images/documents can be added to the task after the issue is recorded.</span></div>
                <div class="ft-prototype-email-preview"><b>Email preview</b><span>The issue notification will be recorded for {{ $supplierName }} with this Order and task.</span></div>
            @elseif($variant === 'purchase_order_email')
                @php
                    $recipientOptions = collect($emailHandoffPreview['recipient_options'] ?? []);

                    $toEmail = trim((string) ($payload['to_email'] ?? ''));
                    if ($toEmail === '') {
                        $legacyToUser = $recipientOptions->firstWhere('id', (int) ($payload['to_user_id'] ?? 0));
                        $toEmail = trim((string) ($legacyToUser['email'] ?? ($payload['external_to_email'] ?? '')));
                    }

                    $matchedToUser = $recipientOptions->first(
                        fn($option) => mb_strtolower(trim((string) ($option['email'] ?? ''))) === mb_strtolower($toEmail)
                    );
                    $toQuery = mb_strtolower($toEmail);
                    $toSuggestions = $toQuery === '' || $matchedToUser
                        ? collect()
                        : $recipientOptions
                            ->filter(function($option) use ($toQuery) {
                                return str_contains(mb_strtolower((string) ($option['name'] ?? '')), $toQuery)
                                    || str_contains(mb_strtolower((string) ($option['email'] ?? '')), $toQuery);
                            })
                            ->take(6)
                            ->values();

                    $ccEmails = trim((string) ($payload['cc_emails'] ?? ''));
                    if ($ccEmails === '') {
                        $legacyCcUserEmails = collect($payload['cc_user_ids'] ?? [])
                            ->map(fn($id) => $recipientOptions->firstWhere('id', (int) $id)['email'] ?? null)
                            ->filter();
                        $legacyExternalCc = collect(preg_split('/[\s,;]+/', trim((string) ($payload['external_cc_emails'] ?? ''))) ?: [])->filter();
                        $ccEmails = $legacyCcUserEmails->concat($legacyExternalCc)->unique()->implode(', ');
                    }

                    $ccParts = collect(preg_split('/[,;]+/', $ccEmails) ?: [])
                        ->map(fn($value) => trim((string) $value));
                    $ccQuery = mb_strtolower((string) ($ccParts->last() ?? ''));
                    $ccPrefix = $ccParts->slice(0, max(0, $ccParts->count() - 1))->filter()->values();
                    $ccExactMatch = $recipientOptions->contains(
                        fn($option) => mb_strtolower(trim((string) ($option['email'] ?? ''))) === $ccQuery
                    );
                    $ccSuggestions = $ccQuery === '' || $ccExactMatch
                        ? collect()
                        : $recipientOptions
                            ->reject(fn($option) => $matchedToUser && (int) $option['id'] === (int) $matchedToUser['id'])
                            ->filter(function($option) use ($ccQuery) {
                                return str_contains(mb_strtolower((string) ($option['name'] ?? '')), $ccQuery)
                                    || str_contains(mb_strtolower((string) ($option['email'] ?? '')), $ccQuery);
                            })
                            ->reject(function($option) use ($ccPrefix) {
                                $email = mb_strtolower(trim((string) ($option['email'] ?? '')));
                                return $ccPrefix->contains(fn($value) => mb_strtolower((string) $value) === $email);
                            })
                            ->take(6)
                            ->values();
                    $hasCcRecipients = filled($ccEmails);
                    $recipientCount = (int) ($emailHandoffPreview['recipient_count'] ?? 0);
                    $toIsValidEmail = $toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL);
                @endphp

                <div class="ft-order-task-document-target">
                    <span class="ft-order-task-document-target-icon">PO</span>
                    <div>
                        <small>ATTACHMENT</small>
                        <strong>{{ $emailHandoffPreview['document_name'] ?? 'No Purchase Order uploaded' }}</strong>
                        <span>{{ filled($emailHandoffPreview['document_version'] ?? null) ? 'Version '.(int) $emailHandoffPreview['document_version'] : 'Upload the Purchase Order in the previous task first' }}</span>
                    </div>
                    <em>{{ $recipientCount > 0 ? $recipientCount.' recipient'.($recipientCount === 1 ? '' : 's') : 'Choose recipient' }}</em>
                </div>

                <section
                    class="ft-po-mail-recipients"
                    aria-label="Purchase Order email recipients"
                    x-data="{ ccOpen: @js($hasCcRecipients) }"
                >
                    <div class="ft-po-mail-row ft-po-mail-row--to">
                        <label for="po-to-email-{{ $task->id }}">To</label>
                        <div class="ft-po-mail-row__control ft-po-mail-recipient-control">
                            <input
                                id="po-to-email-{{ $task->id }}"
                                type="email"
                                wire:model.live.debounce.300ms="orderWorkflowActionPayload.to_email"
                                placeholder="Enter email or search Artwork Team"
                                autocomplete="off"
                                spellcheck="false"
                            >

                            @if($toSuggestions->isNotEmpty())
                                <div class="ft-po-mail-suggestions" role="listbox" aria-label="Artwork Team suggestions">
                                    @foreach($toSuggestions as $option)
                                        <button
                                            type="button"
                                            wire:key="po-to-suggestion-{{ $task->id }}-{{ (int) $option['id'] }}"
                                            wire:click="$set('orderWorkflowActionPayload.to_email', @js((string) $option['email']))"
                                            class="ft-po-mail-suggestion"
                                        >
                                            <span class="ft-po-mail-suggestion__avatar">{{ mb_strtoupper(mb_substr((string) ($option['name'] ?? $option['email']), 0, 1)) }}</span>
                                            <span><b>{{ $option['name'] }}</b><small>{{ $option['email'] }}</small></span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <button
                            type="button"
                            class="ft-po-mail-cc-toggle"
                            x-on:click="ccOpen = !ccOpen"
                            x-bind:aria-expanded="ccOpen.toString()"
                            aria-controls="po-cc-fields-{{ $task->id }}"
                        >Cc</button>
                    </div>
                    @error('orderWorkflowActionPayload.to_email')<p class="validation-error ft-po-mail-validation">{{ $message }}</p>@enderror

                    @if($matchedToUser)
                        <div class="ft-po-assignment-note ft-po-assignment-note--mail">
                            <span aria-hidden="true">✓</span>
                            <p><b>{{ $matchedToUser['name'] }}</b> will receive the Purchase Order by email and will be automatically assigned to <strong>Prepare &amp; Upload Artwork</strong>.</p>
                        </div>
                    @elseif($toIsValidEmail)
                        <p class="ft-po-mail-help">This email will receive the Purchase Order. It does not match an Artwork Team user, so no internal artwork task will be auto-assigned.</p>
                    @endif

                    <div id="po-cc-fields-{{ $task->id }}" class="ft-po-mail-cc" x-cloak x-show="ccOpen">
                        <div class="ft-po-mail-row ft-po-mail-row--cc">
                            <label for="po-cc-emails-{{ $task->id }}">Cc</label>
                            <div class="ft-po-mail-row__control ft-po-mail-recipient-control">
                                <input
                                    id="po-cc-emails-{{ $task->id }}"
                                    type="text"
                                    wire:model.live.debounce.300ms="orderWorkflowActionPayload.cc_emails"
                                    placeholder="Add email addresses, separated by commas"
                                    autocomplete="off"
                                    spellcheck="false"
                                >

                                @if($ccSuggestions->isNotEmpty())
                                    <div class="ft-po-mail-suggestions" role="listbox" aria-label="Artwork Team CC suggestions">
                                        @foreach($ccSuggestions as $option)
                                            @php
                                                $nextCcEmails = $ccPrefix
                                                    ->concat([(string) $option['email']])
                                                    ->unique(fn($email) => mb_strtolower(trim((string) $email)))
                                                    ->implode(', ');
                                            @endphp
                                            <button
                                                type="button"
                                                wire:key="po-cc-suggestion-{{ $task->id }}-{{ (int) $option['id'] }}"
                                                wire:click="$set('orderWorkflowActionPayload.cc_emails', @js($nextCcEmails))"
                                                class="ft-po-mail-suggestion"
                                            >
                                                <span class="ft-po-mail-suggestion__avatar">{{ mb_strtoupper(mb_substr((string) ($option['name'] ?? $option['email']), 0, 1)) }}</span>
                                                <span><b>{{ $option['name'] }}</b><small>{{ $option['email'] }}</small></span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                        @error('orderWorkflowActionPayload.cc_emails')<p class="validation-error ft-po-mail-validation">{{ $message }}</p>@enderror
                    </div>
                </section>

                <x-email.handoff-preview
                    :loading="$emailPreviewPending"
                    :preview="$emailHandoffPreview"
                    :defaultSubject="'Purchase Order ready — '.$orderNumber"
                    emptyRecipientText="Enter an email address in To to see the final email details."
                />
                @error('orderWorkflowActionEmail')<p class="validation-error">{{ $message }}</p>@enderror
            @elseif($variant === 'artwork_review' || $variant === 'artwork_email' || $variant === 'client_erp')
                <div
                    class="ft-prototype-artwork-preview"
                    x-data="{ selectedArtworkId: {{ (int) ($latestArtwork?->id ?? 0) }} }"
                >
                    <div class="ft-prototype-artwork-canvas">
                        @if($latestArtworkDocuments->isNotEmpty())
                            @foreach($latestArtworkDocuments as $previewDocument)
                                @php $previewExtension = strtolower(pathinfo((string) $previewDocument->name, PATHINFO_EXTENSION)); @endphp
                                <div
                                    class="ft-prototype-artwork-canvas-item"
                                    x-cloak
                                    x-show="selectedArtworkId === {{ (int) $previewDocument->id }}"
                                >
                                    @if(in_array($previewExtension, ['jpg','jpeg','png','webp','gif'], true))
                                        <img src="{{ route('documents.open', $previewDocument) }}" alt="Artwork preview: {{ $previewDocument->name }}">
                                    @else
                                        <div class="ft-prototype-artwork-file"><span>{{ strtoupper($previewExtension ?: 'FILE') }}</span><strong>{{ $previewDocument->name }}</strong><a href="{{ route('documents.open', $previewDocument) }}" target="_blank" rel="noopener">Open artwork</a></div>
                                    @endif
                                </div>
                            @endforeach
                        @else
                            <div class="ft-prototype-artwork-file"><span>ART</span><strong>Artwork file</strong><small>No previewable image available</small></div>
                        @endif
                    </div>
                    <div class="ft-prototype-artwork-meta">
                        <small>LATEST ARTWORK · {{ $latestArtworkDocuments->count() }} FILE{{ $latestArtworkDocuments->count() === 1 ? '' : 'S' }}</small>
                        <h3>{{ $productName }}</h3>
                        <dl>
                            <div><dt>Versions</dt><dd>{{ $artworkVersionLabel }}</dd></div>
                            <div><dt>Files</dt><dd>{{ $latestArtworkDocuments->isNotEmpty() ? $latestArtworkDocuments->pluck('name')->implode(', ') : 'Artwork file' }}</dd></div>
                            <div><dt>Uploaded by</dt><dd>{{ $latestArtwork?->uploader?->name ?: $task->assignee?->name ?: 'FlowTrack' }}</dd></div>
                            <div><dt>Client</dt><dd>{{ $clientName }}</dd></div>
                        </dl>
                        @if($latestArtworkDocuments->isNotEmpty())
                            <div class="ft-prototype-artwork-actions">
                                @foreach($latestArtworkDocuments as $previewDocument)
                                    <span x-cloak x-show="selectedArtworkId === {{ (int) $previewDocument->id }}">
                                        <a href="{{ route('documents.open', $previewDocument) }}" target="_blank" rel="noopener">Open</a>
                                        <a href="{{ route('documents.download', $previewDocument) }}">Download</a>
                                    </span>
                                @endforeach
                            </div>
                            <div class="ft-artwork-current-file-picker" aria-label="Current artwork files">
                                <div class="ft-artwork-current-file-picker-head">
                                    <strong>Current artwork files</strong>
                                    <span>Select a file below to preview it on the left.</span>
                                </div>
                                @foreach($latestArtworkDocuments as $doc)
                                    <button
                                        type="button"
                                        class="ft-artwork-current-file-choice"
                                        x-on:click="selectedArtworkId = {{ (int) $doc->id }}"
                                        x-bind:class="{ 'is-active': selectedArtworkId === {{ (int) $doc->id }} }"
                                    >
                                        <span class="ft-artwork-current-file-choice-type">{{ strtoupper(pathinfo((string) $doc->name, PATHINFO_EXTENSION) ?: 'FILE') }}</span>
                                        <span class="ft-artwork-current-file-choice-copy">
                                            <b title="{{ $doc->name }}">{{ $doc->name }}</b>
                                            <small>Artwork</small>
                                        </span>
                                        <em x-text="selectedArtworkId === {{ (int) $doc->id }} ? 'Viewing' : 'Preview'">Preview</em>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                        @if($archivedArtworkDocuments->isNotEmpty())
                            <details class="ft-prototype-version-history">
                                <summary>Previous artwork versions</summary>
                                <div class="ft-prototype-version-list">
                                    @foreach($archivedArtworkDocuments as $index => $doc)
                                        <div>
                                            <span class="ft-prototype-version-file">
                                                <strong>{{ $doc->name }}</strong>
                                                <small>{{ \App\Support\UserLocalTime::format($doc->created_at, 'M j, Y, g:i A') }}</small>
                                            </span>
                                            <span class="ft-prototype-version-status">
                                                <b>{{ $cancelledArtworkDocumentIds->contains((int) $doc->id) ? 'Cancelled' : 'Archived' }}</b>
                                                <a href="{{ route('documents.open', $doc) }}" target="_blank" rel="noopener">Open</a>
                                                <a href="{{ route('documents.download', $doc) }}">Download</a>
                                            </span>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                </div>

                @if($variant === 'artwork_email')
                    @php
                        $artworkRecipientOptions = collect($emailHandoffPreview['recipient_options'] ?? []);
                        $artworkToEmails = trim((string) ($payload['to_emails'] ?? ''));
                        if ($artworkToEmails === '') {
                            $artworkToEmails = trim((string) ($payload['to_email'] ?? ''));
                        }

                        $artworkToParts = collect(preg_split('/[,;]+/', $artworkToEmails) ?: [])
                            ->map(fn($value) => trim((string) $value));
                        $artworkToQuery = mb_strtolower((string) ($artworkToParts->last() ?? ''));
                        $artworkToPrefix = $artworkToParts
                            ->slice(0, max(0, $artworkToParts->count() - 1))
                            ->filter()
                            ->values();
                        $artworkToExactMatch = $artworkRecipientOptions->contains(
                            fn($option) => mb_strtolower(trim((string) ($option['email'] ?? ''))) === $artworkToQuery
                        );
                        $artworkToSuggestions = $artworkToQuery === '' || $artworkToExactMatch
                            ? collect()
                            : $artworkRecipientOptions
                                ->filter(function($option) use ($artworkToQuery) {
                                    return str_contains(mb_strtolower((string) ($option['name'] ?? '')), $artworkToQuery)
                                        || str_contains(mb_strtolower((string) ($option['email'] ?? '')), $artworkToQuery);
                                })
                                ->reject(function($option) use ($artworkToPrefix) {
                                    $email = mb_strtolower(trim((string) ($option['email'] ?? '')));
                                    return $artworkToPrefix->contains(fn($value) => mb_strtolower((string) $value) === $email);
                                })
                                ->take(6)
                                ->values();
                    @endphp

                    <section class="ft-po-mail-recipients ft-artwork-mail-recipients" aria-label="Artwork email recipients">
                        <div class="ft-po-mail-row ft-po-mail-row--single">
                            <label for="artwork-to-emails-{{ $task->id }}">To</label>
                            <div class="ft-po-mail-row__control ft-po-mail-recipient-control">
                                <input
                                    id="artwork-to-emails-{{ $task->id }}"
                                    type="text"
                                    wire:model.live.debounce.300ms="orderWorkflowActionPayload.to_emails"
                                    placeholder="Enter email or search users"
                                    autocomplete="off"
                                    spellcheck="false"
                                >

                                @if($artworkToSuggestions->isNotEmpty())
                                    <div class="ft-po-mail-suggestions" role="listbox" aria-label="System user suggestions">
                                        @foreach($artworkToSuggestions as $option)
                                            @php
                                                $nextArtworkToEmails = $artworkToPrefix
                                                    ->concat([(string) $option['email']])
                                                    ->unique(fn($email) => mb_strtolower(trim((string) $email)))
                                                    ->implode(', ');
                                            @endphp
                                            <button
                                                type="button"
                                                wire:key="artwork-to-suggestion-{{ $task->id }}-{{ (int) $option['id'] }}"
                                                wire:click="$set('orderWorkflowActionPayload.to_emails', @js($nextArtworkToEmails))"
                                                class="ft-po-mail-suggestion"
                                            >
                                                <span class="ft-po-mail-suggestion__avatar">{{ mb_strtoupper(mb_substr((string) ($option['name'] ?? $option['email']), 0, 1)) }}</span>
                                                <span><b>{{ $option['name'] }}</b><small>{{ $option['email'] }}</small></span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        </div>
                        @error('orderWorkflowActionPayload.to_emails')<p class="validation-error ft-po-mail-validation">{{ $message }}</p>@enderror
                    </section>

                    @if($artworkHandoffCommentHistory->isNotEmpty())
                        <x-jobs.order-detail.artwork-handoff-comment-history
                            :history="$artworkHandoffCommentHistory"
                            label="Previous customer comments"
                        />
                    @endif

                    <label class="ft-artwork-handoff-comment" for="artwork-customer-comment-{{ $task->id }}">
                        <span>Comment to customer <em>(optional)</em></span>
                        <textarea
                            id="artwork-customer-comment-{{ $task->id }}"
                            wire:model.live.debounce.300ms="orderWorkflowActionPayload.customer_comment"
                            rows="3"
                            maxlength="2000"
                            placeholder="Write your message or any important note for the customer..."
                        ></textarea>
                    </label>

                    <x-email.handoff-preview
                        :loading="$emailPreviewPending"
                        :preview="$emailHandoffPreview"
                        :defaultSubject="'Artwork ready — '.$orderNumber"
                        emptyRecipientText="Enter one or more email addresses in To."
                    />
                    @error('orderWorkflowActionEmail')<p class="validation-error">{{ $message }}</p>@enderror
                @elseif($variant === 'client_erp')
                    <label class="ft-prototype-field ft-prototype-field--top"><span>Client ERP reference</span><input wire:model="orderWorkflowActionPayload.erp_reference" placeholder="Client ERP reference">@error('orderWorkflowActionPayload.erp_reference')<p class="validation-error">{{ $message }}</p>@enderror</label>
                @endif
            @elseif($variant === 'client_decision')
                <p class="ft-prototype-modal-copy">Choose the decision received from {{ $clientName }} for the current artwork ({{ $artworkVersionLabel }}).</p>
                <div class="ft-prototype-choice-grid">
                    <button type="button" wire:click="submitOrderWorkflowAction('revise')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction"><span class="ft-prototype-choice-icon">↻</span><strong>Client Requested Revision</strong><small>Restart the artwork revision cycle</small></button>
                    <button type="button" wire:click="submitOrderWorkflowAction('approved')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction"><span class="ft-prototype-choice-icon">✓</span><strong>Client Approved Artwork</strong><small>Continue to sample decision</small></button>
                </div>
            @elseif($variant === 'estimated_delivery')
                <div class="ft-prototype-required-date-panel">
                    <div class="ft-prototype-required-date-icon">*</div>
                    <div>
                        <strong>Required before Production</strong>
                        <span>Set the estimated delivery date to unlock Start Production.</span>
                    </div>
                </div>
                <label class="ft-prototype-field ft-prototype-field--top">
                    <span>Estimated delivery date</span>
                    <input
                        type="date"
                        class="ft-prototype-clickable-date"
                        wire:model="orderWorkflowActionPayload.estimated_delivery_date"
                        onclick="this.focus({ preventScroll: true }); if (typeof this.showPicker === 'function') { try { this.showPicker(); } catch (e) {} }"
                        aria-label="Estimated delivery date. Click anywhere in the field to open the calendar."
                    >
                    @error('orderWorkflowActionPayload.estimated_delivery_date')<p class="validation-error">{{ $message }}</p>@enderror
                </label>
            @elseif($variant === 'production_monitor')
                <div class="ft-production-monitor-modal-date-row">
                    <label class="ft-prototype-field ft-prototype-field--top">
                        <span>Supplier Delivery Date <em>(Optional)</em></span>
                        <input
                            type="date"
                            class="ft-prototype-clickable-date"
                            wire:model="orderWorkflowActionPayload.supplier_delivery_date"
                            onclick="this.focus({ preventScroll: true }); if (typeof this.showPicker === 'function') { try { this.showPicker(); } catch (e) {} }"
                        >
                        @error('orderWorkflowActionPayload.supplier_delivery_date')<p class="validation-error">{{ $message }}</p>@enderror
                    </label>
                </div>
                <label class="ft-prototype-field ft-production-monitor-modal-note">
                    <span>Production Issue Note <em>(Optional)</em></span>
                    <textarea wire:model="orderWorkflowActionComment" rows="4" maxlength="10000" placeholder="Add note about the production issue, resolution, or communication with supplier..."></textarea>
                    @error('orderWorkflowActionComment')<p class="validation-error">{{ $message }}</p>@enderror
                </label>
            @elseif($variant === 'issue_resolution')
                <label class="ft-prototype-field"><span>Resolution</span><textarea wire:model="orderWorkflowActionComment" rows="5" placeholder="Describe the corrective action and resolution..."></textarea>@error('orderWorkflowActionComment')<p class="validation-error">{{ $message }}</p>@enderror</label>
            @elseif($variant === 'qc_check')
                <div class="ft-prototype-form-grid">
                    <label class="ft-prototype-field"><span>Quantity received</span><input type="number" min="1" wire:model="orderWorkflowActionPayload.qty_received">@error('orderWorkflowActionPayload.qty_received')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Quantity inspected</span><input type="number" min="1" wire:model="orderWorkflowActionPayload.qty_inspected">@error('orderWorkflowActionPayload.qty_inspected')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Accepted</span><input type="number" min="0" wire:model="orderWorkflowActionPayload.qty_accepted">@error('orderWorkflowActionPayload.qty_accepted')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Rejected</span><input type="number" min="0" wire:model="orderWorkflowActionPayload.qty_rejected">@error('orderWorkflowActionPayload.qty_rejected')<p class="validation-error">{{ $message }}</p>@enderror</label>
                </div>
                <label class="ft-prototype-field"><span>QC comments</span><textarea wire:model="orderWorkflowActionPayload.qc_comments" rows="4" placeholder="Record stitching, dimensions, packaging, print registration, or other QC notes..."></textarea>@error('orderWorkflowActionPayload.qc_comments')<p class="validation-error">{{ $message }}</p>@enderror</label>
                <div class="ft-prototype-choice-grid">
                    <button type="button" class="danger-choice" wire:click="submitOrderWorkflowAction('issue')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction"><span class="ft-prototype-choice-icon">!</span><strong>Report Issue</strong><small>Open a supplier-resolution issue</small></button>
                    <button type="button" wire:click="submitOrderWorkflowAction('pass')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction"><span class="ft-prototype-choice-icon">✓</span><strong>QC Passed</strong><small>Continue toward Shipment</small></button>
                </div>
            @elseif($variant === 'shipment_info')
                <x-jobs.order-detail.shipment.update-details-form :job="$job" :payload="$payload" />
            @elseif($variant === 'shipment_tracking')
                @php
                    $shipmentCourierOptions = collect($payload['courier_options'] ?? []);
                @endphp
                <div class="ft-prototype-form-grid">
                    <label class="ft-prototype-field">
                        <span>Courier</span>
                        <select wire:model="orderWorkflowActionPayload.carrier">
                            <option value="">Select courier</option>
                            @foreach($shipmentCourierOptions as $courierOption)
                                <option value="{{ $courierOption['value'] }}">{{ $courierOption['label'] }}</option>
                            @endforeach
                        </select>
                        @error('orderWorkflowActionPayload.carrier')<p class="validation-error">{{ $message }}</p>@enderror
                        @error('shipmentLabel')<p class="validation-error">{{ $message }}</p>@enderror
                    </label>
                    <label class="ft-prototype-field">
                        <span>Tracking number</span>
                        <input wire:model="orderWorkflowActionPayload.tracking_number" placeholder="Enter tracking number">
                        @error('orderWorkflowActionPayload.tracking_number')<p class="validation-error">{{ $message }}</p>@enderror
                    </label>
                </div>
                <div class="ft-prototype-email-preview">
                    <b>Shipment task:</b> Add tracking number &amp; print courier label<br>
                    <span>Saving the courier and tracking number completes Task 5.2 and unlocks Dispatch shipment.</span>
                </div>
            @elseif($variant === 'courier_label')
                <div class="ft-prototype-label-preview">
                    <div><small>SHIP TO</small><h3>{{ mb_strtoupper($clientName) }}</h3><p>{!! nl2br(e((string) ($payload['address'] ?? $job->shipping_address ?? ''))) !!}</p><div class="ft-prototype-barcode"></div><b>FLOWTRACK · {{ $orderNumber }}</b></div>
                    <div><small>SERVICE</small><h3>Courier<br>Shipping</h3><p><b>{{ $payload['packages'] ?? 'Packages confirmed' }}</b><br>{{ $payload['weight'] ?? '' }}</p><strong class="ft-prototype-label-code">SHIP</strong></div>
                </div>
            @elseif($variant === 'ship_package')
                <div class="ft-prototype-form-grid">
                    <label class="ft-prototype-field"><span>Shipping provider</span><select wire:model="orderWorkflowActionPayload.carrier"><option>UPS</option><option>FedEx</option><option>DHL</option><option>Other</option></select>@error('orderWorkflowActionPayload.carrier')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Tracking number</span><input wire:model="orderWorkflowActionPayload.tracking_number">@error('orderWorkflowActionPayload.tracking_number')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Shipment date</span><input type="date" wire:model="orderWorkflowActionPayload.shipment_date">@error('orderWorkflowActionPayload.shipment_date')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Estimated delivery</span><input type="date" wire:model="orderWorkflowActionPayload.estimated_delivery_date">@error('orderWorkflowActionPayload.estimated_delivery_date')<p class="validation-error">{{ $message }}</p>@enderror</label>
                </div>
            @elseif($variant === 'invoice_prepare')
                <div class="ft-prototype-form-grid">
                    <label class="ft-prototype-field"><span>Invoice number</span><input value="{{ $payload['invoice_number'] ?? '' }}" readonly aria-readonly="true" title="Generated automatically">@error('orderWorkflowActionPayload.invoice_number')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Invoice date</span><input type="date" wire:model="orderWorkflowActionPayload.invoice_date">@error('orderWorkflowActionPayload.invoice_date')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Amount</span><input type="number" step="0.01" wire:model="orderWorkflowActionPayload.invoice_amount">@error('orderWorkflowActionPayload.invoice_amount')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Currency</span><select wire:model="orderWorkflowActionPayload.invoice_currency"><option>USD</option><option>GBP</option><option>EUR</option></select>@error('orderWorkflowActionPayload.invoice_currency')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Payment terms</span><select wire:model="orderWorkflowActionPayload.payment_terms"><option>Net 15</option><option>Net 30</option><option>Due on receipt</option></select>@error('orderWorkflowActionPayload.payment_terms')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Due date</span><input type="date" wire:model="orderWorkflowActionPayload.invoice_due_date">@error('orderWorkflowActionPayload.invoice_due_date')<p class="validation-error">{{ $message }}</p>@enderror</label>
                </div>
                <div class="ft-prototype-email-preview">
                    <div><b>Included order:</b> {{ $orderNumber }}</div>
                    <div><b>Client:</b> {{ $clientName }}</div>
                    @if($workflowRemoteAreaCharge > 0)
                        <div><b>Remote area charge:</b> {{ $payload['invoice_currency'] ?? 'USD' }} {{ number_format($workflowRemoteAreaCharge, 2) }}</div>
                    @endif
                    <div><b>Total:</b> {{ $payload['invoice_currency'] ?? 'USD' }} {{ number_format((float) ($payload['invoice_amount'] ?? $orderTotal) + $workflowRemoteAreaCharge, 2) }}</div>
                </div>
            @elseif($variant === 'invoice_send')
                <div class="ft-invoice-send-workspace">
                    {{-- Recipient search is a live Livewire interaction. Keep the generated
                             invoice viewer outside morph updates so its iframe is not reloaded
                             on every To/CC keystroke (which caused the modal/PDF flash). --}}
                        <section
                            class="ft-invoice-send-document"
                            aria-label="Generated invoice"
                            wire:key="invoice-send-document-{{ $task->id }}-{{ (int) ($workflowInvoice['id'] ?? 0) }}"
                            wire:ignore
                        >
                        <header class="ft-invoice-send-document__head">
                            <div>
                                <small>GENERATED INVOICE</small>
                                <strong>{{ $workflowInvoice['invoice_number'] ?? ($payload['invoice_number'] ?? 'Invoice') }}</strong>
                                <span>This exact PDF will be attached to the client email.</span>
                            </div>
                            @if(!empty($workflowInvoice))
                                <div class="ft-invoice-send-document__actions">
                                    <a href="{{ $workflowInvoice['open_url'] ?? '#' }}" target="_blank" rel="noopener">Open invoice</a>
                                    <a href="{{ $workflowInvoice['download_url'] ?? '#' }}">Download PDF</a>
                                </div>
                            @endif
                        </header>

                        @if(!empty($workflowInvoice))
                            <div class="ft-invoice-send-document__summary">
                                <span><b>{{ $workflowInvoice['currency'] ?? ($payload['invoice_currency'] ?? 'USD') }} {{ number_format((float) ($workflowInvoice['total'] ?? 0), 2) }}</b><small>Amount due</small></span>
                                <span><b>{{ $workflowInvoice['issue_date_label'] ?? '—' }}</b><small>Invoice date</small></span>
                                <span><b>{{ $workflowInvoice['due_date_label'] ?? '—' }}</b><small>Due date</small></span>
                            </div>
                            <div class="ft-invoice-send-pdf-preview">
                                <div class="ft-invoice-send-pdf-preview__bar"><span>PDF</span><b>{{ $workflowInvoice['pdf_name'] ?? (($workflowInvoice['invoice_number'] ?? 'Invoice').'.pdf') }}</b></div>
                                <iframe title="Generated invoice PDF preview" src="{{ $workflowInvoice['open_url'] ?? '#' }}"></iframe>
                            </div>
                        @else
                            <div class="ft-order-email-preview-unavailable">The generated invoice PDF could not be found. Return to Prepare Invoice and generate it before sending.</div>
                        @endif
                    </section>

                    <section class="ft-invoice-send-compose" aria-label="Invoice email compose and preview">
                        <div class="ft-invoice-send-compose__title">
                            <small>EMAIL DELIVERY</small>
                            <strong>Review recipients and message</strong>
                            <span>Change the billing email if needed, then verify the exact message before sending.</span>
                        </div>

                        <section
                            class="ft-po-mail-recipients ft-invoice-mail-recipients"
                            aria-label="Invoice email recipients"
                            x-data="{ ccOpen: @js(filled($payload['cc_emails'] ?? '')) }"
                        >
                            <div class="ft-po-mail-row ft-po-mail-row--to">
                                <label for="invoice-to-email-{{ $task->id }}">To</label>
                                <div class="ft-po-mail-row__control ft-po-mail-recipient-control">
                                    <input
                                        id="invoice-to-email-{{ $task->id }}"
                                        type="text"
                                        wire:model.live.debounce.300ms="orderWorkflowActionPayload.to_email"
                                        placeholder="Enter email or search system users"
                                        autocomplete="off"
                                        spellcheck="false"
                                    >

                                    @if($invoiceToSuggestions->isNotEmpty())
                                        <div class="ft-po-mail-suggestions" role="listbox" aria-label="System user suggestions">
                                            @foreach($invoiceToSuggestions as $option)
                                                <button
                                                    type="button"
                                                    wire:key="invoice-to-suggestion-{{ $task->id }}-{{ (int) $option['id'] }}"
                                                    wire:click="$set('orderWorkflowActionPayload.to_email', @js((string) $option['email']))"
                                                    class="ft-po-mail-suggestion"
                                                >
                                                    <span class="ft-po-mail-suggestion__avatar">{{ mb_strtoupper(mb_substr((string) ($option['name'] ?? $option['email']), 0, 1)) }}</span>
                                                    <span><b>{{ $option['name'] }}</b><small>{{ $option['email'] }}</small></span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                                <button
                                    type="button"
                                    class="ft-po-mail-cc-toggle"
                                    x-on:click="ccOpen = !ccOpen"
                                    x-bind:aria-expanded="ccOpen.toString()"
                                    aria-controls="invoice-cc-fields-{{ $task->id }}"
                                >Cc</button>
                            </div>
                            @error('orderWorkflowActionPayload.to_email')<p class="validation-error ft-po-mail-validation">{{ $message }}</p>@enderror

                            @if($invoiceMatchedToUser)
                                <div class="ft-po-assignment-note ft-po-assignment-note--mail ft-invoice-system-user-note">
                                    <span aria-hidden="true">✓</span>
                                    <p><b>{{ $invoiceMatchedToUser['name'] }}</b> is an active FlowTrack user. The invoice will be sent to <strong>{{ $invoiceMatchedToUser['email'] }}</strong>.</p>
                                </div>
                            @elseif($invoiceToIsValidEmail)
                                <p class="ft-po-mail-help">This address does not match an active FlowTrack user. It will be sent as an external email recipient.</p>
                            @elseif($invoiceNoSystemMatch)
                                <p class="ft-po-mail-help ft-invoice-user-search-empty">No active system user matches “{{ $invoiceToEmail }}”. Choose a suggested user or enter a complete external email address.</p>
                            @else
                                <p class="ft-po-mail-help">Billing contact: {{ ($workflowInvoice['billing_contact_name'] ?? '') ?: $clientName }} · You can search active system users by name or email.</p>
                            @endif

                            <div id="invoice-cc-fields-{{ $task->id }}" class="ft-po-mail-cc" x-cloak x-show="ccOpen">
                                <div class="ft-po-mail-row ft-po-mail-row--cc">
                                    <label for="invoice-cc-emails-{{ $task->id }}">Cc</label>
                                    <div class="ft-po-mail-row__control ft-po-mail-recipient-control">
                                        <input
                                            id="invoice-cc-emails-{{ $task->id }}"
                                            type="text"
                                            wire:model.live.debounce.300ms="orderWorkflowActionPayload.cc_emails"
                                            placeholder="Add email or search system users"
                                            autocomplete="off"
                                            spellcheck="false"
                                        >

                                        @if($invoiceCcSuggestions->isNotEmpty())
                                            <div class="ft-po-mail-suggestions" role="listbox" aria-label="System user CC suggestions">
                                                @foreach($invoiceCcSuggestions as $option)
                                                    <button
                                                        type="button"
                                                        wire:key="invoice-cc-suggestion-{{ $task->id }}-{{ (int) $option['id'] }}"
                                                        wire:click="$set('orderWorkflowActionPayload.cc_emails', @js($invoiceCcSuggestionValues->get((int) $option['id'], '')))"
                                                        class="ft-po-mail-suggestion"
                                                    >
                                                        <span class="ft-po-mail-suggestion__avatar">{{ mb_strtoupper(mb_substr((string) ($option['name'] ?? $option['email']), 0, 1)) }}</span>
                                                        <span><b>{{ $option['name'] }}</b><small>{{ $option['email'] }}</small></span>
                                                    </button>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                @error('orderWorkflowActionPayload.cc_emails')<p class="validation-error ft-po-mail-validation">{{ $message }}</p>@enderror
                            </div>
                        </section>

                        <x-email.handoff-preview
                            :loading="$emailPreviewPending"
                            :preview="$invoiceEmailPreview"
                            :defaultSubject="'Invoice '.($payload['invoice_number'] ?? '').' — '.$orderNumber"
                            emptyRecipientText="Enter the client billing email in To before sending this invoice."
                        />
                        @error('orderWorkflowActionEmail')<p class="validation-error ft-invoice-send-email-error">{{ $message }}</p>@enderror
                    </section>
                </div>
            @elseif($variant === 'payment')
                <div class="ft-prototype-form-grid">
                    <label class="ft-prototype-field"><span>Outstanding balance</span><input value="{{ number_format((float) ($payload['payment_amount'] ?? $orderTotal), 2) }}" disabled></label>
                    <label class="ft-prototype-field"><span>Payment amount</span><input type="number" min="0.01" step="0.01" wire:model="orderWorkflowActionPayload.payment_amount">@error('orderWorkflowActionPayload.payment_amount')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Payment date</span><input type="date" wire:model="orderWorkflowActionPayload.payment_date">@error('orderWorkflowActionPayload.payment_date')<p class="validation-error">{{ $message }}</p>@enderror</label>
                    <label class="ft-prototype-field"><span>Payment reference</span><input wire:model="orderWorkflowActionPayload.payment_reference">@error('orderWorkflowActionPayload.payment_reference')<p class="validation-error">{{ $message }}</p>@enderror</label>
                </div>
                <label class="ft-prototype-field"><span>Notes</span><textarea wire:model="orderWorkflowActionPayload.payment_notes" rows="4" placeholder="Bank transfer received and matched to invoice..."></textarea>@error('orderWorkflowActionPayload.payment_notes')<p class="validation-error">{{ $message }}</p>@enderror</label>
            @else
                <div class="ft-order-task-document-target"><span class="ft-order-task-document-target-icon">⌘</span><div><small>WORKFLOW TASK</small><strong>{{ $task->title }}</strong><span>{{ $task->phase?->name ?? 'Order workflow' }}</span></div><em>{{ $task->status ?: 'Ready' }}</em></div>
            @endif

            @if($emailFallback && $emailServiceEnabled && in_array($variant, ['purchase_order_email', 'artwork_email'], true))
                <div class="ft-prototype-email-preview ft-prototype-email-preview--error" role="alert" aria-live="polite">
                    <b>Email delivery failed after {{ max(3, (int) $emailFallbackAttempts) }} attempts</b>
                    <span>{{ $emailFallbackMessage }}</span>
                    @if($emailFallbackDocument)
                        <div class="ft-prototype-artwork-actions">
                            <a href="{{ route('documents.download', $emailFallbackDocument) }}">Download {{ $emailFallbackAttachmentLabel }}</a>
                        </div>
                    @endif
                </div>
            @endif
        </div>

        @php
            // The three main choice dialogs render their actions inside the body.
            // Once one of those choices opens a nested revision/issue dialog, the
            // normal footer must return so the user can actually submit it.
            $usesInlineWorkflowActions = $step === 'main'
                && in_array($variant, ['client_decision','qc_check'], true);
            $usesShipmentFooter = $step === 'main' && $variant === 'shipment_info';
            $editingCompletedShipmentInformation = $usesShipmentFooter
                && \App\Support\OrderDetailPresenter::isCompletedTask($task);
        @endphp
        @if($usesShipmentFooter)
            <footer class="ft-order-task-document-modal-actions ft-shipment-modal-footer">
                <button type="button" class="ft-shipment-modal-reset" wire:click="resetShipmentActionDetails">Reset changes</button>
                <div class="ft-shipment-modal-footer__actions">
                    <div>
                        <button type="button" class="secondary" wire:click="closeOrderWorkflowAction" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">Cancel</button>
                        <button type="button" class="primary" wire:click="submitOrderWorkflowAction('confirm')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">{{ $editingCompletedShipmentInformation ? 'Save changes' : 'Save & complete task' }}</button>
                    </div>
                    <small>{{ $editingCompletedShipmentInformation ? 'The shipment task stays completed; only the latest shipment details are updated.' : 'Saving unlocks Add tracking number & print courier label.' }}</small>
                </div>
            </footer>
        @endif
        @unless($usesInlineWorkflowActions || $usesShipmentFooter || $step === 'sample')
            <footer class="ft-order-task-document-modal-actions ft-order-workflow-action-buttons">
                <button type="button" class="secondary" wire:click="closeOrderWorkflowAction" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">Cancel</button>
                @if($step === 'revision')
                    <button type="button" class="danger ft-artwork-revision-submit" wire:click="submitOrderWorkflowAction('revise')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction,orderWorkflowActionRevisionAttachments">{{ $automationKey === 'ART_INTERNAL_REVIEW' ? 'Submit Revision' : 'Activate Revision Task' }}</button>
                @elseif($step === 'cancel_artwork')
                    <button type="button" class="danger ft-artwork-cancellation-submit" wire:click="submitOrderWorkflowAction('cancel_artwork')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">Cancel Selected Artwork</button>
                @elseif($step === 'issue')
                    <button type="button" class="danger" wire:click="submitOrderWorkflowAction('issue')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">Report Issue</button>
                @elseif($emailFallback && $emailServiceEnabled && in_array($variant, ['purchase_order_email', 'artwork_email'], true))
                    <button type="button" class="secondary" wire:click="submitOrderWorkflowAction('confirm')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction">Try email again</button>
                    <button type="button" class="primary" wire:click="completeOrderWorkflowEmailTaskAfterFailure" wire:loading.attr="disabled" wire:target="completeOrderWorkflowEmailTaskAfterFailure">Complete task</button>
                @else
                    @foreach($choices as $decision => $label)
                        @continue($decision === 'cancel_artwork' && $latestArtworkDocuments->count() <= 1)
                        @php
                            $actionLabel = ! $emailServiceEnabled && in_array($variant, ['purchase_order_email', 'artwork_email', 'invoice_send'], true)
                                ? 'Complete without email'
                                : $label;
                            $actionDisabled = $variant === 'invoice_send' && empty($workflowInvoice);
                        @endphp
                        <button type="button" class="{{ in_array($decision, ['revise','cancel_artwork','issue'], true) ? 'danger' : 'primary' }}" wire:click="submitOrderWorkflowAction('{{ $decision }}')" wire:loading.attr="disabled" wire:target="submitOrderWorkflowAction" @disabled($actionDisabled)>{{ $actionLabel }}</button>
                    @endforeach
                @endif
            </footer>
        @endunless
    </section>
</div>
