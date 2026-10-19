<?php

namespace Tests\Feature;

use Tests\Support\OrderPhase5Source;
use Tests\TestCase;

class CreateOrderProductSupplierUxTest extends TestCase
{
    public function test_create_order_uses_the_shared_missing_supplier_resolution_flow(): void
    {
        $jobs = OrderPhase5Source::livewire();
        $sharedConcern = file_get_contents(app_path('Livewire/Concerns/ManagesMissingProductSupplier.php'));
        $jobContext = file_get_contents(app_path('Livewire/Jobs/Concerns/HandlesMissingProductSupplierContext.php'));
        $resolver = file_get_contents(app_path('Services/Catalog/ProductSupplierResolutionService.php'));
        $modal = file_get_contents(resource_path('views/components/catalog/missing-product-supplier-modal.blade.php'));
        $wrapper = file_get_contents(resource_path('views/components/jobs/create/missing-product-supplier-modal.blade.php'));
        $createProducts = OrderPhase5Source::createProductsView();
        $resolutionCss = file_get_contents(resource_path('css/modules/orders/create-order-supplier-resolution.css'));
        $afterDashboard = file_get_contents(resource_path('css/application/after-dashboard.css'));

        $this->assertStringContainsString('supplierForProduct($product)', $jobs);
        $this->assertStringContainsString('openMissingProductSupplierModalFor($product)', $jobs);
        $this->assertStringContainsString("'create_order'", $jobContext);
        $this->assertStringContainsString('completeCreateOrderMissingProductSupplier', $jobContext);
        $this->assertStringContainsString('appendCreateOrderProduct($product, $supplierId)', $jobContext);

        $this->assertStringContainsString('resolveMissingProductSupplier', $sharedConcern);
        $this->assertStringContainsString('assertMissingProductSupplierTargetCurrent', $sharedConcern);
        $this->assertStringContainsString('selectMissingProductSupplier', $sharedConcern);
        $this->assertStringContainsString('skipMissingCreateOrderProductSupplier', $sharedConcern);
        $this->assertStringContainsString("['existing', 'create', 'skip']", $sharedConcern);
        $this->assertStringContainsString("->canModule('suppliers', 'create')", $sharedConcern);

        $this->assertStringContainsString('lockForUpdate()', $resolver);
        $this->assertStringContainsString('assignSupplierToProduct', $resolver);
        $this->assertStringContainsString('SaveMasterRecordAction', $resolver);

        $this->assertStringContainsString('<x-catalog.missing-product-supplier-modal', $wrapper);
        $this->assertStringContainsString('Supplier not linked', $modal);
        $this->assertStringContainsString('Link existing supplier', $modal);
        $this->assertStringContainsString('Create new supplier', $modal);
        $this->assertStringContainsString('Continue without supplier', $modal);
        $this->assertStringContainsString('action="selectMissingProductSupplier"', $modal);
        $this->assertStringContainsString('wire:click="resolveMissingProductSupplier"', $modal);
        $this->assertStringContainsString('wire:model.blur="missingProductNewSupplierName"', $modal);
        $this->assertStringContainsString('wire:model.blur="missingProductNewSupplierEmail"', $modal);
        $this->assertStringNotContainsString("@include('components.jobs.create.missing-product-supplier-modal')", $createProducts);

        $this->assertStringContainsString("@import '../modules/orders/create-order-supplier-resolution.css';", $afterDashboard);
        $this->assertStringContainsString('.ft-order-supplier-resolution__notice', $resolutionCss);
        $this->assertStringContainsString('.ft-order-supplier-resolution__option.is-selected', $resolutionCss);
    }
}
