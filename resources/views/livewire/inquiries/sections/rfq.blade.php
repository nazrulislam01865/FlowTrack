<div class="ft-rfq-pane ft-rfq-product-pane" wire:key="inquiry-rfq-pane-{{ $selectedInquiry->id }}">
    <x-inquiries.rfq-product-workspace
        :workspace="$rfqWorkspace ?? []"
        :can-manage="$canManageInquiryRfq"
        :can-edit-suppliers="$canEditSuppliers"
    />

    @if($showRfqSupplierPicker && $canManageInquiryRfq)
        <x-inquiries.rfq-add-supplier-modal
            :candidates="$rfqSupplierCandidates"
            :products="$rfqAssignableProducts ?? collect()"
            :selected-product-id="$rfqSupplierProductId"
        />
    @endif
</div>
