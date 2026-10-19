@props(['job', 'context' => [], 'jobDocumentUploads' => []])
@php
    $canUpload = (bool) ($context['canUploadDocument'] ?? false);
    $canDelete = (bool) ($context['canDeleteDocument'] ?? false);
    $canExport = (bool) ($context['canExportDocument'] ?? false);
    // Task evidence (Purchase Order, Artwork, Sample Approval, etc.) belongs
    // against its workflow task. This section is intentionally order-level only.
    $otherDocuments = $job->documents
        ->filter(fn ($document) => blank($document->task_id))
        ->sortByDesc('created_at')
        ->values();
@endphp
<section class="section-card attachments-card ft-order-section-card ft-order-attachments-card" data-order-disclosure>
    <div class="section-head ft-order-section-head">
        <h2>Other document <span class="status-pill">{{ $otherDocuments->count() }}</span></h2>
        <button type="button" class="section-toggle ft-order-disclosure-button ft-order-compact-toggle" data-order-disclosure-trigger aria-expanded="true">Hide files</button>
    </div>
    <div class="collapse-body ft-order-disclosure-body" data-order-disclosure-body>
        @if($canUpload)
            <div class="attachment-drop ft-order-attachment-drop {{ $errors->has('jobDocumentUploads') || $errors->has('jobDocumentUploads.*') ? 'has-error' : '' }}">
                <label data-file-dropzone data-auto-upload-method="uploadGeneralOrderDocuments" for="orderGeneralAttachment-{{ $job->id }}"><b>⌕ &nbsp; Drop files here or <span>browse</span></b><div class="card-sub">{{ \App\Support\AttachmentUpload::helperText(20) }}</div><input id="orderGeneralAttachment-{{ $job->id }}" type="file" wire:model="jobDocumentUploads" multiple accept="{{ \App\Support\AttachmentUpload::accept() }}"></label>
            </div>
            @error('jobDocumentUploads')<div class="validation-error ft-order-upload-error">{{ $message }} <button type="button" wire:click="clearJobDocumentUploads">Clear</button></div>@enderror
            @if(count($jobDocumentUploads ?? []))
                <div class="ft-order-pending-files">
                    @foreach($jobDocumentUploads as $index => $upload)
                        @php($name = method_exists($upload, 'getClientOriginalName') ? $upload->getClientOriginalName() : 'File '.($index + 1))
                        <div wire:key="order-pending-file-{{ $index }}-{{ md5($name) }}"><span>{{ $name }}</span><button type="button" wire:click="removeJobDocumentUpload({{ $index }})">Remove</button></div>
                    @endforeach
                </div>
            @endif
        @else
            <div class="attachment-drop readonly"><div><b>⌕ &nbsp; Other documents</b><div class="card-sub">You have read-only access.</div></div></div>
        @endif

        <div class="attachment-list ft-order-file-list">
            @forelse($otherDocuments as $document)
                <div class="attachment-row" wire:key="order-document-{{ $document->id }}">
                    <div class="file-icon ft-order-file-icon">{{ strtoupper(pathinfo($document->name, PATHINFO_EXTENSION) ?: 'FILE') }}</div>
                    <div style="flex:1"><b>{{ $document->name }}</b><div class="card-sub">{{ $document->task?->title ?: 'Order document' }} · {{ $document->uploader?->name ?? 'FlowTrack' }} · {{ \App\Support\UserLocalTime::format($document->created_at, 'M j, Y, g:i A') }}</div></div>
                    <a class="btn small" href="{{ route('documents.open', $document) }}" target="_blank" rel="noopener">Open</a>
                    @if($canExport)<a class="btn small" href="{{ route('documents.download', $document) }}">Download</a>@endif
                    @if($canDelete)<button type="button" class="btn danger small" wire:click="deleteJobDocument({{ $document->id }})" wire:confirm="Delete this document link?">×</button>@endif
                </div>
            @empty
                <div class="empty-stage ft-order-empty-state">No other documents yet.</div>
            @endforelse
        </div>
    </div>
</section>
