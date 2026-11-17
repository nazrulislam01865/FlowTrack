<?php

namespace Tests\Feature;

use App\Services\SupplierBridge\SupplierProductModuleAccess;
use Tests\TestCase;

final class SupplierProductGlobalAccessContractTest extends TestCase
{
    public function test_global_permission_matrix_has_no_implicit_grants(): void
    {
        $saved = SupplierProductModuleAccess::sanitized([
            'products' => ['view', 'delete', 'unlisted_action'],
            'client_pricing' => ['view', 'manage'],
        ]);
        $this->assertSame(['view', 'delete'], $saved['products']);
        $this->assertSame(['view'], $saved['client_pricing']);
        $this->assertSame([], $saved['supplier_pricing']);
    }

    public function test_write_permissions_automatically_include_view_without_other_grants(): void
    {
        $saved = SupplierProductModuleAccess::withRequiredView([
            'products' => ['manage'],
            'categories' => ['create'],
            'supplier_pricing' => ['edit'],
            'client_pricing' => ['unsupported'],
        ]);

        $this->assertSame(['view', 'create', 'edit', 'delete', 'manage'], $saved['products']);
        $this->assertSame(['view', 'create'], $saved['categories']);
        $this->assertSame(['view', 'edit'], $saved['supplier_pricing']);
        $this->assertSame([], $saved['client_pricing']);
        $this->assertSame([], $saved['documents']);
    }

    public function test_manage_expands_only_its_section_and_does_not_grant_unrelated_sections(): void
    {
        $saved = SupplierProductModuleAccess::withRequiredView([
            'products' => ['manage'], 'categories' => ['view'], 'documents' => [],
        ]);
        $this->assertSame(SupplierProductModuleAccess::SECTIONS['products'], $saved['products']);
        $this->assertSame(['view'], $saved['categories']);
        $this->assertSame([], $saved['supplier_pricing']);
        $this->assertSame([], $saved['documents']);
    }

    public function test_view_only_permissions_remain_view_only(): void
    {
        $saved = SupplierProductModuleAccess::withRequiredView([
            'products' => ['view'],
            'categories' => [],
        ]);
        $this->assertSame(['view'], $saved['products']);
        $this->assertSame([], $saved['categories']);
    }

    public function test_supplier_settings_use_one_workspace_policy_not_individual_supplier_rules(): void
    {
        $settings = file_get_contents(app_path('Livewire/Administration/SupplierProductAccess.php'));
        $view = file_get_contents(resource_path('views/livewire/administration/supplier-product-access.blade.php'));
        $session = file_get_contents(app_path('Http/Controllers/SupplierBridge/SupplierProductModuleSession.php'));
        $access = file_get_contents(app_path('Services/SupplierBridge/SupplierProductModuleAccess.php'));

        $this->assertStringContainsString("DB::table('supplier_product_global_policies')", $settings);
        $this->assertStringNotContainsString('selectedSupplierId', $settings);
        $this->assertStringNotContainsString('selectedSupplierId', $view);
        $this->assertStringContainsString('All suppliers', $view);
        $this->assertStringContainsString('->policy((int) $supplier->workspace_id)', $session);
        $this->assertStringContainsString('supplier_product_global_policies', $access);
        $this->assertStringNotContainsString("DB::table('supplier_product_portal_access')", $session);
    }

    public function test_product_module_uses_separate_key_without_changing_other_bridge_apis(): void
    {
        $product = file_get_contents(app_path('Http/Controllers/SupplierBridge/VerifySupplierProductApi.php'));
        $session = file_get_contents(app_path('Http/Controllers/SupplierBridge/SupplierProductModuleSession.php'));
        $original = file_get_contents(app_path('Http/Controllers/SupplierBridge/VerifySupplierQuotation.php'));

        $this->assertStringContainsString('supplier_bridge.product_api_key', $product);
        $this->assertStringContainsString('hash_hmac', $product);
        $this->assertStringContainsString('hash_equals', $product);
        $this->assertStringContainsString('signedProductApi($request)', $session);
        $this->assertStringContainsString('supplier_bridge.secret', $original);
    }
}
