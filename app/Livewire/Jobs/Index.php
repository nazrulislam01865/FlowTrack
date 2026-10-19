<?php

namespace App\Livewire\Jobs;

use App\Livewire\Jobs\Concerns\ManagesOrderCreation;
use App\Livewire\Jobs\Concerns\ManagesCreateOrderShipments;
use App\Livewire\Jobs\Concerns\ManagesCreateOrderProducts;
use App\Livewire\Jobs\Concerns\ManagesOrderNavigation;
use App\Livewire\Jobs\Concerns\ManagesOrderFinance;
use App\Livewire\Jobs\Concerns\ManagesOrderInquiryLink;
use App\Livewire\Jobs\Concerns\ManagesOrderWorkflow;
use App\Livewire\Jobs\Concerns\ManagesOrderShipments;
use App\Livewire\Jobs\Concerns\ManagesOrderDetail;
use App\Livewire\Jobs\Concerns\ManagesOrderProducts;
use App\Livewire\Jobs\Concerns\ManagesOrderTasks;
use App\Livewire\Jobs\Concerns\ManagesOrderDocuments;
use App\Livewire\Jobs\Concerns\ManagesOrderTaskResources;
use App\Livewire\Jobs\Concerns\ManagesOrderActivity;
use App\Livewire\Jobs\Concerns\ManagesOrderHold;
use App\Livewire\Jobs\Concerns\ManagesOrderRedo;
use App\Livewire\Jobs\Concerns\ManagesDetailProgressiveLoading;
use App\Livewire\Jobs\Concerns\BuildsOrderPageData;
use App\Livewire\Jobs\Concerns\HandlesMissingProductSupplierContext;

use App\Livewire\Concerns\HandlesInlineEdits;
use App\Livewire\Concerns\ManagesMissingProductSupplier;
use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;

