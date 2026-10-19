<?php

namespace App\Actions\Orders;

use App\Models\MasterRecord;
use App\Models\User;
use App\Services\Catalog\ProductSupplierResolutionService;

/**
 * Backward-compatible Create Order action. Core persistence is shared with
 * Inquiry and detail-page product flows through ProductSupplierResolutionService.
 */
final class CreateAndLinkCreateOrderProductSupplier
{
    public function __construct(private readonly ProductSupplierResolutionService $resolver)
    {
    }

    public function handle(int $productId, string $name, ?string $email, User $actor): MasterRecord
    {
        $this->authorize($actor);

        return $this->resolver->createAndLink($productId, $name, $email);
    }

    private function authorize(User $actor): void
    {
        abort_unless(
            $actor->canModule('jobs', 'create')
                && $actor->canModule('catalog_products', 'view')
                && $actor->canModule('catalog_products', 'edit')
                && $actor->canModule('suppliers', 'create'),
            403
        );
    }
}
