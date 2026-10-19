<div class="ft-master-page" @if(!$showModal && !$showProductView && !$categoryEditorLevel && !$supplierCreateMode && !$supplierViewId && !$supplierEditId) wire:init="loadMasterRecords" @endif>
    {{-- Master category navigation now lives in the application sidebar; counts remain available as $groupCounts[$key] ?? 0 for navigation extensions. --}}
    @php
        $hasParent = in_array($group, ['product', 'state'], true);
        $hasColor = in_array($group, \App\Services\MasterDataService::COLOR_TYPES, true);
        $columnCount = $group === 'remote_area'
            ? 10
            : 6 + ($hasParent ? 1 : 0) + ($hasColor ? 1 : 0) + ($group === 'inquiry_task_status' ? 2 : 0) + (in_array($group, ['order_task_status', 'order_task_flag'], true) ? 1 : 0) + ($group === 'task_pack_work_calendar' ? 2 : 0);
        $colorUsageLabel = match ($group) {
            'department' => 'department and team performance',
            'task_status' => 'legacy task status',
            'task_flag' => 'legacy task flag',
            'order_task_status' => 'order task status',
            'order_task_flag' => 'order task flag',
            'order_flag' => 'order flag',
            'priority' => 'priority',
            'inquiry_task_status' => 'inquiry task status',
            default => 'master data',
        };
        $permissionModule = \App\Services\MasterDataService::permissionModuleForType($group);
        $canCreateMaster = auth()->user()->canModule($permissionModule, 'create');
        $canEditMaster = auth()->user()->canModule($permissionModule, 'edit');
        $canDeleteMaster = auth()->user()->canModule($permissionModule, 'delete');
        $canCreateProductCategory = auth()->user()->canModule('product_categories', 'create');
        $catalogueGroup = in_array($group, ['product', 'product_category', 'supplier'], true);
        $financialGroup = in_array($group, \App\Services\MasterDataService::FINANCIAL_TYPES, true);
        $taskPackMasterGroup = in_array($group, \App\Services\MasterDataService::TASK_PACK_MASTER_TYPES, true);
        $masterSectionLabel = $catalogueGroup
            ? 'Catalogue'
            : ($financialGroup ? 'Financial Master Data' : ($taskPackMasterGroup ? 'Task Pack Master Data' : 'Master Data'));
        $pageTitle = $labels[$group] ?? 'Master Data';
        $singularLabel = match ($group) {
            'product' => 'product',
            'product_category' => 'category',
            'document_category' => 'document category',
            'production_unit' => 'production unit',
            'shipment_method' => 'shipment method',
            'courier' => 'courier',
            'remote_area' => 'remote area surcharge',
            'task_status' => 'legacy task status',
            'inquiry_task_status' => 'inquiry task status',
            'task_flag' => 'legacy task flag',
            'order_task_status' => 'order task status',
            'order_task_flag' => 'order task flag',
            'order_flag' => 'order flag',
            'task_pack_duration_unit' => 'duration unit',
            'task_pack_timer_start' => 'timer start rule',
            'task_pack_timer_stop' => 'timer stop rule',
            'task_pack_work_calendar' => 'work calendar',
            default => strtolower(\Illuminate\Support\Str::singular($pageTitle)),
        };
        $displayTimezone = app(\App\Services\WorkspaceSettingsService::class)->displayTimezone();
        $pageSubtitle = match ($group) {
            'product' => 'Manage the product catalogue used in Inquiries and Orders.',
            'product_category' => 'Manage the categories used to organise products across FlowTrack.',
            'department' => 'Maintain departments used for assignment, routing and task ownership.',
            'supplier' => 'Maintain supplier values available throughout Order processing.',
            'production_unit' => 'Maintain the production units used by workflows and operations.',
            'shipment_method' => 'Maintain shipment methods available for orders and deliveries.',
            'courier' => 'Maintain couriers available when recording shipment tracking details.',
            'remote_area' => 'Maintain carrier-specific remote area surcharge rules by country, postal range or city, with UPS origin/destination classifications and an optional FlowTrack charge.',
            'currency' => 'Maintain currencies available for clients, orders, invoices and payments.',
            'received_account' => 'Maintain the receiving accounts available when recording customer payments.',
            'payment_method' => 'Maintain payment methods available when recording payments.',
            'payment_term' => 'Maintain payment terms available for invoices and client finance settings.',
            'invoice_type' => 'Maintain invoice types available when creating invoices.',
            'country' => 'Maintain countries used by client and address records.',
            'state' => 'Maintain states and their parent countries.',
            'phone_country_code' => 'Maintain searchable international phone codes used on shipping and contact forms.',
            'document_category' => 'Maintain document categories used across uploads and workflows.',
            'priority' => 'Maintain priority levels and the colours used throughout FlowTrack.',
            'task_status' => 'Legacy task statuses retained for compatibility. New Order tasks use Order Task Statuses.',
            'inquiry_task_status' => 'Maintain Inquiry task statuses, their automatic Inquiry status mapping, attention flag rule and display colours.',
            'task_flag' => 'Legacy task flags retained for compatibility. New Order task flags are separate.',
            'order_task_status' => 'Maintain Order task statuses and choose which Order Task Flag each status applies automatically.',
            'order_task_flag' => 'Maintain Order task flags and map each one to the Order Flag that should appear on the parent Order.',
            'order_flag' => 'Maintain the separate Order-level flags shown on Order lists and details.',
            'task_pack_duration_unit' => 'Maintain duration units available in the Task Pack time and efficiency prototype.',
            'task_pack_timer_start' => 'Maintain timer-start choices available in the Task Pack time and efficiency prototype.',
            'task_pack_timer_stop' => 'Maintain timer-stop choices available in the Task Pack time and efficiency prototype.',
            'task_pack_work_calendar' => 'Maintain work-calendar choices available in the Task Pack time and efficiency prototype.',
            default => 'Maintain values used throughout FlowTrack.',
        };
        $productImagePreview = null;
        if ($group === 'product' && $productImage) {
            try { $productImagePreview = $productImage->temporaryUrl(); } catch (\Throwable $exception) { $productImagePreview = null; }
        }
        if (!$productImagePreview && !$removeProductImage && $existingProductImageUrl) {
            $productImagePreview = $existingProductImageUrl;
        }
    @endphp

    @if($group === 'product')
        @include('livewire.master-data.sections.product')
    @elseif($group === 'product_category')
        @include('livewire.master-data.sections.product-category')
    @elseif($group === 'supplier' && $supplierCreateMode)
        @include('livewire.master-data.sections.supplier-create')
    @elseif($group === 'supplier' && $supplierEditId)
        @include('livewire.master-data.sections.supplier-edit')
    @elseif($group === 'supplier' && $supplierViewId)
        @include('livewire.master-data.sections.supplier-detail')
    @elseif($group === 'supplier')
        @include('livewire.master-data.sections.supplier-list')
    @else
        @include('livewire.master-data.sections.generic-list')
    @endif

        @include('livewire.master-data.sections.generic-editor-modal')

        @include('livewire.master-data.sections.category-editor')
</div>
