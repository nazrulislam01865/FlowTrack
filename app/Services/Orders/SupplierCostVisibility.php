<?php

namespace App\Services\Orders;

use App\Models\User;
use App\Services\AccessControlService;

/** A dedicated role-matrix permission, never inferred from Order/Finance access. */
final class SupplierCostVisibility
{
    public function allowed(?User $user = null): bool
    {
        $user ??= auth()->user();
        return $user !== null && app(AccessControlService::class)->can($user, 'supplier_pricing', 'view');
    }
}
