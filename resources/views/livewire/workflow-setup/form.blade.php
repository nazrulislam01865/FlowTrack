<div class="ft-admin-reference ft-workflow-form-page">
    <div class="ft-admin-form-top ft-workflow-create-top">
        <div>
            <div class="ft-admin-breadcrumb">{{ $workflowId ? 'Edit Workflow' : 'New Workflow' }}</div>
            <h1>{{ $workflowId ? 'Edit Workflow' : 'Create New Workflow' }}</h1>
            <p>Configure Inquiry and Order workflows here. Task Packs are managed separately in Task Pack Setup.</p>
        </div>
        <a href="{{ route('workflow.setup') }}" wire:navigate class="ft-admin-back">← Back to Workflow Setup</a>
    </div>

    <form wire:submit="save" class="ft-admin-form-card ft-workflow-create-card" data-ft-feedback-scope="form">
        <section class="ft-workflow-form-section ft-workflow-details-section">
            <div class="ft-workflow-section-heading">
                <h2>1. Workflow details</h2>
                <p>Name and describe this workflow.</p>
            </div>

            <div class="ft-workflow-details-grid">
                <div class="ft-admin-field">
                    <label for="workflow-name">Workflow name *</label>
                    <input id="workflow-name" type="text" wire:model="workflowName" placeholder="e.g. Fast Track Order" autocomplete="off">
                    @error('workflowName')<div class="validation-error">{{ $message }}</div>@enderror
                </div>
                <div class="ft-admin-field">
                    <label for="workflow-code">Workflow code *</label>
                    <input id="workflow-code" type="text" wire:model="workflowCode" placeholder="e.g. FAST_TRACK" autocomplete="off">
                    @error('workflowCode')<div class="validation-error">{{ $message }}</div>@enderror
                </div>
                <div class="ft-admin-field ft-workflow-description-field">
                    <label for="workflow-description">Description</label>
                    <textarea id="workflow-description" wire:model="workflowDescription" rows="3" placeholder="Describe when this workflow should be used..."></textarea>
                    @error('workflowDescription')<div class="validation-error">{{ $message }}</div>@enderror
                </div>
            </div>
        </section>

        <section class="ft-workflow-form-section ft-workflow-scope-section">
            <div class="ft-workflow-section-heading">
                <h2>2. Workflow scope</h2>
                <p>Choose where this workflow is available.</p>
            </div>

            <fieldset class="ft-workflow-choice-group">
                <legend>Workflow applies to *</legend>
                <div class="ft-workflow-choice-grid">
                    <label class="ft-workflow-choice-card {{ $workflowAppliesTo === 'inquiries' ? 'is-selected' : '' }}">
                        <input type="radio" value="inquiries" wire:model.live="workflowAppliesTo" @disabled($workflowId)>
                        <span class="ft-workflow-choice-radio" aria-hidden="true"></span>
                        <span class="ft-workflow-choice-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <circle cx="12" cy="12" r="9"></circle>
                                <path d="M9.8 9.2a2.45 2.45 0 0 1 4.75.8c0 1.85-2.55 2.08-2.55 3.65"></path>
                                <path d="M12 17.2h.01"></path>
                            </svg>
                        </span>
                        <span class="ft-workflow-choice-copy">
                            <strong>Inquiries</strong>
                            <small>Use this workflow when managing new client inquiries.</small>
                        </span>
                    </label>

                    <label class="ft-workflow-choice-card {{ $workflowAppliesTo === 'orders' ? 'is-selected' : '' }}">
                        <input type="radio" value="orders" wire:model.live="workflowAppliesTo" @disabled($workflowId)>
                        <span class="ft-workflow-choice-radio" aria-hidden="true"></span>
                        <span class="ft-workflow-choice-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                                <path d="M4 8.5h16v10.5H4z"></path>
                                <path d="M8.2 8.5V6.8A2.8 2.8 0 0 1 11 4h2a2.8 2.8 0 0 1 2.8 2.8v1.7"></path>
                                <path d="M4 12.3h16"></path>
                            </svg>
                        </span>
                        <span class="ft-workflow-choice-copy">
                            <strong>Orders</strong>
                            <small>Use this workflow after an inquiry becomes an order.</small>
                        </span>
                    </label>
                </div>
                @if($workflowId)<small>Workflow scope is locked after creation so existing Inquiry/Order records keep the correct runtime behavior.</small>@endif
                @error('workflowAppliesTo')<div class="validation-error">{{ $message }}</div>@enderror
            </fieldset>

            <fieldset class="ft-workflow-choice-group ft-workflow-client-availability">
                <legend>Client availability *</legend>
                <div class="ft-workflow-choice-grid">
                    <label class="ft-workflow-choice-card ft-workflow-client-choice {{ $clientAvailability === 'all' ? 'is-selected' : '' }}">
                        <input type="radio" value="all" wire:model.live="clientAvailability">
                        <span class="ft-workflow-choice-radio" aria-hidden="true"></span>
                        <span class="ft-workflow-choice-copy">
                            <strong>All clients</strong>
                            <small>Available to every current and future client.</small>
                        </span>
                    </label>

                    <label class="ft-workflow-choice-card ft-workflow-client-choice {{ $clientAvailability === 'specific' ? 'is-selected' : '' }}">
                        <input type="radio" value="specific" wire:model.live="clientAvailability">
                        <span class="ft-workflow-choice-radio" aria-hidden="true"></span>
                        <span class="ft-workflow-choice-copy">
                            <strong>Specific clients</strong>
                            <small>Available only to clients you select.</small>
                        </span>
                    </label>
                </div>
                @error('clientAvailability')<div class="validation-error">{{ $message }}</div>@enderror
            </fieldset>

            @if($clientAvailability === 'specific')
                <div class="ft-admin-field ft-workflow-client-field">
                    <x-ui.multi-select
                        label="Select clients"
                        property="selectedClientIds"
                        type="clients"
                        context="workflow-setup"
                        :values="$selectedClientIds"
                        :initial-options="$clientOptions"
                        placeholder="Search and select clients"
                        :fixed-menu="true"
                        :menu-width="380"
                        :max-selected="100"
                        class="ft-workflow-client-multi-select"
                    />
                    <small>{{ count($selectedClientIds) }} {{ \Illuminate\Support\Str::plural('client', count($selectedClientIds)) }} selected. Search results are loaded in bounded pages.</small>
                    @error('selectedClientIds')<x-ui.validation-message :message="$message" />@enderror
                    @error('selectedClientIds.*')<x-ui.validation-message :message="$message" />@enderror
                </div>
            @endif
        </section>

        @unless($workflowId)
            <section class="ft-workflow-form-section ft-workflow-start-section">
                <div class="ft-workflow-section-heading">
                    <h2>3. Start from</h2>
                    <p>Existing workflow templates are fetched only when this section is needed.</p>
                </div>
                @if($sourceOptionsReady)
                    <div class="ft-admin-field" wire:key="workflow-source-options-ready">
                        <label for="workflow-source">Start from</label>
                        <select id="workflow-source" wire:model="sourceWorkflowId">
                            <option value="">Blank workflow</option>
                            @foreach($workflows as $workflow)
                                <option value="{{ $workflow->id }}">{{ $workflow->name }}</option>
                            @endforeach
                        </select>
                        <small>
                            @if($workflowAppliesTo === 'orders')
                                A blank Order workflow automatically receives the fixed seven Order stages and separate stage Task Packs. Duplicating an Order workflow clones its Task Packs so each workflow remains independently editable.
                            @else
                                Duplicating copies the Inquiry phase sequence and configuration, but not Inquiry history.
                            @endif
                        </small>
                        @error('sourceWorkflowId')<div class="validation-error">{{ $message }}</div>@enderror
                    </div>
                @else
                    <x-ui.progressive-section-loader section="source-workflows" :rows="3" />
                @endif
            </section>
        @endunless

        @if($workflowAppliesTo === 'orders')
            <div class="ft-workflow-scope-summary" style="margin-bottom:10px">
                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="10" r="7"></circle><path d="M10 9v4"></path><path d="M10 6.7h.01"></path></svg>
                <span><b>Order runtime is protected:</b> New Order, Artwork, Production, QC, Shipment, Billing and Payment are created automatically. Configure the tasks inside their mapped Task Packs from Task Pack Setup.</span>
            </div>
        @endif

        <div class="ft-workflow-scope-summary">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <circle cx="10" cy="10" r="7"></circle><path d="M10 9v4"></path><path d="M10 6.7h.01"></path>
            </svg>
            <span>
                This workflow will be available for {{ $workflowAppliesTo === 'inquiries' ? 'Inquiries' : 'Orders' }}
                @if($clientAvailability === 'specific')
                    from {{ count($selectedClientIds) }} selected {{ \Illuminate\Support\Str::plural('client', count($selectedClientIds)) }}.
                @else
                    for all clients.
                @endif
            </span>
        </div>

        <div class="ft-admin-form-footer ft-workflow-create-footer">
            <button type="button" class="ft-admin-cancel" wire:click="cancel">Cancel</button>
            <button type="submit" class="ft-admin-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $workflowId ? 'Save Workflow' : 'Create Workflow' }}</span>
                <span wire:loading wire:target="save">Saving...</span>
            </button>
        </div>
    </form>
</div>
