@props([
    'purchaseOrderUpload' => null,
    'jobAttachments' => [],
    'canUpload' => false,
])

<section {{ $attributes->class(['ft-create-section', 'ft-order-documents-section']) }}>
    <div class="ft-order-documents-heading">
        <div class="ft-create-section-title ft-order-documents-title">
            <span>5</span>
            <h2>Documents</h2>
        </div>
        @if(($purchaseOrderUpload ? 1 : 0) + count($jobAttachments) > 0)
            <span class="ft-order-documents-count">
                {{ ($purchaseOrderUpload ? 1 : 0) + count($jobAttachments) }}
                {{ \Illuminate\Support\Str::plural('file', ($purchaseOrderUpload ? 1 : 0) + count($jobAttachments)) }}
            </span>
        @endif
    </div>
    <p class="ft-order-documents-intro">Upload the purchase order when available and add any supporting files for this order.</p>

    <div class="ft-order-documents-panel">
        <div class="ft-order-document-group ft-order-document-group--po">
            <div class="ft-order-document-group-heading">
                <strong>Purchase order</strong>
                <span class="ft-order-document-optional">Optional</span>
            </div>
            <p>Upload the official customer purchase order when available. One file only.</p>

            @if($canUpload)
                <x-ui.create-attachment-dropzone
                    input-id="job-create-purchase-order"
                    model="purchaseOrderUpload"
                    variant="order-document"
                    headline="Drop purchase order here"
                    browse-text="browse"
                    browse-button="Browse file"
                    :helper="\App\Support\AttachmentUpload::helperText(20)"
                    :accept="\App\Support\AttachmentUpload::accept(\App\Support\AttachmentUpload::DOCUMENTS_WITH_AI)"
                    progress-label="Uploading purchase order..."
                    progress-aria-label="Purchase order upload progress"
                />
            @else
                <div class="ft-create-note ft-order-document-permission-note">Your role does not allow purchase order uploads during Order creation.</div>
            @endif

            @if($purchaseOrderUpload)
                <div class="ft-order-document-list-block">
                    <small class="ft-order-document-list-label">Uploaded purchase order</small>
                    <x-jobs.create.document-file-row
                        :meta="\App\Support\CreateOrderDocumentPresenter::fileMeta($purchaseOrderUpload)"
                        input-id="job-create-purchase-order"
                        :purchase-order="true"
                    />
                </div>

                <div class="ft-order-document-success">
                    <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.2" fill="currentColor" opacity=".13"/><path d="m5.4 8 1.65 1.65L10.8 5.9" stroke="currentColor" stroke-width="1.55" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span>Purchase order attached and ready.</span>
                </div>
            @endif

            @error('purchaseOrderUpload')<small class="validation-error ft-order-document-error">{{ $message }}</small>@enderror
        </div>

        <div class="ft-order-document-divider" aria-hidden="true"></div>

        <div class="ft-order-document-group ft-order-document-group--other">
            <div class="ft-order-document-group-heading">
                <strong>Other documents</strong>
                <span class="ft-order-document-optional">Optional</span>
            </div>
            <p>Add specifications, artwork, references or approvals. Multiple files allowed.</p>

            @if($canUpload)
                <x-ui.create-attachment-dropzone
                    input-id="job-create-files"
                    model="jobAttachments"
                    :multiple="true"
                    variant="order-document"
                    headline="Drop supporting files here"
                    browse-text="browse"
                    browse-button="Browse files"
                    :helper="\App\Support\AttachmentUpload::helperText(20)"
                    :accept="\App\Support\AttachmentUpload::accept(\App\Support\AttachmentUpload::DOCUMENTS_WITH_AI)"
                    progress-label="Uploading selected files..."
                    progress-aria-label="Order document upload progress"
                />
            @else
                <div class="ft-create-note ft-order-document-permission-note">Your role does not allow supporting document uploads during Order creation.</div>
            @endif

            @if(count($jobAttachments) > 0)
                <div class="ft-order-document-list-block">
                    <small class="ft-order-document-list-label">Uploaded files ({{ count($jobAttachments) }})</small>
                    <div class="ft-order-document-file-list">
                        @foreach($jobAttachments as $index => $file)
                            <x-jobs.create.document-file-row
                                :meta="\App\Support\CreateOrderDocumentPresenter::fileMeta($file)"
                                input-id="job-create-files"
                                :index="$index"
                            />
                        @endforeach
                    </div>
                </div>
            @endif

            @error('jobAttachments')<small class="validation-error ft-order-document-error">{{ $message }}</small>@enderror
            @error('jobAttachments.*')<small class="validation-error ft-order-document-error">{{ $message }}</small>@enderror
        </div>

        <div class="ft-order-document-archive-note">
            <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="6.2" fill="currentColor" opacity=".12"/><path d="M8 7.1v4M8 4.7h.01" stroke="currentColor" stroke-width="1.45" stroke-linecap="round"/></svg>
            <span>Purchase orders and supporting documents will be saved to the order's Document Archive.</span>
        </div>
    </div>
</section>
