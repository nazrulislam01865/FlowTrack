@props([
    'job',
    'detailTab',
    'expandedPhaseIds'=>[],
    'taskStatuses'=>collect(),
    'users'=>collect(),
    'mentionUsers'=>collect(),
    'priorities'=>collect(),
    'shipmentUrgencyOptions'=>collect(),
    'overviewPhaseId'=>null,
    'orderDetailContext'=>[],
    'orderDetailSectionsReady'=>[],
    'orderRedoContext'=>[],
    'orderRedoForm'=>[],
    'products'=>collect(),
    'categories'=>collect(),
    'showAddJobProductForm'=>false,
    'jobProductSearch'=>'',
    'jobProductSearchResults'=>collect(),
    'jobProductSearchSuppliers'=>collect(),
    'jobProductResultTotal'=>0,
    'jobProductShowAllResults'=>false,
    'jobProductSelectedProduct'=>null,
    'jobProductSelectedSupplier'=>null,
    'jobProductCategory'=>'',
    'jobProductQuantity'=>'1000',
    'jobProductUnitPrice'=>'0.00',
    'jobProductSupplierId'=>null,
    'jobProductSupplierLabel'=>'',
    'jobProductSupplierSkipped'=>false,
    'jobProductSupplierLocked'=>false,
    'showEditOrderProductModal'=>false, 'editOrderProductItemId'=>null, 'editOrderProductName'=>'', 'editOrderProductCode'=>'',
    'editOrderProductCategory'=>'', 'editOrderProductSearch'=>'', 'editOrderProductSearchResults'=>collect(),
    'editOrderProductSearchSuppliers'=>collect(), 'editOrderProductResultTotal'=>0, 'editOrderProductSelectedProduct'=>null,
    'editOrderProductSelectedSupplier'=>null, 'editOrderProductShowAllResults'=>false,
    'editOrderProductSupplierId'=>null, 'editOrderProductSupplierLabel'=>'', 'editOrderProductQuantity'=>'1',
    'editOrderProductUnitPrice'=>'0.00', 'editOrderProductNotes'=>'',
    'availableDocuments'=>collect(),
    'overviewTaskDocumentModalTask'=>null,
    'overviewTaskAvailableDocuments'=>collect(),
    'showOverviewTaskDocumentModal'=>false,
    'showAddOrderTaskForm'=>false,
    'newOrderTaskAssigneeId'=>null,
    'overviewTaskDocumentSource'=>'upload',
    'overviewTaskDocumentUpload'=>null,
    'overviewTaskRevisionUpload'=>[],
    'overviewTaskStagedUploads'=>[],
    'overviewTaskStagedRevisionUploads'=>[],
    'overviewTaskExistingDocumentId'=>null,
    'overviewTaskArtworkRevision'=>[],
    'overviewTaskRevisionDocumentIds'=>[],
    'overviewTaskLinkFormTaskId'=>null,
    'jobTaskSearch'=>'',
    'activityTab'=>'all',
    'activityPage'=>1,
    'focusComment'=>null,
    'showOrderAttentionModal'=>false,
    'orderAttentionReason'=>'',
    'showOrderHoldModal'=>false,
    'orderHoldFrom'=>'client',
    'orderHoldReason'=>'',
    'showOrderCancelModal'=>false,
    'orderCancellationReason'=>'',
    'jobDocumentUploads'=>[],
    'jobRequiredDocumentUpload'=>null,
    'jobDocumentTaskId'=>null,
    'showDocumentPicker'=>false,
    'lastJobDocumentUploadId'=>null,
    'lastJobDocumentTaskId'=>null,
    'inquiryResults'=>collect(),
    'inquirySearch'=>'',
    'selectedLinkInquiry'=>null,
    'showInquiryLinkConfirm'=>false,
    'showInquiryUnlinkConfirm'=>false,
    'unlinkInquiryId'=>null,
    'canManageInquiryLink'=>false,
    'linkedInquiryCanOpen'=>false,
    'canViewLinkedInquiryDocuments'=>false,
    'canExportLinkedInquiryDocuments'=>false,
    'financeSummary'=>null,
    'financeContacts'=>null,
    'financeUsers'=>null,
    'financeInvoiceTypes'=>collect(),
    'financeCurrencies'=>collect(),
    'financePaymentTerms'=>collect(),
    'financePaymentMethods'=>collect(),
    'financeReceivedAccounts'=>collect(),
    'canCreateFinance'=>false,
    'canEditFinance'=>false,
    'canViewFinance'=>false,
    'showCreateInvoiceModal'=>false,
    'invoiceType'=>'Final invoice',
    'invoiceCurrency'=>'USD',
    'invoiceIssueDate'=>'',
    'invoicePaymentTerms'=>'Net 15 days',
    'invoiceDueDate'=>'',
    'invoiceBillingContactId'=>null,
    'invoiceLineItems'=>[],
    'invoicePurchaseOrderReference'=>'',
    'invoiceNotes'=>'',
    'invoiceTaxRate'=>'0',
    'invoiceRemoteAreaCharge'=>0,
    'invoiceRemoteAreaName'=>'',
    'invoiceRemoteAreaPostalCode'=>'',
    'invoiceSupportingDocument'=>null,
    'invoiceEmailAfterCreation'=>false,
    'showRecordPaymentModal'=>false,
    'paymentInvoiceId'=>null,
    'paymentDate'=>'',
    'paymentMethod'=>'Bank transfer',
    'paymentAmount'=>'',
    'paymentReference'=>'',
    'paymentNotes'=>'',
    'paymentReceipt'=>null,
    'showCollectionUpdateModal'=>false,
    'collectionOwnerId'=>null,
    'collectionFollowUpDate'=>'',
    'collectionNextFollowUpDate'=>'',
    'collectionNote'=>'',
    'showOrderWorkflowActionModal'=>false,
    'orderWorkflowActionTaskId'=>null,
    'orderWorkflowActionStep'=>'main',
    'orderWorkflowActionPayload'=>[],
    'orderWorkflowActionModalPreview'=>[],
    'orderWorkflowActionAttachment'=>null,
    'orderWorkflowActionRevisionComments'=>[],
    'orderWorkflowActionRevisionAttachments'=>[],
    'orderWorkflowEmailFallback'=>false,
    'orderWorkflowEmailFallbackMessage'=>'',
    'orderWorkflowEmailFallbackAttempts'=>0,
    'showShipmentModal'=>false,
    'shipmentModalTaskId'=>null,
    'shipmentEditingId'=>null,
    'shipmentModalMode'=>'same_address',
    'shipmentForm'=>[],
    'shipmentInlineTaskId'=>null,
    'shipmentInlineEditingId'=>null,
    'shipmentInlineAddressMode'=>\App\Services\OrderShipmentService::MODE_SAME_ADDRESS,
    'shipmentInlineForm'=>[],
    'showShipmentDetailsModal'=>false,
    'shipmentDetailsId'=>null,
])
@php
    $manualAttention = (bool) ($job->attention_requested ?? false);
    $orderIsOnHold = (bool) ($orderDetailContext['isOnHold'] ?? false);
