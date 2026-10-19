<?php

namespace Tests\Feature;

use Tests\TestCase;

class CreateProductCategoryNavigationPerformanceTest extends TestCase
{
    public function test_direct_create_product_category_navigation_does_not_run_full_taxonomy_sync(): void
    {
        $index = file_get_contents(app_path('Livewire/MasterData/Index.php'));
        $editor = file_get_contents(app_path('Livewire/MasterData/Concerns/ManagesProductCategoryEditor.php'));
        $pageData = file_get_contents(app_path('Livewire/MasterData/Concerns/BuildsMasterDataPageData.php'));

        $mountStart = strpos($index, 'public function mount(): void');
        $mountEnd = strpos($index, '/**', $mountStart);
        $mountMethod = substr($index, $mountStart, $mountEnd - $mountStart);

        $openStart = strpos($editor, 'public function openCategoryEditor');
        $openEnd = strpos($editor, 'public function viewCategory', $openStart);
        $openMethod = substr($editor, $openStart, $openEnd - $openStart);

        $this->assertStringContainsString("request()->boolean('create')", $mountMethod);
        $this->assertStringContainsString('$this->recordsReady = false;', $mountMethod);
        $this->assertStringNotContainsString('synchronizeLegacyTaxonomy()', $mountMethod);
        $this->assertStringNotContainsString('synchronizeLegacyTaxonomy()', $openMethod);

        // The direct editor path must load only the parent options required by
        // the selected level while keeping the category hierarchy/list deferred.
        $this->assertStringContainsString("\$this->group === 'product_category' && \$this->categoryEditorLevel && ! \$this->recordsReady", $pageData);
        $this->assertStringContainsString("->ofType('product_main_category')", $pageData);
    }
}
