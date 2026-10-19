@props([
    'clients','workflows','categories','priorities','clientId','workflowId','ownerId','jobItems','jobAttachments','purchaseOrderUpload'=>null,
    'priority'=>'Medium','productionUrgencies'=>collect(),'shipmentMethods'=>collect(),'shipmentUrgencies'=>collect(),'productionUrgencyIds'=>[],'shipmentMethodIds'=>[],'shipmentUrgencyIds'=>[],'isRepeatedOrder'=>false,'repeatedOrderNumber'=>'',
    'clientFilterOptions'=>collect(),'ownerFilterOptions'=>collect(),'workflowFilterOptions'=>collect(),'categoryFilterOptions'=>collect(),
    'productCategories'=>collect(),'productSearchResults'=>collect(),'productSearchSuppliers'=>collect(),'selectedProductDetails'=>collect(),'selectedProductSuppliers'=>collect(),'createOrderSupplierSkipProductIds'=>[],'activeProductCount'=>0,'productResultTotal'=>0,
    'canUseOrderProductSelector'=>false,'canCreateCatalogProduct'=>false,'canViewProductCategories'=>false,'canCreateProductCategory'=>false,'duplicateProduct'=>null,'newProductCategoryMatches'=>collect(),'newProductSimilarCategories'=>collect(),
    'newProductSimilarProducts'=>collect(),'newProductSelectedCategory'=>null,'newProductHasExactCategory'=>false,'newProductImagePreview'=>null,'newProductSupplierOptions'=>collect(),
    'createProductSearch'=>'','createProductCategoryFilter'=>'','createProductShowAllResults'=>false,'showCreateOrderProductModal'=>false,
    'newProductCode'=>'','newProductCategoryId'=>null,'newProductCategorySearch'=>'','newProductCategoryName'=>'','newProductName'=>'','newProductSupplierId'=>null,
    'catalogReady'=>false,'assignmentReady'=>false,'workflowReady'=>false,'workflowSelectorVersion'=>0,'workflowPhaseId'=>null,'mentionUsers'=>collect(),
    'createInquiryFilterOptions'=>collect(),'selectedCreateInquiry'=>null,'selectedCreateInquiries'=>collect(),'createInquirySelectorVersion'=>0,'canLinkInquiryOnCreate'=>false,
    'referenceNumber'=>'',
    'savedShippingAddresses'=>collect(),'savedDeliveryContacts'=>collect(),'showSavedShippingAddressPicker'=>false,'shippingSourceAddressId'=>null,'savedShippingAddressShipmentIndex'=>null,
    'phoneCountryCodeOptions'=>collect(),'shippingPhoneCountryCode'=>'+1','shippingPhone'=>'',
    'createShipmentCountries'=>collect(),'createShipmentStatesByCountry'=>collect(),'createShipmentPhoneCodes'=>collect(),'createShipmentMode'=>'multiple_shipments','createShipments'=>[],
    'shippingContactType'=>'end_customer','shippingContactId'=>null,'shippingContactSelection'=>'','shippingContactName'=>'','shippingSaveContact'=>true,
])
@php
    $selectedClient = $clients->firstWhere('id', (int)$clientId);
    $selectedWorkflow = $workflows->firstWhere('id', (int)$workflowId);
    $selectedOwnerOption = collect($ownerFilterOptions)->first(fn($item) => (int)($item['id'] ?? 0) === (int)($ownerId ?? 0));
    $activeWorkflowPhases = $selectedWorkflow?->phases?->where('is_active', true)->sortBy('sequence')->values() ?? collect();
    // Order workflows always start new Orders at the first fixed stage. Keep the
    // stage data dynamic for display, but do not expose a generic start-phase
    // selector because the Order runtime sequence remains fixed.
    $allowedPhases = $activeWorkflowPhases->take(1);
    $taskCount = $activeWorkflowPhases->sum(fn($phase) => $phase->taskPack?->items?->count() ?? 0);
    $workflowStagePreview = $activeWorkflowPhases->map(fn($phase) => [
        'sequence' => (int) $phase->sequence,
        'name' => (string) $phase->name,
        'color' => (string) ($phase->color ?: '#087f73'),
        'task_count' => (int) ($phase->taskPack?->items?->count() ?? 0),
    ])->values();
    $totalUnits = collect($jobItems)->sum(fn($item)=>(int)($item['quantity'] ?? 0));
    $createReady = $catalogReady && $assignmentReady && $workflowReady && $canUseOrderProductSelector && filled($workflowId) && filled($workflowPhaseId);
