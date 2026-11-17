<?php

namespace Tests\Feature;

use App\Services\SupplierBridge\SupplierProductModuleAccess;
use Tests\TestCase;

final class SupplierProductManageMatrixContractTest extends TestCase
{
    public function test_manage_expands_all_actions_in_its_row_only(): void
    {
        $permissions = SupplierProductModuleAccess::withRequiredView(['products' => ['manage']]);
        $this->assertSame(SupplierProductModuleAccess::SECTIONS['products'], $permissions['products']);
        foreach (SupplierProductModuleAccess::SECTIONS as $section => $actions) {
            if ($section !== 'products') {
                $this->assertSame([], $permissions[$section]);
            }
        }
    }

    public function test_granular_section_edit_does_not_grant_manage_or_other_sections(): void
    {
        $permissions = SupplierProductModuleAccess::withRequiredView([
            'supplier_pricing' => ['edit'], 'categories' => ['view'],
        ]);
        $this->assertSame(['view', 'edit'], $permissions['supplier_pricing']);
        $this->assertSame(['view'], $permissions['categories']);
        $this->assertSame([], $permissions['products']);
    }

    public function test_live_permission_matrix_updates_granular_checkboxes(): void
    {
        $livewire = file_get_contents(app_path('Livewire/Administration/SupplierProductAccess.php'));
        $view = file_get_contents(resource_path('views/livewire/administration/supplier-product-access.blade.php'));
        $this->assertStringContainsString('updatedPortalProductSectionAccess', $livewire);
        $this->assertStringContainsString("if (\$action === 'manage')", $livewire);
        $this->assertStringContainsString('wire:model.live="portalProductSectionAccess.', $view);
    }
}
