            <section id="job-document-upload-panel" class="ft-detail-card ft-upload-panel ft-doc-upload-card ft-prototype-document-uploader">
                <div class="ft-proto-doc-heading">
                    <span class="ft-proto-doc-heading-icon" aria-hidden="true">＋</span>
                    <div>
                        <h3>Add a required document</h3>
                        <p>Choose the document type, then upload a file, select an existing file, or use the task Add link action.</p>
                    </div>
                </div>

                <?php if ($required->isNotEmpty()): ?>
                    <div class="ft-proto-doc-field">
                        <label for="jobDocumentRequirement-{{ $job->id }}">Document type</label>
                        <select id="jobDocumentRequirement-{{ $job->id }}" wire:model.live="jobDocumentTaskId" class="ft-proto-doc-select">
                            <option value="">Select document type</option>
                            @foreach($required as $item)
                                <option value="{{ $item->task->id }}">{{ $item->name }} · {{ $item->phase->name }} · {{ $item->task->title }}</option>
                            @endforeach
                        </select>
                        <p>FlowTrack links the file to the selected phase and task automatically.</p>
                        <?php if ($errors->has('jobDocumentTaskId')): ?><div class="validation-error ft-field-validation" role="alert">{{ $errors->first('jobDocumentTaskId') }}</div><?php endif; ?>
                    </div>

                    <div class="ft-proto-doc-add-label">Add document</div>
                    <div class="ft-proto-doc-mode" role="tablist" aria-label="Document source">
                        <?php if ($canUploadDocument): ?>
                            <button type="button" role="tab" aria-selected="{{ ! $showDocumentPicker ? 'true' : 'false' }}" class="{{ ! $showDocumentPicker ? 'is-active' : '' }}" wire:click="setDocumentUploadMode('upload')">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg>
                                <span>Upload new</span>
                            </button>
                        <?php endif; ?>
                        <?php if ($canLinkDocument): ?>
                            <button type="button" role="tab" aria-selected="{{ ($showDocumentPicker || ! $canUploadDocument) ? 'true' : 'false' }}" class="{{ ($showDocumentPicker || ! $canUploadDocument) ? 'is-active' : '' }}" wire:click="setDocumentUploadMode('existing')">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v13H7zM14 3v6h5"/></svg>
                                <span>Choose existing</span>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($canUploadDocument && ! $showDocumentPicker): ?>
                        <div
                            class="ft-proto-upload-shell"
                            wire:key="job-required-upload-{{ $job->id }}-{{ $uploadInitialState }}-{{ (int) ($lastUploadedDocument?->id ?? 0) }}-{{ md5((string) $uploadError) }}"
                            data-file-dropzone
                            x-data="{
                                state: @js($uploadInitialState),
                                progress: 0,
                                fileName: @js($lastUploadedDocument?->name ?: $pendingUploadName),
                                fileSize: @js($lastUploadedDocument?->size ?: $pendingUploadSize),
                                errorText: @js($uploadError ?: 'The connection was interrupted. Your document was not added.'),
                                selectedFile: null,
                                uploadToken: 0,
                                prettySize(bytes) {
                                    const value = Number(bytes || 0);
                                    if (!value) return '';
                                    if (value < 1024 * 1024) return `${Math.max(1, Math.round(value / 1024))} KB`;
                                    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
                                },
                                validateFile(file) {
                                    const extension = String(file?.name || '').split('.').pop().toLowerCase();
                                    const allowed = @js(\App\Support\AttachmentUpload::extensions());
                                    if (!allowed.includes(extension)) {
                                        this.errorText = @js(\App\Support\AttachmentUpload::validationMessage());
                                        return false;
                                    }
                                    if (Number(file?.size || 0) > 20 * 1024 * 1024) {
                                        this.errorText = 'The file is too large. The maximum size is 20 MB.';
                                        return false;
                                    }
                                    return true;
                                },
                                captureFile(event) {
                                    const file = event.target.files?.[0];
                                    if (!file) return;
                                    this.startUpload(file);
                                },
                                startUpload(file) {
                                    this.selectedFile = file;
                                    this.fileName = file.name;
                                    this.fileSize = file.size;
                                    this.progress = 0;
                                    if (!this.validateFile(file)) {
                                        this.state = 'error';
                                        return;
                                    }

                                    const token = ++this.uploadToken;
                                    this.errorText = 'The connection was interrupted. Your document was not added.';
                                    this.state = 'uploading';
                                    this.progress = 1;

                                    $wire.upload(
                                        'jobRequiredDocumentUpload',
                                        file,
                                        async () => {
                                            if (token !== this.uploadToken) return;
                                            this.progress = 100;
                                            try {
                                                const result = await $wire.persistJobRequiredDocumentUpload();
                                                if (token !== this.uploadToken) return;
                                                if (result && result.ok === false) {
                                                    this.errorText = result.message || 'The document could not be saved. Please try again.';
                                                    this.state = 'error';
                                                    return;
                                                }
                                                this.state = 'success';
                                            } catch (error) {
                                                if (token !== this.uploadToken) return;
                                                this.errorText = 'The document could not be saved. Please try again.';
                                                this.state = 'error';
                                            }
                                        },
                                        () => {
                                            if (token !== this.uploadToken) return;
                                            this.errorText = 'The connection was interrupted. Your document was not added.';
                                            this.state = 'error';
                                        },
                                        (event) => {
                                            if (token !== this.uploadToken) return;
                                            this.state = 'uploading';
                                            this.progress = Math.max(1, Math.min(99, Number(event?.detail?.progress || 0)));
                                        },
                                        () => {
                                            if (token !== this.uploadToken) return;
                                            this.progress = 0;
                                            this.state = 'idle';
                                        }
                                    );
                                },
                                retry() {
                                    const file = this.selectedFile || this.$refs.input?.files?.[0];
                                    if (!file) {
                                        this.$refs.input?.click();
                                        return;
                                    }
                                    this.startUpload(file);
                                },
                                cancel() {
                                    ++this.uploadToken;
                                    $wire.cancelUpload('jobRequiredDocumentUpload');
                                    this.clearSelection(false);
                                },
                                clearSelection(resetServer = true) {
                                    if (this.$refs.input) this.$refs.input.value = '';
                                    this.selectedFile = null;
                                    this.fileName = '';
                                    this.fileSize = 0;
                                    this.progress = 0;
                                    this.state = 'idle';
                                    if (resetServer) $wire.clearJobRequiredDocumentUpload();
                                }
                            }"
                        >
                            <input
                                x-ref="input"
                                class="ft-proto-file-input"
                                id="jobDocumentUpload-{{ $job->id }}"
                                type="file"
                                accept="{{ \App\Support\AttachmentUpload::accept() }}"
                                x-on:change="captureFile($event)"
                            >

                            <div class="ft-proto-upload-state ft-proto-upload-idle" x-show="state === 'idle'" x-cloak>
                                <button type="button" class="ft-proto-idle-target" x-on:click="$refs.input.click()">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V5m0 0L8 9m4-4 4 4M6 14a4 4 0 0 0 1 7h10a4 4 0 0 0 1-7 6 6 0 0 0-11.4-1.8"/></svg>
                                    <span>Drop the file here or <strong>browse</strong> to choose a file.</span>
                                </button>
                            </div>

                            <div class="ft-proto-upload-state ft-proto-uploading" x-show="state === 'uploading'" x-cloak>
                                <div class="ft-proto-file-summary">
                                    <span class="ft-proto-file-icon" x-text="(fileName.split('.').pop() || 'FILE').slice(0,4).toUpperCase()">FILE</span>
                                    <div class="ft-proto-file-copy">
                                        <strong x-text="fileName || 'Preparing file…'"></strong>
                                        <span><span x-text="prettySize(fileSize)"></span><template x-if="fileSize"><span> · </span></template><span x-text="progress >= 100 ? 'Saving & linking…' : 'Uploading…'"></span></span>
                                        <div class="ft-proto-progress-row">
                                            <span class="ft-proto-progress"><i :style="`width:${progress}%`"></i></span>
                                            <b x-text="`${Math.round(progress)}%`"></b>
                                        </div>
                                        <p x-text="progress >= 100 ? 'Upload received. FlowTrack is saving and linking the document…' : 'Keep this window open until the upload finishes.'"></p>
                                    </div>
                                </div>
                                <button type="button" class="ft-proto-outline-action" x-on:click="cancel()">Cancel</button>
                                <div class="ft-proto-drop-again">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 17V9m0 0-3 3m3-3 3 3M6 17a4 4 0 0 1 1-7 6 6 0 0 1 11.4 1.8A4 4 0 0 1 18 20H7"/></svg>
                                    <span>Drop another file here or <button type="button" x-on:click="$refs.input.click()">browse</button> after this upload finishes.</span>
                                </div>
                            </div>

                            <div class="ft-proto-upload-state ft-proto-upload-error" x-show="state === 'error'" x-cloak>
                                <div class="ft-proto-file-summary">
                                    <span class="ft-proto-file-icon" x-text="(fileName.split('.').pop() || 'FILE').slice(0,4).toUpperCase()">FILE</span>
                                    <div class="ft-proto-file-copy">
                                        <strong x-text="fileName || 'Selected document'"></strong>
                                        <span><span x-text="prettySize(fileSize)"></span><template x-if="fileSize"><span> · </span></template>Upload failed</span>
                                        <div class="ft-proto-error-title"><span>!</span><b>We couldn’t upload this file</b></div>
                                        <p x-text="errorText"></p>
                                        <p>If this keeps happening, check your connection and try again.</p>
                                    </div>
                                </div>
                                <div class="ft-proto-error-actions">
                                    <button type="button" class="ft-proto-primary-action" x-on:click="retry()">Retry upload</button>
                                    <button type="button" class="ft-proto-outline-action" x-on:click="$refs.input.click()">Choose another file</button>
                                    <button type="button" class="ft-proto-link-action" x-on:click="clearSelection()">Remove</button>
                                </div>
                                <div class="ft-proto-drop-again">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 17V9m0 0-3 3m3-3 3 3M6 17a4 4 0 0 1 1-7 6 6 0 0 1 11.4 1.8A4 4 0 0 1 18 20H7"/></svg>
                                    <span>Drop the file here again or <button type="button" x-on:click="$refs.input.click()">browse</button> to choose another file.</span>
                                </div>
                            </div>

                            <?php if ($lastUploadedDocument): ?>
                                <div class="ft-proto-upload-state ft-proto-upload-success" x-show="state === 'success'" x-cloak>
                                    <div class="ft-proto-file-summary">
                                        <span class="ft-proto-file-icon">{{ strtoupper(pathinfo($lastUploadedDocument->name, PATHINFO_EXTENSION) ?: 'FILE') }}</span>
                                        <div class="ft-proto-file-copy">
                                            <strong>{{ $lastUploadedDocument->name }}</strong>
                                            <span>{{ number_format(((int) $lastUploadedDocument->size) / 1048576, 1) }} MB · Uploaded just now</span>
                                            <div class="ft-proto-success-title"><span>✓</span><b>Upload complete</b></div>
                                            <p>Linked to the selected document type.</p>
                                        </div>
                                    </div>
                                    <div class="ft-proto-success-actions">
                                        <a class="ft-proto-outline-action" href="{{ route('documents.open', $lastUploadedDocument) }}" target="_blank" rel="noopener">View document</a>
                                        <?php if ($canDeleteDocument): ?><button type="button" class="ft-proto-link-action" wire:click="removeLastJobDocumentUpload">Remove</button><?php endif; ?>
                                    </div>
                                    <div class="ft-proto-drop-again">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 17V9m0 0-3 3m3-3 3 3M6 17a4 4 0 0 1 1-7 6 6 0 0 1 11.4 1.8A4 4 0 0 1 18 20H7"/></svg>
                                        <span>Drop another file here or <button type="button" x-on:click="$refs.input.click()">browse</button> to replace this document.</span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="ft-proto-upload-formats">{{ \App\Support\AttachmentUpload::helperText(20) }}</div>
                    <?php elseif ($canLinkDocument && ($showDocumentPicker || ! $canUploadDocument)): ?>
                        <div class="ft-proto-existing-panel">
                            <div class="ft-proto-existing-copy">
                                <span class="ft-proto-existing-icon" aria-hidden="true">▤</span>
                                <div><b>Choose an existing document</b><p>Select a stored document for this client and FlowTrack will link it to the document type above.</p></div>
                            </div>
                            <?php if ($availableDocuments->isNotEmpty()): ?>
                                <div class="ft-proto-existing-controls">
                                    <select wire:model="existingDocumentId">
                                        <option value="">Select a stored document</option>
                                        @foreach($availableDocuments as $doc)
                                            <option value="{{ $doc->id }}">{{ $doc->name }} · {{ $doc->job?->displayOrderNumber() ?? 'Document archive' }}</option>
                                        @endforeach
                                    </select>
                                    <button class="ft-proto-primary-action" type="button" wire:click="attachExistingDocument">Link document</button>
                                </div>
                                <?php if ($errors->has('existingDocumentId')): ?><div class="validation-error ft-field-validation">{{ $errors->first('existingDocumentId') }}</div><?php endif; ?>
                            <?php else: ?>
                                <div class="ft-proto-existing-empty">No stored documents are available for this client yet.</div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <p class="muted small">You have read-only access to Job documents.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="ft-empty-taskpack-docs">No document requirement exists in the Task Packs selected by this Job. Upload requirements are never created outside the Task Packs.</div>
                <?php endif; ?>
            </section>
