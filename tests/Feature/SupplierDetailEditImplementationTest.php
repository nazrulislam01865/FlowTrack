<?php

namespace Tests\Feature;

use Tests\TestCase;

class SupplierDetailEditImplementationTest extends TestCase
{
    public function test_supplier_list_exposes_view_and_edit_pages(): void
    {
        $row = file_get_contents(resource_path('views/components/suppliers/list-row.blade.php'));
        $index = file_get_contents(resource_path('views/livewire/master-data/index.blade.php'));

        $this->assertStringContainsString("'supplier' => \$supplier->id", $row);
        $this->assertStringContainsString("'edit_supplier' => \$supplier->id", $row);
        $this->assertStringContainsString('>View</a>', $row);
        $this->assertStringContainsString('>Edit</a>', $row);
        $this->assertStringContainsString("sections.supplier-detail", $index);
        $this->assertStringContainsString("sections.supplier-edit", $index);
    }

    public function test_supplier_edit_preserves_product_metadata_and_permissions(): void
    {
        $logic = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesSupplierDetails.php'));

        $this->assertStringContainsString("authorizeGroupAction('edit', 'supplier')", $logic);
        $this->assertStringContainsString('$metadata = (array) ($supplier->metadata ?? []);', $logic);
        $this->assertStringContainsString("app(SaveMasterRecordAction::class)->execute('supplier'", $logic);
        $this->assertStringContainsString("Schema::hasTable('product_supplier_links')", $logic);
        $this->assertStringContainsString("orWhereJsonContains('metadata->supplier_ids'", $logic);
    }

    public function test_supplier_detail_and_edit_views_keep_product_management_available(): void
    {
        $detail = file_get_contents(resource_path('views/livewire/master-data/sections/supplier-detail.blade.php'));
        $edit = file_get_contents(resource_path('views/livewire/master-data/sections/supplier-edit.blade.php'));

        $this->assertStringContainsString('Supplier information', $detail);
        $this->assertStringContainsString('Assigned products', $detail);
        $this->assertStringContainsString('Edit supplier', $detail);
        $this->assertStringContainsString('Save changes', $edit);
        $this->assertStringContainsString('Manage assigned products', $edit);
        $this->assertStringContainsString("'supplier_id' => \$supplier->id", $detail);
    }
}
