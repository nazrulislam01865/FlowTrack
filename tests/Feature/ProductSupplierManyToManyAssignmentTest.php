<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductSupplierManyToManyAssignmentTest extends TestCase
{
    public function test_product_editor_supports_multiple_linked_suppliers_and_separate_default(): void
    {
        $form = file_get_contents(resource_path('views/components/catalog/product-form.blade.php'));
        $logic = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesMasterEditor.php'));

        $this->assertStringContainsString('property="productSupplierIds"', $form);
        $this->assertStringContainsString('property="productSupplierId"', $form);
        $this->assertStringContainsString("'productSupplierIds.*'", $logic);
        $this->assertStringContainsString("$".'linkedSupplierIds->push($defaultSupplierId)', $logic);
        $this->assertStringContainsString("DB::table('product_supplier_links')", $logic);
        $this->assertStringContainsString("->where('workspace_id', \$workspaceId)", $logic);
        $this->assertStringContainsString("->where('product_id', \$record->id)", $logic);
        $this->assertStringContainsString("->whereNotIn('supplier_id', \$supplierIds)->delete()", $logic);
        $this->assertStringContainsString('->insertOrIgnore(', $logic);
    }

    public function test_bulk_assign_supports_many_suppliers_without_replacing_existing_links(): void
    {
        $logic = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesProductSupplierAssignments.php'));
        $picker = file_get_contents(resource_path('views/components/suppliers/assign-products-modal.blade.php'));

        $this->assertStringContainsString("'bulkProductSupplierIds' => ['required', 'array', 'min:1', 'max:100']", $logic);
        $this->assertStringContainsString("'bulkProductSupplierIds.*'", $logic);
        $this->assertStringContainsString("$".'linkedIds->merge($supplierIds)->unique()->values()->all()', $logic);
        $this->assertStringContainsString("if (! $".'product->productSupplierId())', $logic);
        $this->assertStringContainsString("DB::table('product_supplier_links')->insertOrIgnore($".'pivotRows)', $logic);
        $this->assertStringContainsString('selectedSupplierIds', $picker);
    }

    public function test_supplier_edit_can_append_product_codes_without_stealing_existing_product_defaults(): void
    {
        $edit = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesSupplierDetails.php'));
        $create = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesSupplierCreation.php'));
        $form = file_get_contents(resource_path('views/livewire/master-data/sections/supplier-edit.blade.php'));

        $this->assertStringContainsString('linkProductsToSupplier(', $edit);
        $this->assertStringContainsString("canModule('catalog_products', 'edit')", $edit);
        $this->assertStringContainsString("if (! $".'product->productSupplierId())', $create);
        $this->assertStringContainsString('insertOrIgnore($pivotRows)', $create);
        $this->assertStringContainsString('Link more products by code', $form);
    }

    public function test_selected_product_suppliers_are_resolved_in_one_workspace_scoped_query(): void
    {
        $filter = file_get_contents(app_path('Services/FilterOptionService.php'));
        $this->assertStringContainsString("$"."context === 'master-product' && $".'selectedIds->isNotEmpty()', $filter);
        $this->assertStringContainsString("->whereIn('id', \$selectedIds->all())", $filter);
        $this->assertStringContainsString('->forWorkspace(app(SetupContext::class)->workspaceId())', $filter);
    }
}
