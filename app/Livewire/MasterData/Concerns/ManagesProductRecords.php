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

trait ManagesProductRecords
{
    public function loadProductDetailSection(string $section): void
    {
        abort_unless($this->showProductView && $this->viewProductId, 422);
        abort_unless(in_array($section, ['pricing', 'options', 'documents'], true), 422);
        $this->productDetailSectionsReady[$section] = true;
    }

    private function resetProductDetailProgressiveSections(): void
    {
        $this->productDetailSectionsReady = [
            'pricing' => false,
            'options' => false,
            'documents' => false,
        ];
    }

    public function saveProductDraft(): void
    {
        $this->status = 'inactive';
        $this->save();
    }

    public function viewProduct(int $id): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'view'), 403);

        MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')
            ->findOrFail($id);

        $this->viewProductId = $id;
        $this->showProductView = true;
        $this->resetProductDetailProgressiveSections();
    }

    public function closeProductView(): void
    {
        $this->showProductView = false;
        $this->viewProductId = null;
        $this->resetProductDetailProgressiveSections();
    }

    public function toggleProductStatus(int $id): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);

        $service = app(MasterDataService::class);
        $product = MasterRecord::query()
            ->forWorkspace($service->workspaceId())
            ->ofType('product')
            ->findOrFail($id);

        $nextStatus = $product->status === 'active' ? 'inactive' : 'active';
        $product = app(\App\Actions\MasterData\SaveMasterRecordAction::class)->execute('product', [
            'code' => $product->code,
            'name' => $product->name,
            'description' => $product->description,
            'parent_id' => $product->parent_id,
            'status' => $nextStatus,
            'sort_order' => $product->sort_order,
            'metadata' => $product->metadata,
        ], $product->id);
        $this->recordsReady = true;
        session()->flash('success', $product->status === 'active' ? 'Product activated.' : 'Product deactivated.');
    }

    public function editProduct(int $id): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'edit'), 403);

        $this->showProductView = false;
        $this->viewProductId = null;
        $this->resetProductDetailProgressiveSections();

        // Resolve the row inside the active workspace/type before opening the
        // editor. This prevents a stale/tampered action id from ever opening a
        // different Master Data record.
        MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->ofType('product')
            ->findOrFail($id);

        $this->open($id);
    }

    public function deleteProduct(int $id): void
    {
        abort_unless($this->group === 'product', 404);
        abort_unless(auth()->user()?->canModule('catalog_products', 'delete'), 403);

        $service = app(MasterDataService::class);
        $product = MasterRecord::query()
            ->forWorkspace($service->workspaceId())
            ->ofType('product')
            ->findOrFail($id);

        $this->recordsReady = true;
        $this->resetValidation('record');

        try {
            $productName = $product->name;
            app(\App\Actions\MasterData\DeleteMasterRecordAction::class)->execute($product->id);
            $this->resetPage('masterPage');
            session()->flash('success', 'Product deleted.');
            app(\App\Services\NotificationService::class)->notifyUser(
                auth()->user(),
                'Product deleted',
                $productName.' was removed from the product catalogue.',
                'update',
                null,
                null,
                auth()->user(),
            );
        } catch (ValidationException $e) {
            $this->addError('record', collect($e->errors())->flatten()->first());
        }
    }
}
