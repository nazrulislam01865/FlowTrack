<?php

namespace App\Livewire\Administration;

use App\Services\SetupContext;
use App\Services\SupplierBridge\SupplierProductModuleAccess;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/** One permission matrix, applied uniformly to all suppliers in this workspace. */
final class SupplierProductAccess extends Component
{
    public bool $portalProductModuleEnabled = false;
    public array $portalProductSectionAccess = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $workspaceId = app(SetupContext::class)->workspaceId();
        $row = DB::table('supplier_product_global_policies')
            ->where('workspace_id', $workspaceId)->first();
        $saved = SupplierProductModuleAccess::withRequiredView(json_decode((string) ($row->permissions ?? ''), true) ?: []);
        $this->portalProductModuleEnabled = (bool) ($row->enabled ?? false);
        foreach (SupplierProductModuleAccess::SECTIONS as $section => $actions) {
            foreach ($actions as $action) {
                $this->portalProductSectionAccess[$section][$action] = in_array($action, $saved[$section], true);
            }
        }
    }

    /** Keep the checkbox matrix in sync with the effective section policy. */
    public function updatedPortalProductSectionAccess(mixed $value, ?string $key): void
    {
        $this->authorizeAdmin();
        $parts = explode('.', (string) $key);
        if (count($parts) !== 2) return;
        [$section, $action] = $parts;
        $supported = SupplierProductModuleAccess::SECTIONS[$section] ?? null;
        if (! is_array($supported) || ! in_array($action, $supported, true)) return;
        abort_unless(is_bool($value), 422);

        if ($action === 'manage') {
            foreach ($supported as $supportedAction) {
                $this->portalProductSectionAccess[$section][$supportedAction] = $value;
            }
            return;
        }
        if ($value && $action !== 'view') {
            $this->portalProductSectionAccess[$section]['view'] = true;
        }
        if (! $value) {
            if ($action === 'view') {
                // A write permission without View cannot be used by the API.
                foreach ($supported as $supportedAction) {
                    $this->portalProductSectionAccess[$section][$supportedAction] = false;
                }
            } elseif (in_array('manage', $supported, true)) {
                $this->portalProductSectionAccess[$section]['manage'] = false;
            }
        } elseif (in_array('manage', $supported, true) && $action !== 'manage') {
            $granular = array_diff($supported, ['manage']);
            $allSelected = collect($granular)->every(fn ($item) =>
                $this->portalProductSectionAccess[$section][$item] ?? false);
            $this->portalProductSectionAccess[$section]['manage'] = $allSelected;
        }
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->canModule('suppliers', 'manage'), 403);
    }

    public function save(): void
    {
        $this->authorizeAdmin();
        $permissions = [];
        foreach (SupplierProductModuleAccess::SECTIONS as $section => $actions) {
            $permissions[$section] = [];
            foreach ($actions as $action) {
                $checked = $this->portalProductSectionAccess[$section][$action] ?? false;
                abort_unless(is_bool($checked), 422);
                if ($checked) $permissions[$section][] = $action;
            }
        }
        // Manage expands into every supported action for its own section,
        // and write actions always include View.
        $permissions = SupplierProductModuleAccess::withRequiredView($permissions);
        foreach (SupplierProductModuleAccess::SECTIONS as $section => $actions) {
            foreach ($actions as $action) {
                $this->portalProductSectionAccess[$section][$action] = in_array($action, $permissions[$section], true);
            }
        }
        if ($this->portalProductModuleEnabled) {
            abort_unless(in_array('view', $permissions['products'], true)
                || in_array('view', $permissions['categories'], true), 422,
                'Select a Products or Categories permission before enabling this module.');
        }
        $workspaceId = app(SetupContext::class)->workspaceId();
        DB::table('supplier_product_global_policies')->updateOrInsert(
            ['workspace_id' => $workspaceId],
            [
                'enabled' => $this->portalProductModuleEnabled,
                'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ],
        );
        session()->flash('success', 'Global Supplier Product module permissions saved for all suppliers.');
    }

    public function render()
    {
        $this->authorizeAdmin();
        return view('livewire.administration.supplier-product-access');
    }
}