@endphp
<div {{ $attributes->class(['ft-create-job-page', 'ft-form-standard', 'ft-form-standard--order']) }} data-ft-feedback-scope="form">
    <div class="ft-create-shell">
        <div class="ft-create-breadcrumb">Orders / Create order</div>
        <div class="ft-create-title"><h1>Create new order</h1><p>Set the order scope, products, shipping, ownership and workflow.</p></div>

        <section class="ft-create-section">
            <div class="ft-create-section-title"><span>1</span><h2>Order basics</h2></div>
            <div class="ft-create-fields">
                <label class="ft-create-field"><b>Order code</b><div class="ft-locked-input">Generated automatically <span>♙</span></div></label>
                <div class="ft-create-field">
                    <x-ui.search-select
                        class="ft-create-remote-select"
                        label="Client *"
                        property="clientId"
                        type="clients"
                        context="create-job"
                        action="setCreateSelector"
                        :value="$clientId"
                        placeholder="Select client"
                        :selected-label="$selectedClient?->name"
                        :initial-options="$clientFilterOptions"
                        :clearable="false"
                        wire:key="create-client-selector-{{ $clientId ?: 'none' }}"
                    />
                    @error('clientId')<small class="validation-error">{{ $message }}</small>@enderror
                </div>
                <label class="ft-create-field"><b>Client contact</b><input value="{{ $selectedClient?->contact_name ?? 'No contact recorded' }}" readonly></label>
                <label class="ft-create-field"><b>Client Reference Number *</b><input wire:model.live.debounce.300ms="referenceNumber" required aria-required="true" placeholder="e.g. FO-333119 or customer PO number">@error('referenceNumber')<small class="validation-error">{{ $message }}</small>@enderror</label>
                @if($canLinkInquiryOnCreate)
                    <x-jobs.create.inquiry-link
                        :selected-inquiries="$selectedCreateInquiries"
                        :initial-options="$createInquiryFilterOptions"
                        :client-id="$clientId"
                        :selector-version="$createInquirySelectorVersion"
                    />
                @endif
                <div class="ft-create-field ft-repeat-order-option">
                    <b>Repeated order</b>
                    <label class="ft-repeat-order-check">
                        <input type="checkbox" wire:model.live="isRepeatedOrder">
                        <span>Is this a repeated order?</span>
                    </label>
                    @error('isRepeatedOrder')<small class="validation-error">{{ $message }}</small>@enderror
                </div>
                @if($isRepeatedOrder)
                    <label class="ft-create-field" wire:key="repeated-order-number-field">
                        <b>Previous reference number *</b>
                        <input wire:model="repeatedOrderNumber" placeholder="Enter the previous order reference number">
                        @error('repeatedOrderNumber')<small class="validation-error">{{ $message }}</small>@enderror
                    </label>
                @endif
                <div class="ft-create-field ft-mention-host"><b>Request description</b><textarea class="ft-mention-input" data-rich-text wire:model="description" rows="4" autocomplete="off" data-mention-users="{{ $mentionUsers->toJson() }}" placeholder="Type @ to mention a user. Add specifications, target price or customization requirements..."></textarea>@error('description')<small class="validation-error">{{ $message }}</small>@enderror</div>
            </div>
        </section>

        <x-jobs.create.shipping-setup
            :shipments="$createShipments"
            :mode="$createShipmentMode"
            :countries="$createShipmentCountries"
            :states-by-country="$createShipmentStatesByCountry"
            :phone-codes="$createShipmentPhoneCodes"
            :saved-shipping-addresses="$savedShippingAddresses"
            :show-saved-shipping-address-picker="$showSavedShippingAddressPicker"
            :saved-shipping-address-shipment-index="$savedShippingAddressShipmentIndex"
            :reference-number="$referenceNumber"
        />

        {{--
            Backward compatibility: stale Livewire snapshots from the previous
            single-address Create Order implementation may still reference these
            reusable components/state names after a deployment:
            <x-jobs.create.shipping-contact :saved-delivery-contacts="$savedDeliveryContacts" :shipping-contact-selection="$shippingContactSelection" />
            <x-jobs.create.shipping-method-picker />
        --}}

        @include('components.jobs.create-products')

        @if($assignmentReady)
        <section class="ft-create-section" wire:key="create-assignment-ready">
            <div class="ft-create-section-title"><span>4</span><h2>Schedule & owner</h2></div>
            <div class="ft-create-fields">
                    {{-- CHANGE 2026-08-24:
                    kept the optional Hand Date field for Create Order
                    and removed Estimated Delivery from this form only. --}}
                <label
                    class="ft-create-field ft-clickable-date-field"
                    x-data
                    x-on:click="if (!$event.target.closest('.validation-error')) { $refs.deliveryDate?.showPicker?.(); $refs.deliveryDate?.focus(); }"
                >
                    <b>Hand Date</b>

                    <input
                        x-ref="deliveryDate"
                        type="date"
                        wire:model="deliveryDate"
                    >

                    @error('deliveryDate')
                        <small class="validation-error">
                            {{ $message }}
                        </small>
                    @enderror
                </label>
                <x-jobs.create.shipping-method-picker
                    :shipment-methods="$shipmentMethods"
                    :shipment-urgencies="$shipmentUrgencies"
                    :selected-method-ids="$shipmentMethodIds"
                    :selected-urgency-ids="$shipmentUrgencyIds"
                    :required="true"
                    help-text="Applied automatically to every shipment address. You can change a shipment's method later in the Shipment stage."
                />
                <div class="ft-create-field">
                    <x-ui.search-select
                        class="ft-create-remote-select"
                        label="Order owner *"
                        property="ownerId"
                        type="users"
                        context="create-job"
                        action="setCreateSelector"
                        :value="$ownerId"
                        placeholder="Select owner"
                        :selected-label="$selectedOwnerOption['label'] ?? null"
                        :initial-options="$ownerFilterOptions"
                        :clearable="false"
                        wire:key="create-owner-selector"
                    />
                    <small>Accountable for overall delivery.</small>
                    @error('ownerId')<small class="validation-error">{{ $message }}</small>@enderror
                </div>
            </div>
        </section>
        @else
            <x-jobs.create-section-placeholder number="4" title="Schedule & owner" section="assignment" :rows="5" />
        @endif

        <x-jobs.create.documents
            :purchase-order-upload="$purchaseOrderUpload"
            :job-attachments="$jobAttachments"
            :can-upload="auth()->user()->canModule('documents','create')"
        />

        @if($workflowReady)
            <x-ui.create-workflow-picker
                class="ft-create-section ft-order-workflow-create-section"
                step="6"
                title="What happens next"
                :workflow-options="$workflowFilterOptions"
                :selected-workflow-id="$workflowId"
                :selected-workflow-name="$selectedWorkflow?->name ?? 'Select workflow'"
                :phase-count="$selectedWorkflow?->phases?->where('is_active', true)->count() ?? 0"
                :task-count="$taskCount"
                selection-property="workflowId"
                option-fallback="Order workflow"
                footnote="Tasks are created when you select Create order. Workflow and starting phase are fixed after creation."
                :preview-allowed="true"
                :preview-default-open="true"
                availability-label="workflow available"
                icon="workflow"
                :stage-preview="$workflowStagePreview"
                kind-label="Order workflow"
                source-label="Workflow Setup"
                stage-noun="stage"
                option-empty-message="No active, complete Order workflow is available from Workflow Setup. Configure the Order workflow and its Task Packs first, then select it here."
                :setup-url="auth()->user()->canAccess('workflow.view') ? route('workflow.setup', $workflowId ? ['workflow' => $workflowId] : []) : null"
                setup-label="Open Workflow Setup"
                error-field="workflowId"
                :start-phases="$allowedPhases"
                :start-phase-id="$workflowPhaseId"
                start-phase-property="workflowPhaseId"
                start-phase-error-field="workflowPhaseId"
                :selectable="true"
                wire:key="create-order-workflow-picker-{{ $clientId ?: 'none' }}-{{ $workflowSelectorVersion }}-{{ $workflowId ?: 'none' }}"
            />
        @else
            <x-jobs.create-section-placeholder number="6" title="What happens next" section="workflow" :rows="2" />
        @endif

        <div class="ft-create-actions">
            <button type="button" class="ft-create-cancel" wire:click="closeCreate">Cancel</button>
            <button type="button" class="ft-create-draft" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="purchaseOrderUpload,jobAttachments,saveDraft" @disabled(!$createReady)>Save draft</button>
            <button type="button" class="ft-create-primary" wire:click="createJob" wire:loading.attr="disabled" wire:target="purchaseOrderUpload,jobAttachments,createJob" @disabled(!$createReady)>Create order</button>
        </div>
        @error('createLoading')<div class="validation-error">{{ $message }}</div>@enderror
    </div>
</div>
