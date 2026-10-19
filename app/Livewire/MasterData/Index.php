<?php

namespace App\Livewire\MasterData;

use App\Livewire\MasterData\Concerns\ManagesMasterNavigation;
use App\Livewire\MasterData\Concerns\ManagesMasterEditor;
use App\Livewire\MasterData\Concerns\ManagesProductTaxonomy;
use App\Livewire\MasterData\Concerns\ManagesSupplierCreation;
use App\Livewire\MasterData\Concerns\ManagesSupplierDetails;
use App\Livewire\MasterData\Concerns\ManagesSupplierList;
use App\Livewire\MasterData\Concerns\ManagesProductSupplierAssignments;
use App\Livewire\MasterData\Concerns\ManagesProductCatalog;
use App\Livewire\MasterData\Concerns\BuildsMasterDataPageData;
use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\Client;
use App\Models\MasterRecord;
use App\Support\Filters\ProductClientOptions;
use App\Services\MasterDataService;
use App\Services\ProductImageService;
use App\Services\ProductOptionImageService;
use App\Services\ProductPriceTableParser;
use App\Services\ProductCategoryDeletionService;
use App\Support\MasterColor;
use App\Support\AttachmentUpload;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use ManagesMasterNavigation;
    use ManagesMasterEditor;
    use ManagesProductTaxonomy;
    use ManagesProductCatalog;
    use ManagesSupplierCreation;
    use ManagesSupplierDetails;
    use ManagesSupplierList;
    use ManagesProductSupplierAssignments;
    use BuildsMasterDataPageData;

    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use WithFileUploads;
    use WithPagination;

    #[Url(as: 'group', history: true, except: 'product')]
    public string $group = 'product';

    public string $search = '';
    public string $productMainCategory = '';
    public string $productCategory = '';
    public string $productClientAvailability = '';
    public string $productStatus = '';
    public string $productReferenceCode = '';
    public ?int $productSupplierId = null;
    /** @var array<int,int|string> */
    public array $productSupplierIds = [];
    public string $productSupplierFilterId = '';
    public string $productSupplierState = '';
    public string $productFormMainCategory = '';
    public string $productSize = '';
    public string $productPriceTable = '';
    public string $productRemoteSurchargeTable = '';
    public string $productCustomerPriceTable = '';
    public array $productCustomerPricePreview = [];
    /** @var array<int,array{client_id:string,table:string,preview:array}> */
    public array $productClientPrices = [];
    /** Edited supplier costs, keyed by the supplier selected above (no shared editable cost table). */
    public array $productSupplierPrices = [];
    public array $productPricePreview = [];
    public array $productRemoteSurchargePreview = [];
    public array $productOptions = [];
    public array $productOptionUploads = [];
    public array $productShipmentUrgencies = [];
    public bool $productShipmentUrgencyPickerOpen = false;
    public array $productShipmentUrgencyPickerSelection = [];
    public string $productSubcategory = '';
    public string $productClientAvailabilityMode = 'all';
    public array $productClientIds = [];
    public string $productTestCertificateNumber = '';
    public $productCertificateUpload = null;
    public $productTemplateUpload = null;
    public bool $removeProductCertificate = false;
    public bool $removeProductTemplate = false;
    public int $productPerPage = 10;
    public bool $recordsReady = false;
    public bool $showModal = false;
    public bool $productTaxonomyReady = false;
    public bool $productShipmentOptionsReady = false;
    public bool $showProductView = false;
    public array $productDetailSectionsReady = [
        'pricing' => false,
        'options' => false,
        'documents' => false,
    ];

    #[Url(as: 'open', history: true)]
    public ?int $viewProductId = null;
    public ?int $editId = null;
    public string $code = '';
    public string $name = '';
    public string $description = '';
    public string $color = '#2563EB';
    public ?int $parentId = null;
    public string $productCategorySearch = '';
    public string $newProductCategoryName = '';
    public string $newProductCategoryDescription = '';
    public string $newProductCategoryMain = '';
    public string $newMainCategoryName = '';
    public string $newMainCategoryDescription = '';
    public string $newSubcategoryName = '';
    public string $newSubcategoryDescription = '';
    public ?int $newSubcategoryProductCategoryId = null;
    public ?string $categoryCreator = null;
    public string $status = 'active';
    public int $sortOrder = 0;
    public string $metadataJson = '';
    // Remote Area Surcharge editor state. Keep the legacy postal-code
    // property for backwards compatibility with existing exact-match records.
    public string $remoteAreaPostalCode = '';
    public string $remoteAreaCarrier = 'UPS';
    public string $remoteAreaCountry = '';
    public string $remoteAreaIataCode = '';
    public string $remoteAreaPostalCodeFrom = '';
    public string $remoteAreaPostalCodeTo = '';
    public string $remoteAreaCity = '';
    public string $remoteAreaOriginSurcharge = 'No';
    public string $remoteAreaDestinationSurcharge = 'No';
    public string $remoteAreaExtraCharge = '';
    public string $workCalendarDayFrom = 'monday';
    public string $workCalendarDayTo = 'friday';
    public string $workCalendarTimeFrom = '09:00';
    public string $workCalendarTimeTo = '18:00';
    public string $autoInquiryStatus = 'To do';
    public bool $requiresAttention = false;
    public ?int $orderTaskFlagId = null;
    public ?int $orderFlagId = null;
    public $productImage = null;
    public ?string $existingProductImageUrl = null;
    public bool $removeProductImage = false;

    // Product-list bulk selection / actions. Selection is kept by id across pages
    // and can also represent every product matching the current filters.
    public array $selectedProductIds = [];
    public array $excludedProductIds = [];
    public bool $selectAllMatchingProducts = false;
    public ?string $bulkProductPanel = null;
    public string $bulkProductClientMode = 'all';
    public array $bulkProductClientIds = [];
    public string $bulkProductMainCategory = '';
    public ?int $bulkProductCategoryId = null;
    public string $bulkProductSubcategory = '';
    /** @var array<int,int> */
    public array $bulkProductSupplierIds = [];

    // Product Category hierarchy page state.
    public string $categoryLevelFilter = '';
    public string $categoryParentFilter = '';
    public string $categoryStatusFilter = '';
    public int $categoryPerPage = 6;
    public array $expandedMainCategoryIds = [];
    public array $expandedProductCategoryIds = [];
    public array $categoryProductLimits = [];
    public array $categorySubcategoryLimits = [];

    // Product-category bulk selection. Keys use level:id so hierarchy levels can be mixed safely.
    public array $selectedCategoryKeys = [];

    // Destructive category deletion is always previewed before execution.
    public bool $showCategoryDeleteConfirm = false;
    public array $categoryDeletePreview = [];
    public array $categoryDeleteTargetKeys = [];

    private const CATEGORY_PRODUCT_BATCH = 4;
    private const CATEGORY_SUBCATEGORY_BATCH = 3;
    public ?string $categoryEditorLevel = null;
    public ?int $categoryEditorId = null;
    public ?int $categoryEditorParentId = null;
    public string $categoryEditorName = '';
    public string $categoryEditorDescription = '';
    public string $categoryEditorStatus = 'active';
    public bool $categoryEditorReadOnly = false;

    public function mount(): void
    {
        if (!array_key_exists($this->group, MasterDataService::LABELS)) {
            $this->group = 'product';
        }

        $this->authorizeGroupAction('view');
        $this->mountSupplierCreation();
        $this->mountSupplierDetails();

        // Dashboard Active products KPI opens the catalogue already filtered
        // to the same active records counted by the card.
        if ($this->group === 'product') {
            $requestedProductStatus = strtolower(trim((string) request('product_status', '')));
            if (in_array($requestedProductStatus, ['active', 'inactive'], true)) {
                $this->productStatus = $requestedProductStatus;
            }

            $requestedSupplierId = request()->integer('supplier_id');
            $this->productSupplierFilterId = $requestedSupplierId > 0 ? (string) $requestedSupplierId : '';
        }

        // Allow other workflows (for example Create Inquiry) to send the user
        // directly to the standalone Add Product form instead of opening a
        // second inline product-creation modal.
        if ($this->group === 'product' && request()->boolean('create')) {
            $this->viewProductId = null;
            $this->showProductView = false;
            $this->open();
        } elseif ($this->group === 'product' && ($openProductId = request()->integer('open'))) {
            // Keep Product details addressable from the catalogue list so product-name
            // links behave like the Order/Inquiry detail links and survive refresh/navigation.
            $this->viewProductId = $openProductId;
            $this->showProductView = true;
        }

        // Sidebar shortcut: open the standalone Product Category creator directly.
        // Do not run the legacy taxonomy synchronizer in the navigation request:
        // it scans the full product catalogue and can block wire:navigate for seconds.
        // The editor render path already loads only the active Main Category options
        // it needs, while the hierarchy list remains deferred until requested.
        if ($this->group === 'product_category' && request()->boolean('create')) {
            $this->recordsReady = false;
            $this->openCategoryEditor('product');
        }
    }







    /**
     * Server-authoritative handler for the three dependent Product taxonomy
     * selectors. The shared searchable dropdown can optimistically update its
     * label in Alpine, but the dependency chain must always be resolved from
     * canonical taxonomy rows so Product Categories created on the dedicated
     * page appear immediately in Create/Edit Product.
     */











































    /**
     * Backward-compatible bulk entry point. Deletion never happens without the impact modal.
     */


























































}
