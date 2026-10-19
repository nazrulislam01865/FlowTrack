<?php

namespace App\Livewire\Inquiries;

use App\Livewire\Inquiries\Concerns\ManagesInquiryList;
use App\Livewire\Inquiries\Concerns\ManagesInquiryCreation;
use App\Livewire\Inquiries\Concerns\ManagesInquiryCreateRfq;
use App\Livewire\Inquiries\Concerns\ManagesInquiryCreateProducts;
use App\Livewire\Inquiries\Concerns\ManagesInquiryProducts;
use App\Livewire\Inquiries\Concerns\ManagesInquiryDetail;
use App\Livewire\Inquiries\Concerns\ManagesInquiryTasks;
use App\Livewire\Inquiries\Concerns\ManagesInquiryDocuments;
use App\Livewire\Inquiries\Concerns\ManagesInquiryActivity;
use App\Livewire\Inquiries\Concerns\ManagesInquiryWorkflow;
use App\Livewire\Inquiries\Concerns\ManagesInquiryFinalDecision;
use App\Livewire\Inquiries\Concerns\ManagesInquiryRfq;
use App\Livewire\Inquiries\Concerns\BuildsInquiryPageData;
use App\Livewire\Inquiries\Concerns\HandlesMissingProductSupplierContext;

use App\Livewire\Concerns\HandlesInlineEdits;
use App\Livewire\Concerns\ManagesMissingProductSupplier;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\Inquiry;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use ManagesInquiryList;
    use ManagesInquiryCreation;
    use ManagesInquiryCreateRfq;
    use ManagesInquiryCreateProducts;
    use ManagesInquiryProducts;
    use ManagesInquiryDetail;
    use ManagesInquiryTasks;
    use ManagesInquiryDocuments;
    use ManagesInquiryActivity;
    use ManagesInquiryWorkflow;
    use ManagesInquiryFinalDecision;
    use ManagesInquiryRfq;
    use BuildsInquiryPageData;
    use HandlesMissingProductSupplierContext;

    use ManagesMissingProductSupplier;
    use RefreshesFromWorkspace;
    use HandlesInlineEdits, WithFileUploads, WithPagination;

    private const INQUIRIES_PER_PAGE = 10;

    public string $search = '';
    public string $quick = 'all';
    #[\Livewire\Attributes\Url(as: 'metric', history: true, except: '')]
    public string $metricFilter = '';
    public string $listClient = '';
    public string $listClientLabel = '';
    public string $listStatus = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public bool $hideCompleted = false;

    // Draft values for the Inquiry filter bar. These are intentionally kept
    // separate from the applied query state so changing a filter control does
    // not change the result set until the user clicks Apply filter.
    public string $pendingListClient = '';
    public string $pendingListClientLabel = '';
    public string $pendingListStatus = '';
    public string $pendingDateFrom = '';
    public string $pendingDateTo = '';
    public bool $pendingHideCompleted = false;

    public array $metrics = ['createdToday' => 0, 'notStarted' => 0, 'inProgress' => 0, 'dueThisWeek' => 0, 'completed' => 0, 'completedThisWeek' => 0, 'attention' => 0];

    public bool $showCreate = false;
    public ?int $selectedInquiryId = null;
    public string $detailTab = 'overview';
    public ?int $selectedTaskId = null;
    public bool $showWorkflowManager = false;

    // Create Inquiry fields.
    public ?int $clientId = null;
    public string $clientContact = '';
    public array $clientContactOptions = [];
    public string $referenceNumber = '';
    public string $subject = '';
    public string $requirementNotes = '';
    public string $requestSource = 'Email';
    public string $createPriority = 'Medium';
    public string $createReceivedDate = '';
    public ?int $createOwnerId = null;

    // Quick client creation from Create Inquiry.
    public bool $showCreateClientModal = false;
    public string $newClientName = '';
    public string $newClientContactName = '';
    public string $newClientEmail = '';
    public string $newClientPhone = '';
    public string $newClientCountry = '';
    public bool $useNewClientContactForInquiry = true;
    public bool $showCreateContactModal = false;
    public string $newContactName = '';
    public string $newContactEmail = '';
    public string $newContactPhone = '';
    public int $createWorkflowTaskCount = 0;
    public int $createWorkflowPhaseCount = 0;
    public ?int $createWorkflowId = null;
    public string $selectedWorkflowLabel = '';
    public array $createAttachments = [];

    // Optional RFQ setup on Create Inquiry. Supplier invitations are only sent
    // after a non-draft Inquiry has been created successfully.
    public string $createRfqSupplierSearch = '';
    public array $createRfqSupplierIds = [];
    public string $createRfqDueDate = '';
    public string $createRfqMessage = 'Please quote your best unit price, lead time, shipping and sample options.';

    // Product-scoped RFQ composer state used by Create Inquiry. It intentionally
    // lives beside the product rows so every selected product owns its suppliers,
    // due date, message and send/draft choice without introducing N+1 queries.
    public array $createProductRfqRows = [];
    public array $createProductRows = [];
    public array $createProductCategoryOptions = [];
    public string $createProductSearch = '';
    public string $createProductCategoryFilter = '';
    public bool $createProductShowAllResults = false;
    public bool $showCreateOrderProductModal = false;
    public bool $createCatalogReady = false;
    public bool $createWorkflowReady = false;
    public array $inquiryDetailSectionsReady = [
        'products' => false,
        'taskflow' => false,
        'documents' => false,
        'activity' => false,
    ];
    public string $newProductCode = '';
    public ?int $newProductCategoryId = null;
    public string $newProductCategorySearch = '';
    public string $newProductCategoryName = '';
    public string $newProductName = '';
    public $newProductImage = null;

    // Inquiry product editor (Inquiry details).
    public bool $editingInquiryProducts = false;
    public array $inquiryProductRows = [];
    public array $inquiryCategoryFilterOptions = [];

    // Inquiry Details > Add another product. This mirrors the Order Details
    // product picker but keeps independent state for the Inquiry screen.
    public bool $showAddInquiryProductForm = false;
    public string $inquiryProductSearch = '';
    public bool $inquiryProductShowAllResults = false;
    public ?int $inquiryProductSelectedId = null;
    public string $inquiryProductCategory = '';
    public string $inquiryProductQuantity = '1';
    public string $inquiryProductUnitPrice = '0.00';

    // Compact inline editor used from the Inquiry Details product table.
    public ?int $editInquiryProductItemId = null;
    public ?int $editInquiryProductSelectedId = null;
    public string $editInquiryProductSearch = '';
    public bool $editInquiryProductShowAllResults = false;
    public string $editInquiryProductCategory = '';
    public string $editInquiryProductName = '';
    public string $editInquiryProductQuantity = '1';
    public string $editInquiryProductUnitPrice = '';
    public string $editInquiryProductNotes = '';

    // Options are loaded only when create/workflow management is opened.
    public array $userOptions = [];
    public array $clientFilterOptions = [];
    public string $selectedClientLabel = '';
    public array $ownerFilterOptions = [];
    public string $selectedOwnerLabel = '';
    public array $taskPackOptions = [];
    public array $workflowFilterOptions = [];

    // Detail actions.
    public array $inquiryUploads = [];
    public array $taskQuickUploads = [];
    public $taskUpload = null;
    public bool $showTaskDocumentModal = false;
    public ?int $taskDocumentModalTaskId = null;
    public ?int $pendingCompletionTaskId = null;
    public string $taskDocumentSource = 'upload';
    public array $taskDocumentUploads = [];
    public ?int $taskExistingDocumentId = null;
    public string $taskDocumentNote = '';
    public ?int $taskLinkFormTaskId = null;
    public string $taskLinkUrl = '';
    public string $taskComment = '';
    public string $inquiryComment = '';
    public string $inquiryActivityTab = 'all';
    public bool $showInquiryDocumentPicker = false;
    public ?int $inquiryExistingDocumentId = null;
    public ?int $taskAssigneeId = null;
    public string $taskDueDate = '';
    public string $taskStatus = '';
    public bool $showTaskAttentionModal = false;
    public ?int $taskAttentionTaskId = null;
    public string $taskAttentionReason = '';
    public bool $showInquiryAttentionModal = false;
    public string $inquiryAttentionReason = '';

    // Admin-only task append form on Inquiry details.
    public bool $showAddTaskForm = false;
    public string $newTaskName = '';
    public string $newTaskDescription = '';
    public ?int $newTaskAssigneeId = null;
    public string $newTaskAssigneeLabel = 'Unassigned';
    public string $newTaskDueDate = '';
    public bool $newTaskRequiresSubmission = false;
    public string $newTaskSubmissionLabel = '';

    // Workflow manager.
    public array $managerRows = [];
    public ?int $managerTemplateId = null;

    // Final decision.
    public string $deadReason = 'Price too high';
    public string $deadNote = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->canModule('inquiries', 'view'), 403);
        $this->metricFilter = trim((string) request('metric', $this->metricFilter));
        if (! in_array($this->metricFilter, ['', 'createdToday', 'notStarted', 'inProgress', 'dueThisWeek', 'completed', 'completedThisWeek', 'attention', 'dashboardOpen'], true)) {
            $this->metricFilter = '';
        }
        $this->resetCreateCollections();

        if (request()->boolean('create')) {
            $this->openCreate();
            return;
        }

        if ($open = request()->integer('open')) {
            app(\App\Queries\Inquiries\InquiryDetailQuery::class)->find(auth()->user(), $open);
            $this->selectedInquiryId = $open;
            // The separate Taskflow tab was removed; old workflow URLs now land on Overview.
            $this->detailTab = 'overview';
            if ($taskId = request()->integer('task')) {
                $this->detailTab = 'overview';
                $task = app(\App\Queries\Inquiries\InquiryDetailQuery::class)->task(auth()->user(), $taskId);
                if ((int) $task->inquiry_id === $open) {
                    // Task deep-links now highlight the row in the inline workflow.
                    // No task-detail modal or heavy task relationship hydration is needed.
                    $this->selectedTaskId = $taskId;
                    $this->inquiryDetailSectionsReady['taskflow'] = true;
                }
            }

            return;
        }

        // List metrics are not needed while creating or viewing one Inquiry.
        // Avoid running the aggregate query on those routes.
        $this->refreshInquiryListMetrics();
    }

    #[On('flowtrack-notification')]
    public function refreshRealtime(): void
    {
        if (!$this->showCreate && !$this->selectedInquiryId) $this->refreshInquiryListMetrics();
    }

    protected function prepareForWorkspaceRefresh(): void
    {
        if (! $this->showCreate && ! $this->selectedInquiryId) {
            $this->refreshInquiryListMetrics();
        }
    }

    public function render()
    {
        $user = auth()->user();
        if ($this->showCreate) return view('livewire.inquiries.index', $this->createPageData());
        if ($this->selectedInquiryId) return view('livewire.inquiries.index', $this->detailPageData($user));
        return view('livewire.inquiries.index', $this->listPageData($user));
    }
}
