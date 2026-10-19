<?php

namespace App\Livewire\MasterData\Concerns;

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

trait ManagesMasterNavigation
{
    public function selectGroup(string $group): void
    {
        abort_unless(array_key_exists($group, MasterDataService::LABELS), 404);
        $this->authorizeGroupAction('view', $group);
        $this->group = $group;
        $this->recordsReady = true;
        $this->search = '';
        $this->supplierStatus = '';
        $this->productMainCategory = '';
        $this->productCategory = '';
        $this->productClientAvailability = '';
        $this->productStatus = '';
        $this->productSupplierFilterId = '';
        $this->productSupplierState = '';
        $this->categoryLevelFilter = '';
        $this->categoryParentFilter = '';
        $this->categoryStatusFilter = '';
        $this->expandedMainCategoryIds = [];
        $this->expandedProductCategoryIds = [];
        if ($group === 'product_category') {
            // Keep the hierarchy collapsed initially. Child rows are loaded only
            // when their parent is expanded, matching the progressive category
            // loading used by the Product Categories page.
            $this->categoryProductLimits = [];
            $this->categorySubcategoryLimits = [];
        }
        $this->parentId = null;
        $this->productCategorySearch = '';
        $this->newProductCategoryName = '';
        $this->clearProductSelection();
        $this->clearCategorySelection();
        $this->closeCategoryDeleteConfirmation();
        $this->resetPage('masterPage');
        $this->resetValidation();
    }
    public function loadMasterRecords(): void
    {
        // Product Categories intentionally do not synchronize or preload their
        // complete hierarchy here. Main categories render first; descendants are
        // requested only after expansion. Taxonomy synchronization still runs on
        // create/edit operations where it is actually required.
        $this->recordsReady = true;
    }
    private function authorizeGroupAction(string $action, ?string $group = null): void
    {
        $group ??= $this->group;
        abort_unless(array_key_exists($group, MasterDataService::LABELS), 404);
        $module = MasterDataService::permissionModuleForType($group);
        abort_unless(auth()->user()?->canModule($module, $action), 403);
    }
    private function currentGroupRecord(int $id): MasterRecord
    {
        return MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType($this->group)
            ->findOrFail($id);
    }
}
