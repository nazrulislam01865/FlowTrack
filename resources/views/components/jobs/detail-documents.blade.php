@props([
    'job',
    'availableDocuments'=>collect(),
    'jobDocumentUploads'=>[],
    'jobRequiredDocumentUpload'=>null,
    'jobDocumentTaskId'=>null,
    'showDocumentPicker'=>false,
    'lastJobDocumentUploadId'=>null,
    'lastJobDocumentTaskId'=>null,
])
@php
    $required = \App\Support\JobDetailPresenter::requiredDocuments($job);
    $receivedRequired = $required->where('complete', true)->count();
    $missingRequired = $required->where('complete', false)->count();
    $requiredProgress = $required->count() ? (int) round(($receivedRequired / $required->count()) * 100) : 100;
    $unlinkedDocs = $job->documents->whereNull('task_id')->values();
    $canUploadDocument = app(\App\Services\AccessControlService::class)->can(auth()->user(),'documents','create');
    $canLinkDocument = app(\App\Services\AccessControlService::class)->can(auth()->user(),'documents','link');
    $canDeleteDocument = app(\App\Services\AccessControlService::class)->can(auth()->user(),'documents','delete');
    $canManageDocuments = $canUploadDocument || $canLinkDocument;
    $lastUploadedDocument = $lastJobDocumentUploadId
        && (int) $lastJobDocumentTaskId === (int) $jobDocumentTaskId
        ? $job->documents->firstWhere('id', (int) $lastJobDocumentUploadId)
        : null;
    $pendingUploadName = $jobRequiredDocumentUpload && method_exists($jobRequiredDocumentUpload, 'getClientOriginalName')
        ? $jobRequiredDocumentUpload->getClientOriginalName()
        : '';
    $pendingUploadSize = $jobRequiredDocumentUpload && method_exists($jobRequiredDocumentUpload, 'getSize')
        ? (int) $jobRequiredDocumentUpload->getSize()
        : 0;
    $uploadError = $errors->first('jobRequiredDocumentUpload')
        ?: ($pendingUploadName ? $errors->first('jobDocumentTaskId') : '');
    $uploadInitialState = $uploadError ? 'error' : ($lastUploadedDocument ? 'success' : 'idle');
@endphp
<div class="ft-documents-detail-section ft-exact-documents">
    <?php if (session('success')): ?><div class="flash">{{ session('success') }}</div><?php endif; ?>

    <div class="ft-detail-doc-full">
        <main>
            <div class="ft-section-title-row ft-doc-title-row ft-doc-ux-title">
                <div>
                    <h2>Documents</h2>
                    <p>Keep required files, document links, task attachments and existing client documents together.</p>
                </div>
                <div class="ft-doc-completion-copy">
                    <strong>{{ $receivedRequired }}/{{ $required->count() }}</strong>
                    <span>requirements satisfied</span>
                </div>
            </div>

            <div class="ft-doc-summary-strip" aria-label="Document summary">
                <div class="ft-doc-summary-item">
                    <span class="ft-doc-summary-icon blue">▣</span>
                    <div><small>Total files</small><b>{{ $job->documents->count() }}</b></div>
                </div>
                <div class="ft-doc-summary-item">
                    <span class="ft-doc-summary-icon neutral">✓</span>
                    <div><small>Required</small><b>{{ $required->count() }}</b></div>
                </div>
                <div class="ft-doc-summary-item {{ $missingRequired ? 'needs-action' : '' }}">
                    <span class="ft-doc-summary-icon amber">!</span>
                    <div><small>Needs action</small><b>{{ $missingRequired }}</b></div>
                </div>
                <div class="ft-doc-summary-item">
                    <span class="ft-doc-summary-icon green">✓</span>
                    <div><small>Satisfied</small><b>{{ $receivedRequired }}</b></div>
                </div>
            </div>

            <div class="ft-doc-required-progress" aria-label="Required submission progress">
                <div><span>Required submission progress</span><b>{{ $requiredProgress }}%</b></div>
                <span class="ft-doc-progress-track"><i style="width: {{ $requiredProgress }}%"></i></span>
            </div>

            @include('components.jobs.documents.required-document-uploader')

            @include('components.jobs.documents.document-library')
        </main>
    </div>
</div>