use App\Models\Task;
use App\Services\AccessControlService;
use App\Services\ClientService;
use App\Services\TaskService;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use ManagesCreateOrderShipments;
    use ManagesOrderCreation;
    use ManagesCreateOrderProducts;
    use ManagesOrderNavigation;
    use ManagesOrderFinance;
    use ManagesOrderInquiryLink;
    use ManagesOrderWorkflow;
    use ManagesOrderShipments;
    use ManagesOrderDetail;
    use ManagesOrderProducts;
    use ManagesOrderTasks;
    use ManagesOrderDocuments;
    use ManagesOrderTaskResources;
    use ManagesOrderActivity;
    use ManagesOrderHold;
    use ManagesOrderRedo;
    use ManagesDetailProgressiveLoading;
    use BuildsOrderPageData;
    use HandlesMissingProductSupplierContext;

    use ManagesMissingProductSupplier;
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use HandlesInlineEdits;
    use WithPagination, WithFileUploads;

    public string $search = '';
    public string $phase = '';
    public string $client = '';
    public string $owner = '';
    public string $assignee = '';
    public string $delivery = '';
    public string $invoice = '';
    public string $priorityFilter = '';
    public string $jobStatusFilter = '';
    public string $sort = 'updated_desc';
    public bool $showMoreFilters = false;
    public int $perPage = 25;
    public array $selectedJobIds = [];

    #[Url(as: 'open', history: true)]
    public ?int $selectedJobId = null;

    #[Url(as: 'task', history: true)]
    public ?int $selectedTaskId = null;

    #[Url(as: 'comment', history: true)]
    public ?string $focusComment = null;
    public bool $taskEditMode = false;
    public string $detailTab = 'overview';
    public string $inquirySearch = '';
    public ?int $selectedLinkInquiryId = null;
    public bool $showInquiryLinkConfirm = false;
    public bool $showInquiryUnlinkConfirm = false;
    public ?int $unlinkInquiryId = null;

    public bool $showCreateInvoiceModal = false;
    public string $invoiceType = '';
    public string $invoiceCurrency = '';
    public string $invoiceIssueDate = '';
    public string $invoicePaymentTerms = '';
    public string $invoiceDueDate = '';
    public ?int $invoiceBillingContactId = null;
    public array $invoiceLineItems = [];
    public string $invoicePurchaseOrderReference = '';
    public string $invoiceNotes = 'Please include the invoice number with your payment.';
    public string $invoiceTaxRate = '0';
    public float $invoiceRemoteAreaCharge = 0.0;
    public string $invoiceRemoteAreaName = '';
    public string $invoiceRemoteAreaPostalCode = '';
    public $invoiceSupportingDocument = null;
    public bool $invoiceEmailAfterCreation = false;

    public bool $showRecordPaymentModal = false;
    public ?int $paymentInvoiceId = null;
    public string $paymentDate = '';
    public string $paymentMethod = '';
    public string $paymentAmount = '';
    public string $paymentReference = '';
    public string $paymentReceivedAccount = '';
    public $paymentReceipt = null;
    public string $paymentNotes = '';
    public bool $paymentMarkInvoicePaid = true;

    public bool $showCollectionUpdateModal = false;
    public ?int $collectionOwnerId = null;
    public string $collectionFollowUpDate = '';
    public string $collectionNextFollowUpDate = '';
    public string $collectionNote = '';

    public array $expandedPhaseIds = [];
    /** Selected historical/current stage shown in the prototype task panel. */
    public ?int $overviewPhaseId = null;
    /** Last current workflow stage observed while this Order detail is open. */
    public ?int $lastOverviewWorkflowPhaseId = null;
    public bool $showOrderWorkflowActionModal = false;
    public ?int $orderWorkflowActionTaskId = null;
    public string $orderWorkflowActionComment = '';
    /** Legacy single revision attachment state kept for non-revision compatibility. */
    public $orderWorkflowActionAttachment = null;
    /** Per-artwork required-change text keyed by source document id. */
    public array $orderWorkflowActionRevisionComments = [];
    /** Per-artwork supporting uploads keyed by source document id. */
    public array $orderWorkflowActionRevisionAttachments = [];
    public string $orderWorkflowActionStep = 'main';
    /** @var array<string,mixed> */
    public array $orderWorkflowActionPayload = [];
    /** Expensive workflow modal email/invoice preview snapshot; prepared outside Blade. */
    public array $orderWorkflowActionModalPreview = [];
    /** Email-handoff fallback shown only after the synchronous provider fails three times. */
    public bool $orderWorkflowEmailFallback = false;
    public string $orderWorkflowEmailFallbackMessage = '';
    public int $orderWorkflowEmailFallbackAttempts = 0;
    /** Immediate per-task resend result shown beside the completed Artwork email handoff. */
    public array $orderWorkflowEmailResendFeedback = [];
    public string $jobTaskSearch = '';
    public bool $showCreate = false;
    public bool $createCatalogReady = false;
    public bool $createAssignmentReady = false;
    public bool $createWorkflowReady = false;
    public int $createWorkflowSelectorVersion = 0;

    public string $referenceNumber = '';
    public bool $isRepeatedOrder = false;
    public string $repeatedOrderNumber = '';
    public string $priority = 'Medium';
    public array $productionUrgencyIds = [];
    public array $shipmentMethodIds = [];
    public array $shipmentUrgencyIds = [];
    /** Create Order shipment-plan mode shown by the Shipping setup prototype. */
    public string $createShipmentMode = self::CREATE_SHIPMENT_MODE_MULTIPLE;
    /** @var array<int,array<string,mixed>> */
    public array $createShipments = [];
    public ?int $savedShippingAddressShipmentIndex = null;
    public ?int $clientId = null;
    /** Temporary remote-selector value used to add one Inquiry at a time. */
    public ?int $createInquiryId = null;
    /** Ordered Inquiry ids selected for the new Order. The first remains the legacy primary/source Inquiry. */
    public array $createInquiryIds = [];
    /** Cached server-validated display rows keyed by Inquiry id; prevents repeat DB lookups on normal form rerenders. */
    public array $createInquirySelections = [];
    /** Cached picker copy kept for backward-compatible Livewire snapshots while the multi-link UI is active. */
    public string $createInquiryLabel = '';
    public string $createInquiryMeta = '';
    /** Re-key the add-Inquiry selector after add/remove so optimistic Alpine state cannot retain a stale choice. */
    public int $createInquirySelectorVersion = 0;
    /** When set, the next picker selection replaces this selected Inquiry instead of appending. */
    public ?int $createInquiryReplaceId = null;
    public ?int $workflowId = null;
    public ?int $workflowPhaseId = null;
    public ?int $ownerId = null;
    public ?int $coordinatorId = null;
    public string $deliveryDate = '';
    public string $estimatedDeliveryDate = '';
    public string $description = '';
    public const DEFAULT_SHIPPING_PHONE_COUNTRY_CODE = '+1';

    public string $shippingAddress = '';
    public string $shippingPhoneCountryCode = self::DEFAULT_SHIPPING_PHONE_COUNTRY_CODE;
    public string $shippingPhone = '';
    public string $shippingPostalCode = '';
    public string $shippingContactType = 'end_customer';
    public ?int $shippingContactId = null;
    public string $shippingContactSelection = '';
    public string $shippingContactName = '';
    public bool $shippingSaveContact = true;
    /** @var array<string,array{contact_id:?int,selection:string,name:string,country_code:string,phone:string,save_contact:bool}> */
    public array $shippingContactDrafts = [];
    public ?int $shippingSourceAddressId = null;
    public bool $showSavedShippingAddressPicker = false;
    public array $jobItems = [];
    public string $createProductSearch = '';
    public string $createProductCategoryFilter = '';
    public bool $createProductShowAllResults = false;
    public bool $showCreateOrderProductModal = false;
    /** @var array<int,int> Product IDs explicitly allowed without a supplier for this Create Order draft. */
    public array $createOrderSupplierSkipProductIds = [];
    /** @var array<int,int> Product ID => Supplier ID explicitly chosen for this Order only. */
    public array $createOrderSupplierOverrides = [];

    // Order Details > Add another product flow. Kept separate from Create Order
    // state so opening the detail picker never mutates the create-order draft.
    public bool $showAddJobProductForm = false;
    public string $jobProductSearch = '';
    public bool $jobProductShowAllResults = false;
    public ?int $jobProductSelectedId = null;
    public string $jobProductCategory = '';
    public string $jobProductQuantity = '1';
    public string $jobProductUnitPrice = '0.00';
    public ?int $jobProductSupplierId = null;
    public string $jobProductSupplierLabel = '';
    /** Explicit detail-flow opt-out matching Create Order's temporary supplier skip. */
    public bool $jobProductSupplierSkipped = false;
    public bool $jobProductSupplierLocked = false;

    // Order Details > prototype product editor. The modal mirrors the supplied
    // prototype while keeping persistence and validation in the backend.
    public bool $showEditOrderProductModal = false;
    public ?int $editOrderProductItemId = null;
    public ?int $editOrderProductSelectedId = null;
    public string $editOrderProductSearch = '';
    public bool $editOrderProductShowAllResults = false;
    public string $editOrderProductName = '';
    public string $editOrderProductCode = '';
    public string $editOrderProductCategory = '';
    public ?int $editOrderProductSupplierId = null;
    public string $editOrderProductSupplierLabel = '';
    public string $editOrderProductQuantity = '1';
    public string $editOrderProductUnitPrice = '0.00';
    public string $editOrderProductNotes = '';
    public string $newProductCode = '';
    public ?int $newProductCategoryId = null;
    public string $newProductCategorySearch = '';
    public string $newProductCategoryName = '';
    public string $newProductName = '';
    public ?int $newProductSupplierId = null;
    public $newProductImage = null;
    /** Purchase Order selected during Create Order. Stored against NEW_UPLOAD_PO after the Order exists. */
    public $purchaseOrderUpload = null;
    public array $jobAttachments = [];
    public array $jobDocumentUploads = [];
    public $jobRequiredDocumentUpload = null;
    public ?int $jobDocumentTaskId = null;
    public ?int $existingDocumentId = null;
    public bool $showDocumentPicker = false;
    public ?int $lastJobDocumentUploadId = null;
    public ?int $lastJobDocumentTaskId = null;

    public string $taskStatus = 'Ready';
    public ?int $taskAssigneeId = null;
    public int $taskProgress = 0;
    public bool $taskAttention = false;
    public string $taskAttentionReason = '';
    public string $taskComment = '';
    public string $newChecklistItem = '';
    public string $taskActivityTab = 'all';
    public int $taskActivityPage = 1;
    public string $jobComment = '';
    public string $jobActivityTab = 'all';
    public int $jobActivityPage = 1;
    public bool $showOrderAttentionModal = false;
    public string $orderAttentionReason = '';
    public bool $showOrderHoldModal = false;
    public string $orderHoldFrom = 'client';
    public string $orderHoldReason = '';
    public bool $showOrderCancelModal = false;
    public string $orderCancellationReason = '';
    public int $activityPerPage = 30;
    public array $taskDocumentUploads = [];
    /** Per-task temporary uploads used by the compact Order Overview taskflow. */
    public array $overviewTaskUploads = [];
    public bool $showOverviewTaskDocumentModal = false;
    public ?int $overviewTaskDocumentModalTaskId = null;
    public string $overviewTaskDocumentSource = 'upload';
    /** Files selected in the Order Details workflow upload modal. */
    public array $overviewTaskDocumentUpload = [];
    /** Replacement files for the currently selected Artwork revision targets. */
    public array $overviewTaskRevisionUpload = [];
    /** Completed chunk-staged Artwork files; payload bytes never enter Livewire state. */
    public array $overviewTaskStagedUploads = [];
    /** Completed chunk-staged selective replacements keyed by source document id. */
    public array $overviewTaskStagedRevisionUploads = [];
    /** Artwork files selected for replacement in an active revision upload. */
    public array $overviewTaskRevisionDocumentIds = [];
    public ?int $overviewTaskExistingDocumentId = null;
    public string $overviewTaskDocumentNote = '';
    public ?int $overviewTaskLinkFormTaskId = null;
    public string $overviewTaskLinkUrl = '';
    public ?int $taskExistingDocumentId = null;
    public bool $showTaskDocumentPicker = false;
    public bool $showAddOrderTaskForm = false;
    public string $newOrderTaskName = '';
    public string $newOrderTaskDescription = '';
    public ?int $newOrderTaskPhaseId = null;
    public ?int $newOrderTaskAssigneeId = null;
    public string $newOrderTaskDueDate = '';

    public function mount(): void
    {
        $this->search = (string) request('search', '');
        $this->selectedJobId = request()->integer('open') ?: null;
        $this->selectedTaskId = request()->integer('task') ?: null;
        $requestedComment = trim((string) request('comment', $this->focusComment ?? ''));
        $this->focusComment = $requestedComment !== '' ? $requestedComment : null;
        $this->showCreate = request()->boolean('create');

        if ($this->showCreate) {
            abort_unless(auth()->user()->canModule('jobs', 'create'), 403);
            $this->selectedJobId = null;
            $this->selectedTaskId = null;
            $this->initializeCreateForm(request()->integer('client') ?: null);
            return;
        }

        $requestedClientFilter = request()->integer('client');
        if ($requestedClientFilter) {
            app(ClientService::class)
                ->referenceQuery(auth()->user(), 'jobs')
                ->where('is_active', true)
                ->findOrFail($requestedClientFilter);
            $this->client = (string) $requestedClientFilter;
        }

        if ($this->selectedTaskId) {
            $this->taskEditMode = true;
            $task = app(TaskService::class)->visibleQuery(auth()->user())->findOrFail($this->selectedTaskId);
            $this->selectedJobId = (int) $task->flow_job_id;
            $this->loadTaskForm($this->selectedTaskId);
            $this->applyFocusedComment();
        }

        // A task deep-link is authorized by the task scope above. Do not run
        // the separate Job scope here as well: roles can legitimately have a
        // task in scope while the parent Order is outside their Job list scope.
        // Requiring both scopes caused valid tagged-comment links to 404.
        if ($this->selectedJobId && !$this->selectedTaskId) {
            $requestedDetailTab = strtolower(trim((string) request('tab', '')));
            if ($requestedDetailTab === 'finance') {
                abort_unless(app(AccessControlService::class)->can(auth()->user(), 'finance', 'view'), 403);
                $this->detailTab = 'finance';
            } elseif ($requestedDetailTab === 'redo') {
                $this->detailTab = 'redo';
            }

            $this->prepareSelectedJob($this->selectedJobId);
            $this->applyFocusedComment();
        }
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        // Re-render open Job/Task details when another permitted user updates them.
    }

    #[On('order-runtime-refreshed')]
    public function refreshOrderRuntime(int $orderId): void
    {
        // Workflow maintenance and task actions run inside the isolated
        // Workflow child. Handling this event intentionally performs no extra
        // write/query work here; Livewire rerenders the lightweight parent shell
        // so Overall progress and Next required action reflect the saved state
        // immediately without a browser refresh.
        if ((int) ($this->selectedJobId ?: 0) !== $orderId) {
            return;
        }
    }

    public function render()
    {
        $user = auth()->user();

        if ($this->showCreate) {
            return view('livewire.jobs.index', $this->createPageData($user));
        }

        if ($this->selectedTaskId) {
            return view('livewire.jobs.index', $this->taskPageData($user));
        }

        if ($this->selectedJobId) {
            return view('livewire.jobs.index', $this->jobPageData($user));
        }

        return view('livewire.jobs.index', $this->jobsTableData($user));
    }
}