@endphp
<div
    {{ $attributes->class('ft-job-detail-page ft-order-prototype-detail ft-detail-products-scope') }}
    x-data="Object.assign(window.FlowTrack.ui.orderHoldGuard({ held: @js($orderIsOnHold) }), { redoNotice: '', redoNoticeOpen: false, showRedoNotice(message) { this.redoNotice = message; this.redoNoticeOpen = true; clearTimeout(this.__redoNoticeTimer); this.__redoNoticeTimer = setTimeout(() => this.redoNoticeOpen = false, 2600); } })"
    x-on:order-redo-notice.window="showRedoNotice($event.detail.message ?? 'Redo update saved.')"
    x-on:flowtrack:order-held-blocked.window="showHoldBlocked($event.detail?.action ?? '')"
    x-on:flowtrack:order-hold-state.window="held = Boolean($event.detail?.held); if (!held) closeHoldBlocked()"
    x-on:click.capture="guardInteraction($event)"
    x-on:focusin.capture="guardInteraction($event)"
    x-on:change.capture="guardInteraction($event)"
    x-on:submit.capture="guardInteraction($event)"
>
    <x-jobs.order-detail.header
        :job="$job"
        :context="$orderDetailContext"
        :shipment-urgency-options="$shipmentUrgencyOptions"
        :redo-context="$orderRedoContext"
    />
    <x-jobs.order-detail.tabs
        :job="$job"
        :detail-tab="$detailTab"
        :can-view-finance="$canViewFinance"
        :can-create-finance="$canCreateFinance"
        :redo-context="$orderRedoContext"
    />

    <x-jobs.order-detail.redo-banner :job="$job" :context="$orderRedoContext" />

    @if($detailTab==='overview')
        <x-jobs.detail-overview
            :job="$job"
            :mention-users="$mentionUsers"
            :shipment-urgency-options="$shipmentUrgencyOptions"
            :order-detail-context="$orderDetailContext"
            :focus-comment="$focusComment"
        />
    @elseif($detailTab==='inquiry')
        <x-jobs.detail-inquiry
            :job="$job"
            :results="$inquiryResults"
            :search="$inquirySearch"
            :selected-inquiry="$selectedLinkInquiry"
            :show-link-confirm="$showInquiryLinkConfirm"
            :show-unlink-confirm="$showInquiryUnlinkConfirm"
            :unlink-inquiry-id="$unlinkInquiryId"
            :can-manage="$canManageInquiryLink"
            :linked-inquiry-can-open="$linkedInquiryCanOpen"
            :can-view-linked-inquiry-documents="$canViewLinkedInquiryDocuments"
            :can-export-linked-inquiry-documents="$canExportLinkedInquiryDocuments"
        />
    @elseif($detailTab==='redo' && (bool) ($orderRedoContext['hasRedo'] ?? false))
        <x-jobs.order-detail.redo-panel :job="$job" :context="$orderRedoContext" />

        {{--
            Keep the standard Order Activity feed beneath the Redo cards,
            matching the approved Redo prototype. This is the same source used
            on Overview, so comments/history stay in one authoritative audit
            stream instead of creating a separate Redo-only activity store.
        --}}
        <x-jobs.order-detail.activity
            :job="$job"
            :mention-users="$mentionUsers"
            :activity-tab="$activityTab"
            :activity-page="$activityPage"
            :focus-comment="$focusComment"
            :can-comment="(bool) ($orderDetailContext['canComment'] ?? false)"
        />
    @elseif($detailTab==='finance')
        <x-jobs.finance.detail
            :job="$job"
            :summary="$financeSummary"
            :redo-context="$orderRedoContext"
            :contacts="$financeContacts ?? collect()"
            :users="$financeUsers ?? collect()"
            :invoice-types="$financeInvoiceTypes ?? collect()"
            :currencies="$financeCurrencies ?? collect()"
            :payment-terms="$financePaymentTerms ?? collect()"
            :payment-methods="$financePaymentMethods ?? collect()"
            :received-accounts="$financeReceivedAccounts ?? collect()"
            :can-create="$canCreateFinance"
            :can-edit="$canEditFinance"
            :show-create-invoice-modal="$showCreateInvoiceModal"
            :invoice-type="$invoiceType"
            :invoice-currency="$invoiceCurrency"
            :invoice-issue-date="$invoiceIssueDate"
            :invoice-payment-terms="$invoicePaymentTerms"
            :invoice-due-date="$invoiceDueDate"
            :invoice-billing-contact-id="$invoiceBillingContactId"
            :invoice-line-items="$invoiceLineItems"
            :invoice-purchase-order-reference="$invoicePurchaseOrderReference"
            :invoice-notes="$invoiceNotes"
            :invoice-tax-rate="$invoiceTaxRate"
            :invoice-remote-area-charge="$invoiceRemoteAreaCharge"
            :invoice-remote-area-name="$invoiceRemoteAreaName"
            :invoice-remote-area-postal-code="$invoiceRemoteAreaPostalCode"
            :invoice-supporting-document="$invoiceSupportingDocument"
            :invoice-email-after-creation="$invoiceEmailAfterCreation"
            :show-record-payment-modal="$showRecordPaymentModal"
            :payment-invoice-id="$paymentInvoiceId"
            :payment-date="$paymentDate"
            :payment-method="$paymentMethod"
            :payment-amount="$paymentAmount"
            :payment-reference="$paymentReference"
            :payment-notes="$paymentNotes"
            :payment-receipt="$paymentReceipt"
            :show-collection-update-modal="$showCollectionUpdateModal"
            :collection-owner-id="$collectionOwnerId"
            :collection-follow-up-date="$collectionFollowUpDate"
            :collection-next-follow-up-date="$collectionNextFollowUpDate"
            :collection-note="$collectionNote"
        />
    @endif


    <x-jobs.order-detail.redo-modal :job="$job" :context="$orderRedoContext" :form="$orderRedoForm" :mention-users="$mentionUsers" />

    @if($showOrderHoldModal)
        <x-jobs.order-detail.hold-modal
            :job="$job"
            :hold-from="$orderHoldFrom"
            :reason="$orderHoldReason"
            :mention-users="$mentionUsers"
        />
    @endif

    @if($orderIsOnHold)
        <x-jobs.order-detail.hold-blocked-modal
            :hold="$orderDetailContext['hold'] ?? null"
            :can-release-hold="(bool) ($orderDetailContext['canReleaseHold'] ?? false)"
            :order-id="$job->id"
            :direct-release="true"
        />
    @endif

    <div class="ft-redo-toast" x-cloak x-show="redoNoticeOpen" x-transition x-text="redoNotice" role="status" aria-live="polite"></div>

    @if($showOrderCancelModal)
        <div
            class="ft-order-modal-backdrop"
            wire:key="order-cancel-modal"
            wire:click.self="closeOrderCancelModal"
        >
            <section
                class="ft-order-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="order-cancel-modal-title"
                x-data="{ cancelling: false }"
            >
                <header>
                    <div>
                        <h2 id="order-cancel-modal-title">Cancel Order</h2>

                        <p>
                            {{ $job->displayOrderNumber() }}
                            · cancellation is available through the QC stage.
                        </p>
                    </div>

                    <button
                        type="button"
                        wire:click="closeOrderCancelModal"
                        aria-label="Close"
                    >
                        ×
                    </button>
                </header>

                <div class="ft-order-modal-body ft-mention-host">
                    <div class="ft-order-critical-note">
                        <strong>This stops workflow progression.</strong>

                        <span>
                            Open tasks are marked cancelled.
                            Existing documents, products, history,
                            and audit records are retained.
                        </span>
                    </div>

                    <label for="order-cancellation-reason">
                        Cancellation reason *
                    </label>

                    <textarea
                        id="order-cancellation-reason"
                        x-ref="cancelReason"
                        class="ft-mention-input"
                        data-rich-text
                        wire:model="orderCancellationReason"
                        rows="5"
                        autocomplete="off"
                        data-mention-users="{{ json_encode(
                            collect($mentionUsers)->values()->all(),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ) }}"
                        placeholder="Explain why this Order is being cancelled. Type @ to mention someone, paste text, or paste an image..."
                    ></textarea>

                    @error('orderCancellationReason')
                        <p class="validation-error">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <footer>
                    <button
                        type="button"
                        class="secondary"
                        wire:click="closeOrderCancelModal"
                        x-bind:disabled="cancelling"
                    >
                        Keep Order
                    </button>

                    <button
                        type="button"
                        class="danger"
                        x-bind:disabled="cancelling"
                        x-on:click="
                            if (cancelling) return;

                            cancelling = true;

                            (async () => {
                                try {
                                    const input = $refs.cancelReason;

                                    const value =
                                        input?.__flowtrackRichTextValueAsync
                                            ? await input.__flowtrackRichTextValueAsync()
                                            : (input?.value ?? '');

                                    await $wire.confirmOrderCancellation(value);
                                } finally {
                                    cancelling = false;
                                }
                            })();
                        "
                    >
                        <span x-show="!cancelling">
                            Cancel Order
                        </span>

                        <span
                            x-cloak
                            x-show="cancelling"
                        >
                            Cancelling...
                        </span>
                    </button>
                </footer>
            </section>
        </div>
    @endif

    @if($showOrderAttentionModal)
        <div class="ft-inquiry-attention-modal-backdrop" wire:key="order-attention-modal" wire:click.self="closeOrderAttentionReason">
            <section class="ft-inquiry-attention-modal" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-labelledby="order-attention-modal-title">
                <header class="ft-inquiry-attention-modal-head">
                    <div>
                        <h2 id="order-attention-modal-title">Request attention</h2>
                        <p>{{ $job->displayOrderNumber() }} · Admin, Super Admin and the Order creator will be notified.</p>
                    </div>
                    <button type="button" class="ft-inquiry-attention-modal-close" wire:click="closeOrderAttentionReason" aria-label="Close">×</button>
                </header>
                <div class="ft-inquiry-attention-modal-body ft-mention-host">
                    <label for="order-attention-reason">Reason for flag *</label>
                    <textarea id="order-attention-reason" class="ft-mention-input" wire:model="orderAttentionReason" rows="5" maxlength="2000" autocomplete="off" data-mention-users="{{ json_encode(collect($mentionUsers)->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}" placeholder="Explain what needs attention. Type @ to mention a user..."></textarea>
                    @error('orderAttentionReason')<p class="ft-inquiry-attention-modal-error">{{ $message }}</p>@enderror
                    <p class="ft-inquiry-attention-modal-help">The reason is added to Order comments. Use <b>@</b> to mention specific users in addition to the automatic Admin/Super Admin/creator notification.</p>
                </div>
                <footer class="ft-inquiry-attention-modal-actions">
                    @if($manualAttention)<button type="button" class="ft-inquiry-attention-clear" wire:click="clearOrderAttention" wire:loading.attr="disabled" wire:target="clearOrderAttention">Clear flag</button>@else<span></span>@endif
                    <div>
                        <button type="button" class="secondary" wire:click="closeOrderAttentionReason">Cancel</button>
                        <button type="button" class="primary" wire:click="saveOrderAttentionReason" wire:loading.attr="disabled" wire:target="saveOrderAttentionReason">
                            <span wire:loading.remove wire:target="saveOrderAttentionReason">Request attention</span>
                            <span wire:loading wire:target="saveOrderAttentionReason">Saving...</span>
                        </button>
                    </div>
                </footer>
            </section>
        </div>
    @endif
</div>
