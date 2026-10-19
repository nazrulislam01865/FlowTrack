<?php

namespace App\Services\Catalog;

use App\Actions\MasterData\SaveMasterRecordAction;
use App\Models\MasterRecord;
use App\Services\MasterDataService;
use App\Services\ProductCatalogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a Product that has no default Supplier configured.
 *
 * The service owns the concurrency-sensitive Product/Supplier persistence so
 * Create Order, Order Details, Create Inquiry and Inquiry Details all use one
 * canonical implementation. Screen-level authorization stays with the caller.
 */
final class ProductSupplierResolutionService
{
    public function __construct(
        private readonly SaveMasterRecordAction $saveMasterRecord,
        private readonly MasterDataService $masterData,
        private readonly ProductCatalogService $catalog,
    ) {
    }

    public function linkExisting(int $productId, int $supplierId): MasterRecord
    {
        $workspaceId = $this->masterData->workspaceId();

        return DB::transaction(function () use ($productId, $supplierId, $workspaceId): MasterRecord {
            $product = $this->lockActiveProduct($productId, $workspaceId);

            // Product Master is authoritative. A concurrent resolution should
            // never be overwritten by the stale modal that happened to finish later.
            if ($current = $this->catalog->supplierForProduct($product)) {
                return $current;
            }

            $supplier = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('supplier')
                ->active()
                ->findOrFail($supplierId);

            $this->catalog->assignSupplierToProduct($product, (int) $supplier->id);

            return $this->catalog->supplierForProduct($product->fresh())
                ?? abort(422, 'The supplier could not be linked to this product.');
        });
    }

    public function createAndLink(int $productId, string $name, ?string $email = null): MasterRecord
    {
        $name = trim($name);
        $email = trim((string) $email);
        $workspaceId = $this->masterData->workspaceId();

        return DB::transaction(function () use ($productId, $name, $email, $workspaceId): MasterRecord {
            $product = $this->lockActiveProduct($productId, $workspaceId);

            if ($current = $this->catalog->supplierForProduct($product)) {
                return $current;
            }

            $duplicate = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('supplier')
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'missingProductNewSupplierName' => 'A supplier with this name already exists. Link the existing supplier instead.',
                ]);
            }

            $sortOrder = ((int) MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('supplier')
                ->max('sort_order')) + 1;

            $supplier = $this->saveMasterRecord->execute('supplier', [
                'code' => $this->masterData->nextCode('supplier'),
                'name' => $name,
                'description' => null,
                'color' => null,
                'parent_id' => null,
                'status' => 'active',
                'sort_order' => $sortOrder,
                'metadata' => $email !== '' ? ['email' => $email] : [],
            ]);

            $this->catalog->assignSupplierToProduct($product, (int) $supplier->id);

            return $this->catalog->supplierForProduct($product->fresh()) ?: $supplier;
        });
    }

    private function lockActiveProduct(int $productId, int $workspaceId): MasterRecord
    {
        return MasterRecord::query()
            ->forWorkspace($workspaceId)
            ->ofType('product')
            ->active()
            ->lockForUpdate()
            ->findOrFail($productId);
    }
}
