<?php

namespace Tests\Feature;

use Tests\TestCase;

class BulkOrderImportImplementationTest extends TestCase
{
    public function test_bulk_order_import_matches_the_refined_template_and_backend_contract(): void
    {
        $view = file_get_contents(resource_path('views/pages/bulk-order-import.blade.php'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $service = file_get_contents(app_path('Services/BulkOrderImportService.php'));
        $reader = file_get_contents(app_path('Services/SpreadsheetRowReader.php'));
        $jobService = $this->jobServiceSource();
        $baseMigration = file_get_contents(database_path('migrations/2026_08_10_200000_add_bulk_order_import_support.php'));
        $refinedMigration = file_get_contents(database_path('migrations/2026_08_15_051500_refine_bulk_order_import_fields.php'));
        $controller = file_get_contents(app_path('Http/Controllers/BulkOrderImportController.php'));
        $bulkCss = $this->compatibilityCss('flowtrack-bulk-order-import.css');
        $ordersTable = file_get_contents(resource_path('views/components/jobs/table.blade.php'));
        $ordersIndex = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $bulkJs = file_get_contents(resource_path('js/features/bulk-order-import.js'));
        $routeLoader = file_get_contents(resource_path('js/features/route-loader.js'));

        $this->assertStringContainsString('<h1>Import orders</h1>', $view);
        $this->assertStringContainsString('Client ID, Client Reference Number, Shipping Address, Postal Code and Product ID are mandatory.', $view);
        $this->assertStringContainsString('Phone Number is optional', $view);
        $this->assertStringContainsString('Previous Reference Number becomes required only when Repeat Order? is Yes.', $view);
        $this->assertStringContainsString('Product ID is required so FlowTrack can generate the Order Title automatically', $view);
        $this->assertStringContainsString('Normal, Urgent or Super Urgent', $view);
        $this->assertStringContainsString('Reference already exists; this row will be skipped', $service);
        $this->assertStringContainsString('Reference already exists; the matching order will be updated', $service);
        $this->assertStringContainsString('Client &amp; Product IDs validated', $view);
        $this->assertStringNotContainsString('Fallback Client ID', $view);
        $this->assertStringNotContainsString('Fallback Supplier ID', $view);
        $this->assertStringNotContainsString('What is Source Row ID?', $view);

        $this->assertStringContainsString("Route::get('/orders/bulk-import'", $routes);
        $this->assertStringContainsString("Route::post('/orders/bulk-import/validate'", $routes);
        $this->assertStringContainsString("Route::post('/orders/bulk-import/import'", $routes);
        $this->assertStringContainsString("name('orders.bulk-import.template')", $routes);

        $this->assertStringContainsString("'client_id' => ['clientid', 'clientcode']", $service);
        $this->assertStringContainsString("'ref' => ['clientreferencenumber', 'clientreference', 'referenceorderno'", $service);
        $this->assertStringContainsString("'is_repeat' => ['repeatorderyesno'", $service);
        $this->assertStringContainsString("'product_id' => ['productid'", $service);
        $this->assertStringContainsString("'shipping_address' => ['shippingaddress'", $service);
        $this->assertStringContainsString("'shipping_phone' => ['phonenumberwithcountrycode'", $service);
        $this->assertStringContainsString("'shipping_postal_code' => ['postalcode'", $service);
        // Legacy aliases stay readable for old uploads, but the v4 template no longer exposes these Create Order fields.
        $this->assertStringContainsString("'estimated_delivery' => ['estimateddeliverydate']", $service);
        $this->assertStringContainsString("'production_urgency' => ['productionurgency'", $service);
        $this->assertStringContainsString("'shipment_urgency' => ['shipmenturgency'", $service);
        $this->assertStringContainsString("'notes' => ['notes', 'ordernotes']", $service);
        $this->assertStringContainsString('Client ID is required', $service);
        $this->assertStringContainsString('Client Reference Number is required', $service);
        $this->assertStringContainsString('Product ID is required', $service);
        $this->assertStringContainsString('OrderCreateData::generateTitle', $service);
        $this->assertStringNotContainsString('Order Title is required', $service);
        $this->assertStringContainsString('Shipping Address is required', $service);
        $this->assertStringContainsString('Postal Code is required', $service);
        $this->assertStringContainsString('activePhoneCountryCodes', $service);
        $this->assertStringContainsString('resolveShippingPhone', $service);
        $this->assertStringContainsString("'shipping_address' => \$row['shipping_address']", $service);
        $this->assertStringContainsString("'shipping_postal_code' => \$row['shipping_postal_code']", $service);
        $this->assertStringContainsString('Repeat Order? must be Yes or No', $service);
        $this->assertStringContainsString('Previous Reference Number is required when Repeat Order? is Yes', $service);
        $this->assertStringContainsString('Product ID does not match an active Product', $service);
        $this->assertStringContainsString('Product Quantity must be a whole number', $service);
        $this->assertStringContainsString("'product_supplier_resolved_id'", $service);
        $this->assertStringContainsString("'product_unit_price_resolved'", $service);
        $this->assertStringContainsString('productSupplierId()', $service);
        $this->assertStringContainsString('productPriceForQuantity', $service);
        $this->assertStringContainsString("'supplier_id' => filled(\$row['product_supplier_resolved_id']", $service);
        $this->assertStringContainsString("'unit_price' => \$row['product_unit_price_resolved'] ?? 0", $service);
        $this->assertStringContainsString("'catalog_product_id' => (int) \$row['product_resolved_id']", $service);
        $this->assertStringContainsString('Invalid Order Hand Date', $service);
        $this->assertStringContainsString('Invalid Estimated Delivery Date', $service);
        $this->assertStringContainsString('resolveUrgency', $service);
        $this->assertStringContainsString("'notes' => blank(\$row['notes']) ? null : \$row['notes']", $service);
        $this->assertStringContainsString('resolveClientOrderWorkflow', $service);
        $this->assertStringContainsString('Select an Order workflow for this client', $service);
        $this->assertStringContainsString('workflowAvailableForClient', $service);
        $this->assertStringContainsString('bulk_order_import_rows', $service);
        $this->assertStringContainsString("'profile' => 'CLIENT_AUTO'", $service);
        $this->assertStringNotContainsString('Urgent? is required', $service);
        $this->assertStringNotContainsString('Order description is required', $service);
        $this->assertStringNotContainsString('Invalid received date', $service);

        $this->assertStringNotContainsString("'default_client_id' => ['nullable', 'integer']", $controller);
        $this->assertStringNotContainsString("'default_supplier_id' => ['nullable', 'integer']", $controller);
        $this->assertStringContainsString('.steps .step::before', $bulkCss);
        $this->assertStringContainsString('content:none!important', $bulkCss);
        $this->assertStringContainsString('ft-bulk-import-button', $ordersTable);
        $this->assertLayoutLoadsViteCss('resources/css/application/after-dashboard.css', $layout);
        $this->assertStringContainsString("@import '../modules/orders/bulk-import.css';", file_get_contents(resource_path('css/application/after-dashboard.css')));
        $this->assertStringContainsString("import * as XLSX from 'xlsx';", $bulkJs);
        $this->assertStringContainsString("import('./bulk-order-import.js')", $routeLoader);
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/xlsx', $view);
        $this->assertStringContainsString('manual_workflows', $bulkJs);
        $this->assertStringContainsString('Client ID *', $bulkJs);
        $this->assertStringContainsString('Shipping Address *', $bulkJs);
        $this->assertStringContainsString('Phone Number (with country code)', $bulkJs);
        $this->assertStringContainsString('Postal Code *', $bulkJs);
        $this->assertStringContainsString('Client Reference Number *', $bulkJs);
        $this->assertStringContainsString('Product ID *', $bulkJs);
        $this->assertStringContainsString('Order Hand Date', $bulkJs);
        $this->assertStringContainsString('Shipment Urgency', $bulkJs);
        $this->assertStringNotContainsString('Order Title *', $bulkJs);
        $this->assertStringNotContainsString('Estimated Delivery Date', $bulkJs);
        $this->assertStringNotContainsString('Production Urgency', $bulkJs);
        $this->assertStringContainsString("'view_orders_url'", $controller);
        $this->assertStringContainsString("'import' => \$result['import_id']", $controller);
        $this->assertStringContainsString('result.view_orders_url', $bulkJs);
        $this->assertStringContainsString('importedOrdersLink.href = result.view_orders_url', $bulkJs);

        $this->assertStringContainsString("#[Url(as: 'import'", $ordersIndex);
        $this->assertStringContainsString('public int $importBatchId = 0;', $ordersIndex);
        $this->assertStringContainsString("clearListFiltersExcept('importBatch')", $ordersIndex);
        $this->assertStringContainsString("from('bulk_order_import_rows as imported_order_rows')", $jobService);
        $this->assertStringContainsString("whereIn('imported_order_rows.status', ['created', 'updated'])", $jobService);
        $this->assertStringContainsString("plural('order', \$jobs->total())", $ordersTable);
        $this->assertStringContainsString('Show all orders', $ordersTable);

        $this->assertStringContainsString("in_array('clientid', \$keys, true)", $reader);
        $this->assertStringContainsString("in_array('clientreferencenumber', \$keys, true)", $reader);
        $this->assertStringContainsString("in_array('referenceorderno', \$keys, true)", $reader);
        $this->assertStringContainsString("in_array('productid', \$keys, true)", $reader);
        $this->assertStringNotContainsString("in_array('ordertitle', \$keys, true)", $reader);
        $this->assertStringContainsString("in_array('shippingaddress', \$keys, true)", $reader);
        $this->assertStringContainsString("in_array('postalcode', \$keys, true)", $reader);
        $this->assertStringContainsString('legacy binary .xls', $reader);

        $this->assertStringContainsString("'is_repeat_order' => (bool) (\$data['is_repeat_order'] ?? false)", $jobService);
        $this->assertStringContainsString("'estimated_delivery_date' => \$data['estimated_delivery_date'] ?? null", $jobService);
        $this->assertStringContainsString("'production_urgency_ids'", $jobService);
        $this->assertStringContainsString("'shipment_urgency_ids'", $jobService);
        $this->assertStringContainsString("'notes' => blank(\$data['notes'] ?? null)", $jobService);

        $this->assertStringContainsString("Schema::create('bulk_order_imports'", $baseMigration);
        $this->assertStringContainsString("Schema::create('bulk_order_import_rows'", $baseMigration);
        $this->assertStringContainsString("Schema::hasColumn('flow_jobs', 'notes')", $refinedMigration);
        $this->assertStringContainsString("'name' => 'Super Urgent'", $refinedMigration);
        $this->assertStringContainsString('FlowTrack_Bulk_Order_Import_Template_v4.xlsx', $controller);
        $this->assertFileExists(resource_path('templates/FlowTrack_Bulk_Order_Import_Template_v4.xlsx'));
        $this->assertFileExists(storage_path('app/templates/FlowTrack_Bulk_Order_Import_Template_v4.xlsx'));
    }
}
