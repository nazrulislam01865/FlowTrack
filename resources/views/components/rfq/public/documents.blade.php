@props([
    'invitation', 'token', 'quote', 'products', 'documents', 'documentTypes',
    'requiredDocumentTypes', 'requiredDocumentCount', 'requiredDocumentTotal',
    'supportingInformationOptions', 'supportingInformation', 'locked' => false,
])
@php
    $inquiry = $invitation->inquiry;
    $firstProduct = collect($products)->first();
    $documentCollection = collect($documents);
@endphp
<div class="ft-rfq-portal-stack">
    <section class="ft-rfq-portal-card ft-rfq-documents-intro">
        <div class="ft-rfq-portal-card__header is-compact">
            <div>
                <h2>Quotation documents</h2>
                <p>Upload supporting files for {{ $firstProduct['name'] ?? 'the requested product' }}.</p>
            </div>
        </div>
        <div class="ft-rfq-documents-meta">
            <div><small>Inquiry reference</small><strong>{{ $inquiry->inquiry_number }}</strong></div>
            <div><small>Supplier</small><strong>{{ $invitation->supplier?->name ?: '—' }}</strong></div>
            <div>
                <small>Product &amp; quantity</small>
                <strong class="ft-rfq-documents-product-pill">{{ $firstProduct['code'] ?? 'Product' }} <span>·</span> {{ number_format((float) ($firstProduct['quantity'] ?? 0), 0) }} {{ $firstProduct['unit'] ?? 'units' }}</strong>
            </div>
        </div>
        <div class="ft-rfq-info-strip"><x-rfq.public.icon name="info" /> Upload files that support your pricing, specifications and production capability. You can add more files until the quotation is submitted.</div>
    </section>

    <section class="ft-rfq-portal-card ft-rfq-required-docs">
        <div class="ft-rfq-section-title-row">
            <h2>Required documents</h2>
            <span class="ft-rfq-complete-pill">{{ $requiredDocumentCount }} of {{ $requiredDocumentTotal }} complete</span>
        </div>
        <div class="ft-rfq-required-list">
            @foreach($requiredDocumentTypes as $requiredType)
                @php
                    $present = $documentCollection->contains('document_type', $requiredType);
                    $description = $requiredType === 'formal_quotation'
                        ? 'PDF or DOCX showing commercial pricing and validity.'
                        : 'XLSX or PDF with unit pricing and any volume breaks.';
                @endphp
                <div class="ft-rfq-required-row {{ $present ? 'is-complete' : '' }}">
                    <span class="ft-rfq-status-dot">{{ $present ? '✓' : $loop->iteration }}</span>
                    <div><strong>{{ $loop->iteration }}. {{ $documentTypes[$requiredType] }}</strong><small>{{ $description }}</small></div>
                    <span class="ft-rfq-uploaded-status">{{ $present ? '✓ Uploaded' : 'Required' }}</span>
                </div>
            @endforeach
        </div>
    </section>

    @unless($locked)
        <form method="post" action="{{ route('rfq.public.respond', ['token' => $token]) }}" enctype="multipart/form-data" id="rfq-documents-form" class="ft-rfq-portal-stack">
            @csrf
            <section class="ft-rfq-portal-card ft-rfq-upload-section">
                <h2>Upload documents</h2>
                <label class="ft-rfq-dropzone" data-rfq-dropzone>
                    <input type="file" name="documents[]" multiple accept="{{ \App\Support\AttachmentUpload::accept() }}" data-rfq-document-input data-upload-url="{{ route('rfq.public.documents.upload', ['token' => $token]) }}">
                    <span class="ft-rfq-upload-icon"><x-rfq.public.icon name="upload-cloud" /></span>
                    <span class="ft-rfq-dropzone__copy"><strong>Drop files here or <b>browse</b></strong><small>{{ \App\Support\AttachmentUpload::helperText(20) }}</small></span>
                    <span class="ft-rfq-browse-btn">Browse files</span>
                </label>

                <div class="ft-rfq-uploaded-heading">Uploaded files ({{ $documentCollection->count() }})</div>
                <div class="ft-rfq-doc-table-wrap">
                    <table class="ft-rfq-doc-table">
                        <thead><tr><th>Document</th><th>File name</th><th>Size</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        @forelse($documentCollection as $document)
                            @php
                                $extension = strtolower(pathinfo((string) $document->name, PATHINFO_EXTENSION));
                                $size = (int) $document->size;
                                $sizeLabel = $size >= 1048576 ? number_format($size / 1048576, 1).' MB' : number_format(max(1, (int) ceil($size / 1024)), 0).' KB';
                            @endphp
                            <tr>
                                <td>
                                    <select name="document_types[{{ $document->id }}]">
                                        @foreach($documentTypes as $value => $label)<option value="{{ $value }}" @selected(old('document_types.'.$document->id, $document->document_type) === $value)>{{ $label }}</option>@endforeach
                                    </select>
                                </td>
                                <td><div class="ft-rfq-file-name"><span class="ft-rfq-file-icon is-{{ $extension }}">{{ strtoupper(substr($extension ?: 'FILE', 0, 4)) }}</span><strong>{{ $document->name }}</strong></div></td>
                                <td>{{ $sizeLabel }}</td>
                                <td><span class="ft-rfq-ready-status">✓ Uploaded</span></td>
                                <td>
                                    <div class="ft-rfq-doc-actions">
                                        <a href="{{ route('rfq.public.documents.preview', ['token' => $token, 'document' => $document->id]) }}" target="_blank" rel="noopener">Preview</a>
                                        <a href="{{ route('rfq.public.documents.download', ['token' => $token, 'document' => $document->id]) }}">Download</a>
                                        <button type="submit" form="rfq-remove-doc-{{ $document->id }}">Remove</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="ft-rfq-empty-docs">No documents uploaded yet.</div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="ft-rfq-portal-card ft-rfq-supporting-section">
                <h2>Optional supporting information</h2>
                <div class="ft-rfq-supporting-checks">
                    @foreach($supportingInformationOptions as $value => $label)
                        <label><input type="checkbox" name="supporting_information[]" value="{{ $value }}" @checked(in_array($value, old('supporting_information', $supportingInformation), true))><span>{{ $label }}</span></label>
                    @endforeach
                </div>
                <label class="ft-rfq-document-notes"><span>Document notes (optional)</span><textarea name="document_notes" rows="3">{{ old('document_notes', $quote?->document_notes) }}</textarea></label>
            </section>

            <div class="ft-rfq-portal-bottom-actions">
                <a class="ft-rfq-btn is-secondary" href="{{ route('rfq.public.show', ['token' => $token, 'step' => 'pricing']) }}"><x-rfq.public.icon name="arrow-left" /> Back to pricing</a>
                <div>
                    <button type="submit" class="ft-rfq-btn is-secondary" name="action" value="save_documents"><x-rfq.public.icon name="save" /> Save draft</button>
                    <button type="submit" class="ft-rfq-btn is-primary" name="action" value="continue_review">Continue to review <x-rfq.public.icon name="chevron-right" /></button>
                </div>
            </div>
        </form>

        @foreach($documentCollection as $document)
            <form method="post" action="{{ route('rfq.public.documents.remove', ['token' => $token, 'document' => $document->id]) }}" id="rfq-remove-doc-{{ $document->id }}" class="ft-rfq-hidden-form">@csrf</form>
        @endforeach
    @else
        <section class="ft-rfq-portal-card"><div class="ft-rfq-locked-note">This quotation is locked and documents can no longer be changed.</div></section>
    @endunless
</div>
