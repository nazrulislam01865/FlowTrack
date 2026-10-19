<section class="ft-detail-card ft-attachment-card">
    <h2>Attachments <span>{{ $inquiry->documents_count }}</span></h2>
    <div class="ft-upload-zone compact ft-task-upload-zone">
        @if($canEditInquiry && $canCreateDocuments)
            <label class="ft-task-upload-drop ft-livewire-upload-zone" data-file-dropzone data-auto-upload-method="uploadInquiryFiles" for="inquiryOverviewUpload-{{ $inquiry->id }}">
                <input id="inquiryOverviewUpload-{{ $inquiry->id }}" type="file" wire:model="inquiryUploads" multiple accept="{{ \App\Support\AttachmentUpload::accept() }}">
                <span class="ft-paperclip">⌕</span>
                <div>Drop files here or <strong>browse</strong><small data-drop-status>{{ \App\Support\AttachmentUpload::helperText(20) }}</small></div>
            </label>
        @elseif(!$canEditInquiry || !$canCreateDocuments)
            <div class="ft-task-upload-drop ft-task-upload-readonly"><span class="ft-paperclip">⌕</span><div>Attachments<small>You have read-only access to Inquiry attachments.</small></div></div>
        @endif
    </div>

    @if(count($inquiryUploads ?? []))
        <div class="ft-upload-ready-row ft-auto-upload-state" aria-live="polite">
            <span>Uploading and linking {{ count($inquiryUploads ?? []) }} file{{ count($inquiryUploads ?? []) === 1 ? '' : 's' }} automatically…</span>
        </div>
    @endif
    @error('inquiryUploads')<div class="validation-error">{{ $message }}</div>@enderror
    @error('inquiryUploads.*')<div class="validation-error">{{ $message }}</div>@enderror

    @if($inquiryDocuments)
        @foreach($inquiryDocuments as $document)
            <div class="ft-attachment-row ft-inquiry-attachment-row" wire:key="inquiry-overview-doc-{{ $document->id }}">
                <span class="ft-file-type">{{ strtoupper(pathinfo($document->name, PATHINFO_EXTENSION) ?: 'FILE') }}</span>
                <div class="ft-inquiry-attachment-main">
                    <b title="{{ $document->name }}">{{ $document->name }}</b>
                    <small>{{ $document->created_at ? \App\Support\UserLocalTime::format($document->created_at, 'M j, Y, g:i A') : '—' }}</small>
                </div>
                <div class="ft-inquiry-attachment-actions">
                    <a class="ft-link-blue ft-inquiry-attachment-open" href="{{ route('inquiries.documents.open', $document) }}" target="_blank" rel="noopener">Open</a>
                    @if($canExportDocuments)<a class="ft-link-blue ft-inquiry-attachment-download" href="{{ route('inquiries.documents.download', $document) }}">Download</a>@endif
                    @if($canEditInquiry && $canDeleteDocuments)
                        <button type="button" class="ft-doc-delete-button" wire:click="deleteInquiryDocument({{ $document->id }})" wire:confirm="Remove this attachment from the Inquiry?" aria-label="Remove {{ $document->name }}">×</button>
                    @endif
                </div>
            </div>
        @endforeach
        @if($inquiryDocuments->isEmpty())<div class="empty-state">No Inquiry attachments yet.</div>@endif
        @if($inquiryDocuments->lastPage() > 1)
            <div class="ft-activity-pagination">
                <span>Showing {{ $inquiryDocuments->firstItem() ?? 0 }}–{{ $inquiryDocuments->lastItem() ?? 0 }} of {{ $inquiryDocuments->total() }}</span>
                <div><button type="button" wire:click="previousPage('inquiryDocumentsPage')" @disabled($inquiryDocuments->onFirstPage())>Previous</button><span>Page {{ $inquiryDocuments->currentPage() }} of {{ $inquiryDocuments->lastPage() }}</span><button type="button" wire:click="nextPage('inquiryDocumentsPage')" @disabled(!$inquiryDocuments->hasMorePages())>Next</button></div>
            </div>
        @endif
    @endif
    <p class="ft-upload-note">Every file uploaded here is linked to this Inquiry and remains available from Inquiry Documents.</p>
</section>
