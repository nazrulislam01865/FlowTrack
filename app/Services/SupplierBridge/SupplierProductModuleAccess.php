<?php

namespace App\Services\SupplierBridge;

use App\Models\MasterRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Explicit deny-by-default supplier access, evaluated against FlowTracker's database. */
final class SupplierProductModuleAccess
{
    public const SECTIONS = [
        'products' => ['view', 'create', 'edit', 'delete', 'manage'],
        'categories' => ['view', 'create', 'edit', 'delete', 'manage'],
        'supplier_pricing' => ['view', 'edit'],
        'client_pricing' => ['view', 'edit'],
        'options' => ['view', 'edit'],
        'documents' => ['view', 'edit'],
        'shipment' => ['view', 'edit'],
        'availability' => ['view', 'edit'],
    ];

    private array $actors = [];
    private array $permissions = [];
    private array $supplierWorkspaces = [];

    /** One section-wise policy per workspace, shared by every active supplier. */
    public function policy(int $workspaceId): array
    {
        if (! array_key_exists($workspaceId, $this->permissions)) {
            $row = DB::table('supplier_product_global_policies')
                ->where('workspace_id', $workspaceId)->first(['enabled', 'permissions']);
            $this->permissions[$workspaceId] = [
                'enabled' => $row !== null && (bool) $row->enabled,
                'permissions' => self::withRequiredView(json_decode((string) ($row->permissions ?? ''), true) ?: []),
            ];
        }
        return $this->permissions[$workspaceId];
    }


    public static function emptyPermissions(): array
    {
        return array_fill_keys(array_map(static fn (string $section): string => $section,
            array_keys(self::SECTIONS)), []);
    }

    public static function sanitized(mixed $raw): array
    {
        $result = self::emptyPermissions();
        if (! is_array($raw)) return $result;
        foreach (self::SECTIONS as $section => $actions) {
            $input = $raw[$section] ?? [];
            $result[$section] = array_values(array_intersect($actions, is_array($input) ? $input : []));
        }
        return $result;
    }

    /**
     * Permission dependencies are enforced when saving administrator settings.
     * A non-view action is unusable without View; automatically include only
     * its required View permission, never unrelated sections or actions.
     */
    public static function withRequiredView(mixed $requested): array
    {
        $permissions = self::sanitized($requested);
        foreach (self::SECTIONS as $section => $actions) {
            // Manage is the complete action set for its section. Expand it to
            // match both the visible matrix and the effective API permissions.
            if (in_array('manage', $permissions[$section], true)) {
                $permissions[$section] = $actions;
            } elseif (array_diff($permissions[$section], ['view']) !== []
                && in_array('view', $actions, true)
                && ! in_array('view', $permissions[$section], true)) {
                array_unshift($permissions[$section], 'view');
            }
        }
        return $permissions;
    }

    /** Null means this is an ordinary FlowTracker user, not a portal actor. */
    public function actor(User $user): ?object
    {
        $id = (int) $user->id;
        if (array_key_exists($id, $this->actors)) return $this->actors[$id];
        if (! str_starts_with((string) $user->email, 'supplier-product-actor-')) {
            return $this->actors[$id] = null;
        }
        return $this->actors[$id] = DB::table('supplier_product_portal_actors')
            ->where('flowtrack_user_id', $id)->first();
    }

    public function isPortalActor(User $user): bool
    {
        return $this->actor($user) !== null;
    }

    public function supplierId(User $user): ?int
    {
        $actor = $this->actor($user);
        return $actor ? (int) $actor->supplier_id : null;
    }

    public function can(User $user, string $section, string $action = 'view'): bool
    {
        $actor = $this->actor($user);
        if (! $actor) return false;
        if (! $user->is_active) return false;
        if (! session()->isStarted() || (int) session('supplier_product_actor_id') !== (int) $actor->id) return false;
        if ((int) session('supplier_product_expires', 0) < time()) return false;

        // Even with a global permission matrix, suppliers must be active and
        // retain their original product assignment isolation.
        $supplierId = (int) $actor->supplier_id;
        if (! array_key_exists($supplierId, $this->supplierWorkspaces)) {
            $this->supplierWorkspaces[$supplierId] = DB::table('master_records')
                ->where('id', $supplierId)->where('type', 'supplier')
                ->where('status', 'active')->whereNull('deleted_at')->value('workspace_id');
        }
        $workspaceId = (int) $this->supplierWorkspaces[$supplierId];
        if ($workspaceId <= 0) return false;
        $policy = $this->policy($workspaceId);
        if (! $policy['enabled']) return false;
        $allowed = $policy['permissions'][$section] ?? [];
        // Editing any section also requires the ability to view that section.
        if ($action !== 'view' && in_array($section, array_keys(self::SECTIONS), true)
            && ! in_array('view', $allowed, true)) return false;
        if ($action === 'edit') return in_array('edit', $allowed, true) || in_array('manage', $allowed, true);
        return in_array($action, $allowed, true) || in_array('manage', $allowed, true);
    }

    /** FlowTracker's ordinary staff retain their existing permissions and UI. */
    public function permitsSection(?User $user, string $section, string $action = 'view'): bool
    {
        if (! $user || ! $this->isPortalActor($user)) return true;
        return $this->can($user, $section, $action);
    }

    public function canModule(User $user, string $module, string $action): bool
    {
        return match ($module) {
            'catalog_products' => $this->can($user, 'products', $action),
            'product_categories' => $this->can($user, 'categories', $action),
            'supplier_pricing' => $this->can($user, 'supplier_pricing', $action),
            default => false,
        };
    }

    /** Assert a product is assigned to this supplier, including legacy metadata links. */
    public function assertProduct(User $user, MasterRecord $product): void
    {
        $actor = $this->actor($user);
        if (! $actor) return;
        $supplierId = (int) $actor->supplier_id;
        abort_unless($product->type === 'product'
            && $product->workspace_id === app(\App\Services\MasterDataService::class)->workspaceId()
            && ($product->hasProductSupplier($supplierId)
                || DB::table('product_supplier_links')->where('workspace_id', $product->workspace_id)
                    ->where('supplier_id', $supplierId)->where('product_id', $product->id)->exists()), 404);
    }
}
