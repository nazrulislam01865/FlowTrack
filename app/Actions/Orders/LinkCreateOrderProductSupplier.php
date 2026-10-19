<?php

namespace App\Actions\Orders;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\Catalog\ProductSupplierResolutionService;

/** Backward-compatible Create Order wrapper around the shared resolver. */
final class LinkCreateOrderProductSupplier
{
    public function __construct(private readonly ProductSupplierResolutionService $resolver)
    {
    }

    public function handle(int $productId, int $supplierId, User $actor): MasterRecord
    {
        $this->authorize($actor);

        return $this->resolver->linkExisting($productId, $supplierId);
    }

    private function authorize(User $actor): void
    {
        abort_unless(
            $actor->canModule('jobs', 'create')
                && $actor->canModule('catalog_products', 'view')
                && $actor->canModule('catalog_products', 'edit'),
            403
        );
    }
}
