@props(['job', 'task', 'availableDocuments' => collect(), 'source' => 'upload', 'upload' => null, 'revisionUpload' => null, 'stagedUploads' => [], 'stagedRevisionUploads' => [], 'existingDocumentId' => null, 'artworkRevision' => [], 'revisionDocumentIds' => [], 'context' => []])
@php
    $canUpload = (bool) ($context['canUploadDocument'] ?? false);
    $canLink = (bool) ($context['canLinkDocument'] ?? false);
    $workflowActions = app(\App\Services\OrderWorkflowActionService::class);
    $automationKey = $workflowActions->automationKey($task);
    $prototypeUpload = in_array($automationKey, ['NEW_UPLOAD_PO', 'ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true);
    $chunkedArtworkUpload = in_array($automationKey, ['ART_PREPARE_UPLOAD', 'ART_SAMPLE_APPROVAL'], true);
    $allowMultipleUploads = $chunkedArtworkUpload
        || $automationKey === 'NEW_UPLOAD_PO'
        || (bool) ($task->setupTemplate?->allow_multiple_documents ?? false);
    $hasExistingEvidence = $task->relationLoaded('documents') ? $task->documents->isNotEmpty() : false;
    $artworkRevisionActive = $automationKey === 'ART_PREPARE_UPLOAD' && (bool) ($artworkRevision['active'] ?? false);
    $allRevisionCandidates = $artworkRevisionActive
        ? collect($artworkRevision['documents'] ?? [])->merge(collect($artworkRevision['retained_documents'] ?? []))->unique('id')->sortBy('id')->values()
        : collect();
    $selectedRevisionIds = collect($revisionDocumentIds ?: ($artworkRevision['document_ids'] ?? []))
        ->map(fn($id) => (int) $id)
        ->filter(fn($id) => $id > 0)
        ->unique()
        ->values();
    $revisionDocuments = $artworkRevisionActive
        ? $allRevisionCandidates->filter(fn($document) => $selectedRevisionIds->contains((int) $document->id))->values()
        : collect();
    $retainedArtworkDocuments = $artworkRevisionActive
        ? $allRevisionCandidates->reject(fn($document) => $selectedRevisionIds->contains((int) $document->id))->values()
        : collect();
    $revisionCount = $revisionDocuments->count();
    // Normal Artwork uploads can still be multi-file. Selective revision replacements
    // are intentionally chosen one-at-a-time under the exact source artwork.
    $inputAllowsMultiple = $allowMultipleUploads;
    $uploadCopyPlural = $allowMultipleUploads;
    $revisionItemsByDocumentId = collect($artworkRevision['items'] ?? [])->mapWithKeys(function ($item) {
        $id = (int) data_get($item, 'document_id', 0);
        return $id > 0 ? [$id => (array) $item] : [];
    });

    $prototypeConfig = match ($automationKey) {
        'NEW_UPLOAD_PO' => [
            'title' => $hasExistingEvidence ? 'Add other documents' : 'Upload Purchase Order',
            'label' => $hasExistingEvidence ? 'Other purchase order documents' : 'Purchase order documents',
            'copy' => $hasExistingEvidence
                ? 'Add more documents to the completed Purchase Order task.'
                : 'Upload the client purchase order and any supporting documents to begin processing this Order.',
            'hint' => \App\Support\AttachmentUpload::helperText(20).' · Up to 10 files',
            'button' => $hasExistingEvidence ? 'Add other documents' : 'Upload Purchase Order',
        ],
        'ART_PREPARE_UPLOAD' => [
            'title' => $artworkRevisionActive ? 'Upload Revised Artwork' : ($hasExistingEvidence ? 'Upload Revised Artwork' : 'Upload Artwork'),
            'label' => $artworkRevisionActive ? 'Replacement artwork files' : ($hasExistingEvidence ? 'Revised artwork files' : 'Artwork files'),
            'copy' => $artworkRevisionActive
                ? ($revisionCount > 0
                    ? 'Upload each replacement directly under the artwork it replaces. '.$revisionCount.' artwork file'.($revisionCount === 1 ? ' is' : 's are').' waiting for replacement. Unselected artwork remains unchanged automatically.'
                    : 'Upload one replacement directly under each artwork selected in the revision request.')
                : ($hasExistingEvidence
                    ? 'Upload up to 50 corrected artwork files as one revision. The previous version remains in Order history.'
                    : 'Upload up to 50 artwork files together for internal review.'),
            'hint' => $artworkRevisionActive
                ? \App\Support\AttachmentUpload::helperText(400).' · '.$revisionCount.' replacement'.($revisionCount === 1 ? '' : 's').' required'
                : \App\Support\AttachmentUpload::helperText(400).' · Up to 50 files',
            'button' => $artworkRevisionActive ? 'Upload Revised Artwork' : ($hasExistingEvidence ? 'Upload Revised Artwork' : 'Upload Artwork'),
        ],
        'ART_SAMPLE_APPROVAL' => [
            'title' => 'Upload Sample Approval',
            'label' => 'Signed sample approval',
            'copy' => 'Attach the client sample/swatch approval to continue to Production.',
            'hint' => \App\Support\AttachmentUpload::helperText(400).' · Up to 50 files',
            'button' => 'Upload Sample Approval',
        ],
        default => [
            'title' => 'Add document to task',
            'label' => 'Task document',
            'copy' => 'Upload a new file or link an existing client document.',
            'hint' => \App\Support\AttachmentUpload::helperText(20),
            'button' => 'Add document',
        ],
    };
    $effectiveUpload = $artworkRevisionActive ? $revisionUpload : $upload;
    if ($chunkedArtworkUpload) {
        $selectedUploads = $artworkRevisionActive
            ? collect($stagedRevisionUploads)->filter()
            : collect($stagedUploads)->filter()->values();
        $selectedUploadCount = $selectedUploads->count();
        $selectedUploadDetails = $artworkRevisionActive ? collect() : $selectedUploads->map(function ($file) {
            $name = (string) data_get($file, 'name', 'Artwork file');
            $size = (int) data_get($file, 'size', 0);
            return [
                'name' => $name,
                'type' => (string) (data_get($file, 'type') ?: (strtoupper((string) pathinfo($name, PATHINFO_EXTENSION)) ?: 'FILE')),
                'size' => $size >= 1048576
                    ? number_format($size / 1048576, 1).' MB'
                    : number_format(max(1, (int) ceil($size / 1024))).' KB',
            ];
        });
    } else {
        $selectedUploads = collect(is_array($effectiveUpload) ? $effectiveUpload : ($effectiveUpload ? [$effectiveUpload] : []))->filter()->values();
        $selectedUploadCount = $selectedUploads->count();
        $selectedUploadDetails = $selectedUploads->map(function ($file) {
            $name = $file->getClientOriginalName();

            return [
                'name' => $name,
                'type' => strtoupper((string) pathinfo($name, PATHINFO_EXTENSION)) ?: 'FILE',
                'size' => $file->getSize() >= 1048576
                    ? number_format($file->getSize() / 1048576, 1).' MB'
                    : number_format(max(1, (int) ceil($file->getSize() / 1024))).' KB',
            ];
        });
    }
@endphp
<div class="ft-order-task-document-modal-backdrop" wire:key="order-task-document-modal-{{ $task->id }}" wire:click.self="closeOverviewTaskDocumentModal">
    <section class="ft-order-task-document-modal ft-order-attachment-upload-modal {{ $prototypeUpload ? 'ft-order-prototype-upload-modal' : '' }} {{ $artworkRevisionActive ? 'ft-order-prototype-upload-modal--artwork-revision' : '' }}" data-ft-feedback-scope="form" data-artwork-upload-modal-task="{{ $task->id }}" role="dialog" aria-modal="true" aria-labelledby="order-task-document-modal-title">
        <header class="ft-order-task-document-modal-head">
            <div>
                <h2 id="order-task-document-modal-title">{{ $prototypeConfig['title'] }}</h2>
                <p>{{ $prototypeConfig['copy'] }}</p>
            </div>
            <button type="button" wire:click="closeOverviewTaskDocumentModal" aria-label="Close">×</button>
        </header>
        <div class="ft-order-task-document-modal-body">
            @unless($prototypeUpload)
                <div class="ft-order-task-document-target"><span class="ft-order-task-document-target-icon">▣</span><div><small>ATTACHING TO</small><strong>{{ $task->title }}</strong><span>{{ $task->task_number ?: 'TASK-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT) }} · {{ $task->phase?->name ?? 'Order Taskflow' }}</span><span><b>Order Reference:</b> {{ $job->order_number ?: '—' }}</span></div><em>Task selected</em></div>
            @endunless

            @if(!$prototypeUpload || !$canUpload)
                <div class="ft-order-task-document-source-tabs">
                    <button type="button" class="{{ $source === 'upload' ? 'active' : '' }}" wire:click="setOverviewTaskDocumentSource('upload')" @disabled(!$canUpload)>Upload new</button>
                    <button type="button" class="{{ $source === 'existing' ? 'active' : '' }}" wire:click="setOverviewTaskDocumentSource('existing')" @disabled(!$canLink)>Choose existing</button>
                </div>
            @endif

            @if($source === 'upload' && $canUpload)
                {{-- CHANGED 2026-08-24: local Livewire upload progress feedback. --}}
                <div
                    class="ft-order-popup-field"
                    x-data="{ uploading: false, progress: 0 }"
                    x-on:livewire-upload-start="uploading = true; progress = 0"
                    x-on:livewire-upload-progress="progress = $event.detail.progress"
                    x-on:livewire-upload-finish="progress = 100; window.setTimeout(() => { uploading = false; progress = 0 }, 250)"
                    x-on:livewire-upload-error="uploading = false; progress = 0"
                    x-on:livewire-upload-cancel="uploading = false; progress = 0"
                    x-on:flowtrack-artwork-upload-start="uploading = true; progress = 0"
                    x-on:flowtrack-artwork-upload-progress="progress = Math.max(0, Math.min(100, Number($event.detail.progress) || 0))"
                    x-on:flowtrack-artwork-upload-finish="progress = 100; window.setTimeout(() => { uploading = false; progress = 0 }, 250)"
                    x-on:flowtrack-artwork-upload-error="uploading = false; progress = 0"
                >
                    @if($artworkRevisionActive)
                        <div class="ft-artwork-revision-upload-plan">
                            <div class="ft-artwork-revision-upload-plan-head">
                                <div>
                                    <strong>Artwork selected for revision</strong>
                                    <span>Upload one replacement under each artwork below. Files not listed here remain unchanged.</span>
                                </div>
                                <em>{{ $selectedUploadCount }} / {{ $revisionCount }} ready</em>
                            </div>

                            <div class="ft-artwork-revision-replacement-list">
                                @foreach($revisionDocuments as $revisionCandidate)
                                    @php
                                        $revisionDocumentId = (int) $revisionCandidate->id;
                                        $candidateExtension = strtoupper(pathinfo((string) $revisionCandidate->name, PATHINFO_EXTENSION) ?: 'FILE');
                                        $revisionItem = (array) ($revisionItemsByDocumentId[$revisionDocumentId] ?? []);
                                        $revisionInstruction = trim((string) data_get($revisionItem, 'comment', ''));
                                        $replacementFile = $revisionUpload[$revisionDocumentId] ?? $revisionUpload[(string) $revisionDocumentId] ?? null;
                                        $stagedReplacement = $stagedRevisionUploads[$revisionDocumentId] ?? $stagedRevisionUploads[(string) $revisionDocumentId] ?? null;
                                        $replacementDetail = null;
                                        if ($stagedReplacement) {
                                            $replacementName = (string) data_get($stagedReplacement, 'name', 'Artwork file');
                                            $replacementSize = (int) data_get($stagedReplacement, 'size', 0);
                                            $replacementDetail = [
                                                'name' => $replacementName,
                                                'type' => (string) (data_get($stagedReplacement, 'type') ?: (strtoupper((string) pathinfo($replacementName, PATHINFO_EXTENSION)) ?: 'FILE')),
                                                'size' => $replacementSize >= 1048576
                                                    ? number_format($replacementSize / 1048576, 1).' MB'
                                                    : number_format(max(1, (int) ceil($replacementSize / 1024))).' KB',
                                            ];
                                        } elseif ($replacementFile) {
                                            $replacementName = $replacementFile->getClientOriginalName();
                                            $replacementDetail = [
                                                'name' => $replacementName,
                                                'type' => strtoupper((string) pathinfo($replacementName, PATHINFO_EXTENSION)) ?: 'FILE',
                                                'size' => $replacementFile->getSize() >= 1048576
                                                    ? number_format($replacementFile->getSize() / 1048576, 1).' MB'
                                                    : number_format(max(1, (int) ceil($replacementFile->getSize() / 1024))).' KB',
                                            ];
                                        }
                                    @endphp
                                    <div
                                        class="ft-artwork-revision-replacement-item {{ $replacementDetail ? 'has-replacement' : '' }}"
                                        wire:key="artwork-revision-replacement-{{ $task->id }}-{{ $revisionDocumentId }}"
                                        x-data="{ uploadingReplacement: false, replacementProgress: 0 }"
                                        x-on:livewire-upload-start="uploadingReplacement = true; replacementProgress = 0"
                                        x-on:livewire-upload-progress="replacementProgress = Math.max(0, Math.min(100, Number($event.detail.progress) || 0))"
                                        x-on:livewire-upload-finish="replacementProgress = 100; window.setTimeout(() => { uploadingReplacement = false; replacementProgress = 0 }, 250)"
                                        x-on:livewire-upload-error="uploadingReplacement = false; replacementProgress = 0"
                                        x-on:livewire-upload-cancel="uploadingReplacement = false; replacementProgress = 0"
                                        x-on:flowtrack-artwork-upload-start="uploadingReplacement = true; replacementProgress = 0"
                                        x-on:flowtrack-artwork-upload-progress="replacementProgress = Math.max(0, Math.min(100, Number($event.detail.progress) || 0))"
                                        x-on:flowtrack-artwork-upload-finish="replacementProgress = 100; window.setTimeout(() => { uploadingReplacement = false; replacementProgress = 0 }, 250)"
                                        x-on:flowtrack-artwork-upload-error="uploadingReplacement = false; replacementProgress = 0"
                                    >
                                        <div class="ft-artwork-revision-replacement-summary">
                                            <div class="ft-artwork-revision-replacement-source">
                                                <span class="ft-artwork-revision-selector-check is-checked" aria-hidden="true">✓</span>
                                                <x-ui.file-type-badge :extension="$candidateExtension" size="sm" />
                                                <span class="ft-artwork-revision-upload-selector-copy">
                                                    <b title="{{ $revisionCandidate->name }}">{{ $revisionCandidate->name }}</b>
                                                    <small>{{ $candidateExtension }} · Current artwork</small>
                                                </span>
                                            </div>
                                            <div class="ft-artwork-revision-replacement-actions">
                                                @if($replacementDetail)
                                                    <span>Ready</span>
                                                @endif
                                                <a href="{{ route('documents.open', $revisionCandidate) }}" target="_blank" rel="noopener">Open</a>
                                            </div>
                                        </div>

                                        <div class="ft-artwork-revision-replacement-details">
                                            @if($revisionInstruction !== '')
                                                <div class="ft-artwork-revision-replacement-change">
                                                    <strong>Required change</strong>
                                                    <p>{{ $revisionInstruction }}</p>
                                                </div>
                                            @endif

                                            <div class="ft-artwork-revision-replacement-upload">
                                                <div class="ft-artwork-revision-replacement-upload-head">
                                                    <div>
                                                        <strong>Replacement artwork <b>*</b></strong>
                                                        <small>Upload the corrected file for this artwork only.</small>
                                                    </div>
                                                </div>

                                                @if($replacementDetail)
                                                    <div class="ft-order-attachment-selected-file ft-artwork-revision-replacement-selected-file">
                                                        <x-ui.file-type-badge :extension="$replacementDetail['type']" size="sm" />
                                                        <span class="ft-order-attachment-selected-copy">
                                                            <strong title="{{ $replacementDetail['name'] }}">{{ $replacementDetail['name'] }}</strong>
                                                            <small>{{ $replacementDetail['type'] }} · {{ $replacementDetail['size'] }} · Ready to replace this artwork</small>
                                                        </span>
                                                        <button
                                                            type="button"
                                                            wire:click="removeOverviewTaskDocumentUpload({{ $revisionDocumentId }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="overviewTaskRevisionUpload.{{ $revisionDocumentId }},removeOverviewTaskDocumentUpload({{ $revisionDocumentId }})"
                                                        >Remove</button>
                                                    </div>
                                                @endif

                                                <label class="ft-order-task-document-dropzone ft-order-attachment-dropzone ft-artwork-revision-replacement-dropzone {{ $replacementDetail ? 'is-compact' : '' }}" data-file-dropzone>
                                                    <input
                                                        type="file"
                                                        data-artwork-chunk-input
                                                        data-artwork-upload-start-url="{{ route('orders.artwork-uploads.start', [], false) }}"
                                                        data-artwork-task-id="{{ $task->id }}"
                                                        data-revision-document-id="{{ $revisionDocumentId }}"
                                                        accept="{{ \App\Support\AttachmentUpload::accept() }}"
                                                        aria-label="Choose replacement artwork for {{ $revisionCandidate->name }}"
                                                        title="Choose replacement file"
                                                    >
                                                    <svg class="ft-order-attachment-upload-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 16l-4-4-4 4M12 12v9M20.4 17.5A5 5 0 0 0 18 8.2 7 7 0 0 0 4.3 10.8 4.5 4.5 0 0 0 5.5 19H7"/></svg>
                                                    @if($replacementDetail)
                                                        <strong>Choose a different replacement</strong>
                                                        <b>Drag &amp; drop or <span>browse</span></b>
                                                    @else
                                                        <strong>Drag &amp; drop a file here</strong>
                                                        <b>or choose from your computer</b>
                                                        <span class="ft-order-attachment-browse">Browse file</span>
                                                    @endif
                                                    <small data-drop-status>{{ \App\Support\AttachmentUpload::helperText(400) }}</small>
                                                </label>

                                                <div
                                                    class="ft-prototype-upload-progress ft-artwork-revision-replacement-uploading"
                                                    x-cloak
                                                    x-show="uploadingReplacement"
                                                    x-transition.opacity.duration.120ms
                                                >
                                                    <div class="ft-prototype-upload-progress-meta">
                                                        <span>Uploading replacement...</span>
                                                        <b x-text="`${replacementProgress}%`">0%</b>
                                                    </div>
                                                    <div
                                                        class="ft-prototype-upload-progress-track"
                                                        role="progressbar"
                                                        aria-label="Replacement artwork upload progress"
                                                        aria-valuemin="0"
                                                        aria-valuemax="100"
                                                        x-bind:aria-valuenow="replacementProgress"
                                                    >
                                                        <span x-bind:style="`width: ${replacementProgress}%`"></span>
                                                    </div>
                                                </div>
                                                @error('overviewTaskRevisionUpload.'.$revisionDocumentId)<p class="validation-error">{{ $message }}</p>@enderror
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            @error('overviewTaskRevisionDocumentIds')<p class="validation-error">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    @unless($artworkRevisionActive)
                    @if($selectedUploads->isNotEmpty())
                        <div class="ft-order-attachment-selected-count">{{ $selectedUploadCount }} file{{ $selectedUploadCount === 1 ? '' : 's' }} selected{{ $artworkRevisionActive ? ' · '.$selectedUploadCount.' of '.$revisionCount.' replacements' : ($automationKey === 'ART_PREPARE_UPLOAD' ? ' · One artwork version' : '') }}</div>
                        @foreach($selectedUploadDetails as $index => $selectedUpload)
                            <div class="ft-order-attachment-selected-file" wire:key="overview-task-upload-{{ $task->id }}-{{ $index }}-{{ md5($selectedUpload['name']) }}">
                                @if($artworkRevisionActive)
                                    <x-ui.file-type-badge :extension="$selectedUpload['type']" size="sm" />
                                @else
                                    <span class="ft-order-attachment-selected-check" aria-hidden="true">✓</span>
                                @endif
                                <span class="ft-order-attachment-selected-copy">
                                    <strong class="{{ $prototypeUpload ? 'ft-prototype-selected-file-name' : '' }}" title="{{ $selectedUpload['name'] }}">{{ $selectedUpload['name'] }}</strong>
                                    <small>{{ $selectedUpload['type'] }} · {{ $selectedUpload['size'] }} · Uploaded · Ready to save</small>
                                </span>
                                <button type="button" wire:click="removeOverviewTaskDocumentUpload({{ $index }})" wire:loading.attr="disabled" wire:target="overviewTaskDocumentUpload,overviewTaskRevisionUpload,removeOverviewTaskDocumentUpload({{ $index }})">Remove</button>
                            </div>
                        @endforeach
                    @else
                        <div class="{{ $prototypeUpload ? 'ft-prototype-upload-label' : 'ft-order-attachment-field-label' }}">{{ $prototypeUpload ? $prototypeConfig['label'] : 'File attachment' }}</div>
                    @endif

                    <label class="ft-order-task-document-dropzone ft-order-attachment-dropzone {{ $selectedUploads->isNotEmpty() ? 'is-compact' : '' }}">
                        @if($chunkedArtworkUpload)
                            <input
                                type="file"
                                data-artwork-chunk-input
                                data-artwork-upload-start-url="{{ route('orders.artwork-uploads.start', [], false) }}"
                                data-artwork-task-id="{{ $task->id }}"
                                data-artwork-current-count="{{ $selectedUploadCount }}"
                                @if($inputAllowsMultiple) multiple @endif
                                accept="{{ \App\Support\AttachmentUpload::accept() }}"
                                aria-label="{{ $uploadCopyPlural ? 'Choose files to upload' : 'Choose a file to upload' }}"
                                title="{{ $uploadCopyPlural ? 'Choose files' : 'Choose file' }}"
                            >
                        @else
                            <input type="file" wire:model="overviewTaskDocumentUpload" @if($inputAllowsMultiple) multiple @endif accept="{{ \App\Support\AttachmentUpload::accept() }}" aria-label="{{ $uploadCopyPlural ? 'Choose files to upload' : 'Choose a file to upload' }}" title="{{ $uploadCopyPlural ? 'Choose files' : 'Choose file' }}">
                        @endif
                        <svg class="ft-order-attachment-upload-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M16 16l-4-4-4 4M12 12v9M20.4 17.5A5 5 0 0 0 18 8.2 7 7 0 0 0 4.3 10.8 4.5 4.5 0 0 0 5.5 19H7"/></svg>
                        @if($selectedUploads->isNotEmpty())
                            <strong>{{ $uploadCopyPlural ? 'Choose a different file set' : 'Choose a different file' }}</strong>
                            <b>Drag &amp; drop or <span>browse</span></b>
                        @else
                            <strong>Drag &amp; drop {{ $uploadCopyPlural ? 'files' : 'a file' }} here</strong>
                            <b>or choose from your computer{{ $uploadCopyPlural ? ' (use Shift/Ctrl/Cmd to select several)' : '' }}</b>
                            <span class="ft-order-attachment-browse">Browse file{{ $uploadCopyPlural ? 's' : '' }}</span>
                        @endif
                        <small>{{ $prototypeConfig['hint'] }}</small>
                    </label>

                    {{-- Shows progress for legacy Livewire uploads and Artwork chunk transport. --}}
                    <div
                        class="ft-prototype-upload-progress"
                        x-cloak
                        x-show="uploading"
                        x-transition.opacity.duration.120ms
                    >
                        <div class="ft-prototype-upload-progress-meta">
                            <span>Uploading {{ $uploadCopyPlural ? 'files' : 'file' }}...</span>

                            <b x-text="`${progress}%`">
                                0%
                            </b>
                        </div>

                        <div
                            class="ft-prototype-upload-progress-track"
                            role="progressbar"
                            aria-label="File upload progress"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            x-bind:aria-valuenow="progress"
                        >
                            <span
                                x-bind:style="`width: ${progress}%`"
                            ></span>
                        </div>
                    </div>

                    @endunless
                    @error('overviewTaskDocumentUpload')
                        <p class="validation-error">
                            {{ $message }}
                        </p>
                    @enderror
                    @error('overviewTaskDocumentUpload.*')
                        <p class="validation-error">
                            {{ $message }}
                        </p>
                    @enderror
                    @error('overviewTaskRevisionUpload')
                        <p class="validation-error">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            @endif

            @unless($prototypeUpload)
                <label class="ft-order-task-document-note"><span>Document note (optional)</span><input type="text" wire:model="overviewTaskDocumentNote" placeholder="Add a short note...">@error('overviewTaskDocumentNote')<p class="validation-error">{{ $message }}</p>@enderror</label>
                <div class="ft-order-task-document-info">This document remains linked to this task and is available from the task and Documents archive.</div>
            @else
                <div class="ft-prototype-upload-meta"><span>Task</span><b>{{ $task->title }}</b><span>Order</span><b>{{ $job->displayOrderNumber() }}</b></div>
            @endunless
        </div>
        <footer class="ft-order-task-document-modal-actions">
            <button type="button" class="secondary" wire:click="closeOverviewTaskDocumentModal">Cancel</button>
            <button
                type="button"
                class="primary"
                data-artwork-upload-save
                data-server-disabled="{{ ($source === 'upload' ? ($artworkRevisionActive ? ($revisionCount < 1 || $selectedUploadCount !== $revisionCount) : $selectedUploads->isEmpty()) : !$existingDocumentId) ? '1' : '0' }}"
                wire:click="saveOverviewTaskDocument"
                wire:loading.attr="disabled"
                wire:target="saveOverviewTaskDocument,overviewTaskDocumentUpload,overviewTaskRevisionUpload"
                @disabled($source === 'upload' ? ($artworkRevisionActive ? ($revisionCount < 1 || $selectedUploadCount !== $revisionCount) : $selectedUploads->isEmpty()) : !$existingDocumentId)
            >
                <span
                    wire:loading.remove
                    wire:target="saveOverviewTaskDocument"
                >
                    {{
                        $prototypeUpload
                            ? $prototypeConfig['button']
                            : ($selectedUploadCount > 0 ? 'Add '.$selectedUploadCount.' document'.($selectedUploadCount === 1 ? '' : 's') : 'Add document')
                    }}
                </span>

                <span
                    wire:loading
                    wire:target="saveOverviewTaskDocument"
                >
                    Saving...
                </span>
            </button>
        </footer>
    </section>
</div>
