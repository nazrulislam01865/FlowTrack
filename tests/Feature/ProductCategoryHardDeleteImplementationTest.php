<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductCategoryHardDeleteImplementationTest extends TestCase
{
    public function test_category_deletion_is_previewed_and_executes_as_hard_delete(): void
    {
        $component = \Tests\Support\AdministrationPhase7Source::masterData();
        $service = file_get_contents(app_path('Services/ProductCategoryDeletionService.php'));
        $bulk = file_get_contents(resource_path('views/components/catalog/category-bulk-actions.blade.php'));
        $menu = file_get_contents(resource_path('views/components/catalog/category-action-menu.blade.php'));
        $modal = file_get_contents(resource_path('views/components/catalog/category-delete-modal.blade.php'));

        $this->assertStringContainsString('openCategoryDeleteConfirmation', $component);
        $this->assertStringContainsString('confirmCategoryHardDelete', $component);
        $this->assertStringContainsString('ProductCategoryDeletionService::class', $component);
        $this->assertStringContainsString('->forceDelete()', $service);
        $this->assertStringContainsString('$product->parent_id = null', $service);
        $this->assertStringContainsString("'main_category', 'excel_main_category'", $service);
        $this->assertStringContainsString("'sub_category', 'excel_sub_category'", $service);
        $this->assertStringNotContainsString('wire:confirm=', $bulk);
        $this->assertStringNotContainsString("confirm('Delete this category?')", $menu);
        $this->assertStringContainsString('Affected rows', $modal);
        $this->assertStringContainsString('Products to unassign', $modal);
        $this->assertStringContainsString('Delete permanently', $modal);
    }
}
