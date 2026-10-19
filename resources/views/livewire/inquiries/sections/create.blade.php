        @php
            $selectedWorkflow = collect($workflowFilterOptions)->first(fn ($item) => (int) ($item['id'] ?? 0) === (int) $createWorkflowId);
            $selectedWorkflowName = (string) ($selectedWorkflow['label'] ?? $selectedWorkflowLabel ?: 'Select workflow');
        @endphp
        <section class="view ft-inquiry-create-v3 ft-form-standard ft-form-standard--inquiry" data-ft-feedback-scope="form" x-on:keydown.meta.enter.window="$wire.createInquiry()" x-on:keydown.ctrl.enter.window="$wire.createInquiry()">
            <div class="formwrap ft-inquiry-create-shell">
                <div class="crumb">Inquiries / New Inquiry</div>
                <div class="formtop ft-inquiry-create-heading">
                    <div>
                        <h1>Create Inquiry</h1>
                        <p>Capture a new client request from email or phone. The inquiry workflow starts automatically.</p>
                    </div>
                </div>

                <div class="formcard ft-inquiry-create-card">
                    <section class="section ft-inquiry-create-section ft-inquiry-create-details">
                        <div class="sectiontitle ft-inquiry-step-title"><span>1</span><h2>Inquiry details</h2></div>

                        <div class="ft-inquiry-create-grid ft-inquiry-create-grid-top">
                            <div class="ft-inquiry-create-field">
                                <label>How was this inquiry received? *</label>
                                <div class="ft-inquiry-source-switch" role="group" aria-label="How was this inquiry received?">
                                    @foreach(['Email' => '✉', 'Phone' => '☎', 'Other' => '•••'] as $source => $icon)
                                        <button type="button" class="{{ $requestSource === $source ? 'is-active' : '' }}" wire:click="$set('requestSource', '{{ $source }}')">
                                            <span aria-hidden="true">{{ $icon }}</span>{{ $source }}
                                        </button>
                                    @endforeach
                                </div>
                                @error('requestSource')<small class="field-error">{{ $message }}</small>@enderror
                            </div>

                            <div class="ft-inquiry-create-field">
                                <label for="inquiry-received-date">Received *</label>
                                <div class="ft-inquiry-received-control">
                                    <input id="inquiry-received-date" type="date" wire:model="createReceivedDate" aria-describedby="inquiry-received-help">
                                </div>
                                <small id="inquiry-received-help" class="ft-inquiry-field-help">Defaults to today. Change it when the inquiry was received on another date.</small>
                                @error('createReceivedDate')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                        </div>

                        <div class="ft-inquiry-create-grid ft-inquiry-create-grid-client">
                            <div class="ft-inquiry-create-field">
                                <label>Client *</label>
                                <div class="ft-inquiry-client-control-row">
                                    <x-ui.search-select
                                        class="ft-create-remote-select inquiry-create-remote ft-inquiry-client-selector"
                                        label="Client"
                                        property="clientId"
                                        type="clients"
                                        context="create-inquiry"
                                        action="setCreateSelector"
                                        :value="$clientId"
                                        placeholder="Search or select client..."
                                        :selected-label="$selectedClientLabel ?: null"
                                        :initial-options="$clientFilterOptions"
                                        :clearable="false"
                                        wire:key="inquiry-create-client-selector-{{ $clientId ?: 'none' }}-{{ substr(md5($selectedClientLabel ?: 'none'), 0, 8) }}"
                                    />
                                </div>
                                @error('clientId')<small class="field-error">{{ $message }}</small>@enderror
                            </div>

                            <div class="ft-inquiry-create-field">
                                <label>Client contact *</label>
                                <div class="ft-inquiry-client-control-row">
                                    <div class="ft-inquiry-contact-select-wrap">
                                        <select wire:model="clientContact" @disabled(!$clientId || empty($clientContactOptions)) aria-required="true">
                                            @if(!$clientId)
                                                <option value="">Select a client first</option>
                                            @elseif(empty($clientContactOptions))
                                                <option value="">No contact recorded</option>
                                            @else
                                                @foreach($clientContactOptions as $contactOption)
                                                    <option value="{{ $contactOption['value'] }}">{{ $contactOption['label'] }}{{ $contactOption['primary'] ? ' · Primary' : '' }}{{ $contactOption['meta'] ? ' · '.$contactOption['meta'] : '' }}</option>
                                                @endforeach
                                            @endif
                                        </select>
                                    </div>
                                </div>
                                @if($clientId && empty($clientContactOptions))
                                    <small class="ft-inquiry-field-help">This client has no contact. Add a contact from Clients before creating the Inquiry.</small>
                                @endif
                                @error('clientContact')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                        </div>

                        <div class="ft-inquiry-create-grid">
                            <label class="ft-inquiry-create-field">
                                <span>Reference number</span>
                                <input wire:model="referenceNumber" placeholder="Enter the client-provided ES or NEQ number">
                            </label>

                            <div class="ft-inquiry-create-field">
                                <label>Assigned to *</label>
                                <x-ui.search-select
                                    class="ft-create-remote-select inquiry-create-remote ft-inquiry-owner-selector"
                                    label="Assigned to"
                                    property="createOwnerId"
                                    type="users"
                                    context="create-inquiry"
                                    action="setCreateSelector"
                                    :value="$createOwnerId"
                                    placeholder="Search or select assignee..."
                                    :selected-label="$selectedOwnerLabel ?: null"
                                    :initial-options="$ownerFilterOptions"
                                    :clearable="false"
                                    wire:key="inquiry-create-owner-selector-{{ $createOwnerId ?: 'none' }}-{{ substr(md5($selectedOwnerLabel ?: 'none'), 0, 8) }}"
                                />
                                @error('createOwnerId')<small class="field-error">{{ $message }}</small>@enderror
                            </div>
                        </div>

                        @php
                            $createPriorityColor = optional($createPriorityOptions->first(
                                fn ($priority) => (string) $priority->name === (string) $createPriority
                            ))->color;
                        @endphp
                        <div class="ft-inquiry-create-field ft-inquiry-create-field-full">
                            <label>Shipment Priority *</label>
                            <select
                                data-master-color-select
                                wire:model="createPriority"
                                class="{{ $createPriorityColor ? 'ft-master-color' : '' }}"
                                style="{{ \App\Support\MasterColor::style($createPriorityColor) }}"
                                aria-label="Inquiry priority"
                            >
                                @forelse($createPriorityOptions as $priority)
                                    <option value="{{ $priority->name }}" data-color="{{ $priority->color }}">{{ $priority->name }}</option>
                                @empty
                                    <option value="">No active priorities</option>
                                @endforelse
                            </select>
                            @error('createPriority')<small class="field-error">{{ $message }}</small>@enderror
                        </div>

                        <label class="ft-inquiry-create-field ft-inquiry-create-field-full">
                            <span>Inquiry title *</span>
                            <input wire:model="subject" placeholder="e.g. 5,000 embroidered polo shirts for September">
                            @error('subject')<small class="field-error">{{ $message }}</small>@enderror
                        </label>

                        <div class="ft-inquiry-create-field ft-inquiry-create-field-full ft-inquiry-request-details">
                            <label>Request details</label>
                            <textarea data-rich-text wire:model="requirementNotes" placeholder="Paste or summarize the client's request, including quantities, specifications, target date and any special instructions..."></textarea>
                            <small class="ft-inquiry-field-tip"><b>Tip:</b> Include quantity, product, deadline and delivery location.</small>
                        </div>
                    </section>

                    @if($canUseInquiryProductSelector)
                        @include('components.inquiries.create-products')
                    @endif


                    <section class="section ft-inquiry-create-section ft-inquiry-attachments-section">
                        <div class="sectiontitle ft-inquiry-step-title ft-inquiry-step-title-inline">
                            <span>{{ $canUseInquiryProductSelector ? 3 : 2 }}</span><h2>Attachments</h2><p>Add emails, specifications, artwork or reference images.</p>
                        </div>
                        <x-ui.create-attachment-dropzone
                            input-id="inquiry-create-attachments"
                            model="createAttachments"
                            :multiple="true"
                            headline="Drop client files here"
                            browse-text="browse files"
                            :helper="\App\Support\AttachmentUpload::helperText(20)"
                            progress-label="Uploading files..."
                            progress-aria-label="Inquiry attachment upload progress"
                        />
                        @if(count($createAttachments))
                            <div class="inquiry-selected-files ft-inquiry-selected-files">
                                <div class="inquiry-selected-files-title">Selected files <span>{{ count($createAttachments) }}</span></div>
                                <div class="ft-inquiry-selected-file-grid">
                                    @foreach($createAttachments as $upload)
                                        @php
                                            $attachmentName = (string) $upload->getClientOriginalName();
                                            $attachmentExtension = strtolower((string) pathinfo($attachmentName, PATHINFO_EXTENSION));
                                            $attachmentMime = method_exists($upload, 'getMimeType') ? (string) $upload->getMimeType() : '';
                                            $attachmentIsImage = str_starts_with($attachmentMime, 'image/')
                                                || in_array($attachmentExtension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                                            $attachmentPreviewUrl = $attachmentIsImage && method_exists($upload, 'temporaryUrl')
                                                ? $upload->temporaryUrl()
                                                : null;
                                            $attachmentSize = method_exists($upload, 'getSize') ? (int) $upload->getSize() : 0;
                                            $attachmentSizeLabel = $attachmentSize >= 1048576
                                                ? number_format($attachmentSize / 1048576, 1).' MB'
                                                : ($attachmentSize > 0 ? max(1, (int) round($attachmentSize / 1024)).' KB' : 'Selected file');
                                        @endphp
                                        <article class="ft-inquiry-selected-file-card" wire:key="create-attachment-{{ $loop->index }}-{{ md5($attachmentName) }}">
                                            @if($attachmentPreviewUrl)
                                                <a
                                                    class="ft-inquiry-selected-file-preview"
                                                    href="{{ $attachmentPreviewUrl }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    title="Open image preview"
                                                    aria-label="Open preview of {{ $attachmentName }}"
                                                >
                                                    <img src="{{ $attachmentPreviewUrl }}" alt="Preview of {{ $attachmentName }}">
                                                    <span>Preview</span>
                                                </a>
                                            @else
                                                <div class="ft-inquiry-selected-file-type" aria-hidden="true">
                                                    <span>▤</span>
                                                    <b>{{ $attachmentExtension !== '' ? strtoupper($attachmentExtension) : 'FILE' }}</b>
                                                </div>
                                            @endif
                                            <div class="ft-inquiry-selected-file-meta">
                                                <strong title="{{ $attachmentName }}">{{ $attachmentName }}</strong>
                                                <span>{{ $attachmentExtension !== '' ? strtoupper($attachmentExtension) : 'FILE' }} · {{ $attachmentSizeLabel }}</span>
                                            </div>
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </section>

                    @if($createWorkflowReady)
                        <x-ui.create-workflow-picker
                            class="section ft-inquiry-create-section ft-inquiry-next-section"
                            :step="$canUseInquiryProductSelector ? 4 : 3"
                            title="What happens next"
                            :workflow-options="$workflowFilterOptions"
                            :selected-workflow-id="$createWorkflowId"
                            :selected-workflow-name="$selectedWorkflowName"
                            :phase-count="$createWorkflowPhaseCount"
                            :task-count="$createWorkflowTaskCount"
                            selection-property="createWorkflowId"
                            option-fallback="Inquiry workflow"
                            footnote="Tasks are created when you select Create inquiry."
                            :preview-allowed="auth()->user()->canAccess('workflow.view')"
                            :empty-message="$createWorkflowId && $createWorkflowTaskCount === 0 ? 'This Workflow has no active Task Pack tasks.' : null"
                            error-field="createWorkflowId"
                            wire:key="create-inquiry-workflow-picker"
                        />
                    @else
                        <section class="section ft-inquiry-create-section ft-inquiry-next-section" wire:key="create-inquiry-workflow-placeholder">
                            <div class="sectiontitle ft-inquiry-step-title ft-inquiry-step-title-inline">
                                <span>{{ $canUseInquiryProductSelector ? 4 : 3 }}</span><h2>What happens next</h2><p>Workflow options load only when this section is needed.</p>
                            </div>
                            <x-ui.progressive-section-loader section="workflow" :rows="3" />
                        </section>
                    @endif

                    <div class="formactions ft-inquiry-create-actions">
                        <span>Required fields are marked with *</span>
                        <div>
                            <button class="secondary" type="button" wire:click="cancelCreate">Cancel</button>
                            <button class="secondary" type="button" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft">Save draft</button>
                            <button class="primary" type="button" wire:click="createInquiry" wire:loading.attr="disabled" wire:target="createInquiry">Create inquiry <kbd>⌘ Enter</kbd></button>
                        </div>
                    </div>
                </div>
            </div>

            @if($showCreateClientModal)
                <div class="ft-inquiry-modal-backdrop" wire:key="inquiry-quick-client-modal" wire:click.self="closeCreateClientModal">
                    <section class="ft-inquiry-quick-client-modal" role="dialog" aria-modal="true" aria-labelledby="quick-client-title">
                        <header>
                            <div><h2 id="quick-client-title">Add new client</h2><p>Create the client with minimum information. You can complete the profile later.</p></div>
                            <button type="button" wire:click="closeCreateClientModal" aria-label="Close">×</button>
                        </header>
                        <div class="ft-inquiry-quick-client-body">
                            <label class="ft-inquiry-modal-field ft-inquiry-modal-field-full"><span>Client name *</span><input wire:model="newClientName" placeholder="Company or client name">@error('newClientName')<small class="field-error">{{ $message }}</small>@enderror<small>This is the only required field.</small></label>

                            <div class="ft-inquiry-modal-divider"></div>
                            <div class="ft-inquiry-modal-subhead"><strong>Primary contact (optional)</strong><span>Add contact details if they were provided with the inquiry.</span></div>
                            <label class="ft-inquiry-modal-field ft-inquiry-modal-field-full"><span>Contact name</span><input wire:model="newClientContactName" placeholder="Full name"></label>
                            <div class="ft-inquiry-modal-grid">
                                <label class="ft-inquiry-modal-field"><span>Email</span><input type="email" wire:model="newClientEmail" placeholder="name@company.com">@error('newClientEmail')<small class="field-error">{{ $message }}</small>@enderror</label>
                                <label class="ft-inquiry-modal-field"><span>Phone</span><input wire:model="newClientPhone" placeholder="Phone number"></label>
                            </div>
                            <label class="ft-inquiry-contact-checkbox"><input type="checkbox" wire:model="useNewClientContactForInquiry"><span>Use this person as the inquiry contact</span></label>
                            <label class="ft-inquiry-modal-field ft-inquiry-modal-field-full"><span>Country / region</span><input list="ft-country-regions" wire:model="newClientCountry" placeholder="Select country or region"><datalist id="ft-country-regions"><option value="Bangladesh"><option value="China"><option value="Hong Kong"><option value="India"><option value="United Kingdom"><option value="United States"><option value="Vietnam"><option value="Cambodia"><option value="Pakistan"><option value="Sri Lanka"><option value="United Arab Emirates"></datalist></label>
                            <div class="ft-inquiry-client-info">ⓘ <span>The new client will be selected automatically in this inquiry.</span></div>
                        </div>
                        <footer>
                            <span>Required fields are marked with *</span>
                            <div><button type="button" class="secondary" wire:click="closeCreateClientModal">Cancel</button><button type="button" class="primary" wire:click="createClientAndSelect" wire:loading.attr="disabled" wire:target="createClientAndSelect">Add &amp; select client</button></div>
                        </footer>
                    </section>
                </div>
            @endif

            @if($showCreateContactModal)
                <div class="ft-inquiry-modal-backdrop" wire:key="inquiry-quick-contact-modal" wire:click.self="closeCreateContactModal">
                    <section class="ft-inquiry-quick-client-modal ft-inquiry-quick-contact-modal" role="dialog" aria-modal="true" aria-labelledby="quick-contact-title">
                        <header><div><h2 id="quick-contact-title">Add client contact</h2><p>Add the primary contact for {{ $selectedClientLabel ?: 'this client' }} and use it in this inquiry.</p></div><button type="button" wire:click="closeCreateContactModal" aria-label="Close">×</button></header>
                        <div class="ft-inquiry-quick-client-body">
                            <label class="ft-inquiry-modal-field ft-inquiry-modal-field-full"><span>Contact name *</span><input wire:model="newContactName" placeholder="Full name">@error('newContactName')<small class="field-error">{{ $message }}</small>@enderror</label>
                            <div class="ft-inquiry-modal-grid">
                                <label class="ft-inquiry-modal-field"><span>Email</span><input type="email" wire:model="newContactEmail" placeholder="name@company.com">@error('newContactEmail')<small class="field-error">{{ $message }}</small>@enderror</label>
                                <label class="ft-inquiry-modal-field"><span>Phone</span><input wire:model="newContactPhone" placeholder="Phone number"></label>
                            </div>
                        </div>
                        <footer><span></span><div><button type="button" class="secondary" wire:click="closeCreateContactModal">Cancel</button><button type="button" class="primary" wire:click="saveCreateContact" wire:loading.attr="disabled" wire:target="saveCreateContact">Add contact</button></div></footer>
                    </section>
                </div>
            @endif
        </section>

