#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root.'/'.$relative;
    if (! is_file($path)) {
        $failures[] = $relative.' is missing.';
        return '';
    }

    return (string) file_get_contents($path);
};

$assertContains = static function (string $source, string $needle, string $message) use (&$failures): void {
    if (! str_contains($source, $needle)) {
        $failures[] = $message;
    }
};

$assertNotMatches = static function (string $source, string $pattern, string $message) use (&$failures): void {
    if (preg_match($pattern, $source)) {
        $failures[] = $message;
    }
};

/*
 * Route-sensitive / editor pages must NOT defer the whole Livewire component.
 * Their mount() methods depend on the original GET request or need interactive
 * state immediately. Lazy loading belongs inside heavy sections on those pages.
 */
$immediatePages = [
    'resources/views/pages/workflow-form.blade.php' => 'workflow-setup.form',
    'resources/views/pages/task-pack-form.blade.php' => 'task-pack-setup.form',
    'resources/views/pages/user-edit.blade.php' => 'user-editor.index',
    'resources/views/pages/profile.blade.php' => 'profile.index',
    'resources/views/pages/company-setup.blade.php' => 'company-setup.index',
    'resources/views/pages/order-workflow-setup.blade.php' => 'order-workflow-setup.index',
    'resources/views/pages/administration.blade.php' => 'administration.index',
    'resources/views/pages/master-data.blade.php' => 'master-data.index',
    'resources/views/pages/task-pack-setup.blade.php' => 'task-pack-setup.index',
];

foreach ($immediatePages as $relative => $component) {
    $source = $read($relative);
    $assertContains($source, '<livewire:'.$component, $relative.' must render '.$component.'.');
    $assertNotMatches(
        $source,
        '/<livewire:'.preg_quote($component, '/').'[^>]*\sdefer(?:\.bundle)?(?:\s|\/|>)/s',
        $relative.' must not defer the whole interactive/route-sensitive component.'
    );
}

/*
 * Mixed-mode workspaces may defer ONLY their plain list mode. Create/detail or
 * deep-linked filter state must mount on the original request.
 */
$jobsPage = $read('resources/views/pages/jobs.blade.php');
foreach (['open', 'task', 'create'] as $needle) {
    $assertContains($jobsPage, "'{$needle}'", 'Orders page route guard is missing '.$needle.'.');
}
$assertContains($jobsPage, '<livewire:jobs.index />', 'Order Create/Detail mode must mount jobs.index immediately.');
$assertContains($jobsPage, '<livewire:orders.index defer />', 'Plain Order list mode should remain deferred.');
$assertContains($jobsPage, '<livewire:orders.index />', 'Deep-linked Order list filters must mount immediately.');

$inquiriesPage = $read('resources/views/pages/inquiries.blade.php');
foreach (['create', 'open', 'task', 'metric'] as $needle) {
    $assertContains($inquiriesPage, "'{$needle}'", 'Inquiry page route guard is missing '.$needle.'.');
}
$assertContains($inquiriesPage, '<livewire:inquiries.index />', 'Inquiry Create/Detail/deep-link mode must mount immediately.');
$assertContains($inquiriesPage, '<livewire:inquiries.index defer />', 'Plain Inquiry list mode should remain deferred.');

$clientsPage = $read('resources/views/pages/clients.blade.php');
$assertContains($clientsPage, "request()->boolean('create')", 'Client page must guard ?create=1 before deferring.');
$assertContains($clientsPage, '<livewire:clients.index />', 'Create Client mode must mount immediately.');
$assertContains($clientsPage, '<livewire:clients.index defer />', 'Plain Client list mode should remain deferred.');

$documentsPage = $read('resources/views/pages/documents.blade.php');
foreach (['client', 'job'] as $needle) {
    $assertContains($documentsPage, "'{$needle}'", 'Documents route guard is missing '.$needle.'.');
}
$assertContains($documentsPage, '<livewire:documents.index />', 'Contextual Documents mode must mount immediately.');
$assertContains($documentsPage, '<livewire:documents.index defer />', 'Plain Documents list mode should remain deferred.');

$workflowSetupPage = $read('resources/views/pages/workflow-setup.blade.php');
$assertContains($workflowSetupPage, "request()->has('workflow')", 'Workflow Setup must preserve ?workflow= route context.');
$assertContains($workflowSetupPage, '<livewire:workflow-setup.index />', 'Deep-linked Workflow Setup must mount immediately.');
$assertContains($workflowSetupPage, '<livewire:workflow-setup.index defer />', 'Plain Workflow Setup list mode should remain deferred.');

/* Pure read/list pages can still defer after the application shell. */
$deferredPages = [
    'resources/views/pages/board.blade.php' => 'board.index',
    'resources/views/pages/cancelled-orders.blade.php' => 'orders.cancelled-orders',
    'resources/views/pages/dashboard.blade.php' => 'dashboard.index',
    'resources/views/pages/my-work.blade.php' => 'my-work.index',
    'resources/views/pages/notifications.blade.php' => 'notifications.index',
    'resources/views/pages/reports.blade.php' => 'reports.index',
    'resources/views/pages/order-summary-report.blade.php' => 'reports.order-summary',
    'resources/views/pages/team-performance-report.blade.php' => 'team-performance.report',
];

foreach ($deferredPages as $relative => $component) {
    $source = $read($relative);
    if (! preg_match('/<livewire:'.preg_quote($component, '/').'[^>]*\sdefer(?:\.bundle)?(?:\s|\/|>)/s', $source)) {
        $failures[] = $relative.' should defer the safe read/list component '.$component.'.';
    }
}

/* Existing internal progressive boundaries must stay in place. */
$masterView = $read('resources/views/livewire/master-data/index.blade.php');
$assertContains($masterView, 'wire:init="loadMasterRecords"', 'Master Data must keep internal wire:init loading.');

$taskPackView = $read('resources/views/livewire/task-pack-setup/index.blade.php');
$assertContains($taskPackView, 'wire:init="loadTaskPacks"', 'Task Pack Setup must keep internal wire:init loading.');

$inquiryIndex = $read('app/Livewire/Inquiries/Index.php');
$inquiryCreation = $read('app/Livewire/Inquiries/Concerns/ManagesInquiryCreation.php');
$inquiryData = $read('app/Livewire/Inquiries/Concerns/BuildsInquiryPageData.php');
$dashboardView = $read('resources/views/livewire/dashboard/index.blade.php');

$assertContains($inquiryIndex, 'public bool $createCatalogReady = false;', 'Create Inquiry is missing the progressive catalog readiness flag.');
$assertContains($inquiryCreation, 'loadCreateSection(string $section)', 'Create Inquiry is missing the viewport section loader.');
$assertContains($inquiryData, 'if ($catalogReady)', 'Create Inquiry catalog queries are not guarded by catalog readiness.');


/* Create forms must mount immediately, then hydrate only expensive lower sections. */
$progressiveLoader = $read('resources/views/components/ui/progressive-section-loader.blade.php');
foreach (['IntersectionObserver', 'loadCreateSection', 'ft-progressive-skeleton'] as $needle) {
    $assertContains($progressiveLoader, $needle, 'Reusable progressive form-section loader is missing '.$needle.'.');
}

$assertContains($inquiryIndex, 'public bool $createWorkflowReady = false;', 'Create Inquiry is missing the progressive workflow readiness flag.');
$assertContains($inquiryCreation, 'if ($section === \'workflow\')', 'Create Inquiry workflow options are not viewport-gated.');

$clientIndex = $read('app/Livewire/Clients/Index.php');
$clientCreation = $read('app/Livewire/Clients/Concerns/ManagesClientCreation.php');
$clientData = $read('app/Livewire/Clients/Concerns/BuildsClientPageData.php');
$clientCreateView = $read('resources/views/components/clients/create.blade.php');
$assertContains($clientIndex, 'public bool $createAddressOptionsReady = false;', 'Create Client is missing the progressive address readiness flag.');
$assertContains($clientCreation, 'if ($section === \'addresses\')', 'Create Client address options are not viewport-gated.');
$assertContains($clientData, '$addressOptionsReady', 'Create Client country/state queries are not guarded by address readiness.');
$assertContains($clientCreateView, 'section="addresses"', 'Create Client is missing the progressive address placeholder.');

$masterIndex = $read('app/Livewire/MasterData/Index.php');
$masterEditor = $read('app/Livewire/MasterData/Concerns/ManagesMasterEditor.php');
$masterData = $read('app/Livewire/MasterData/Concerns/BuildsMasterDataPageData.php');
$productForm = $read('resources/views/components/catalog/product-form.blade.php');
$assertContains($masterIndex, 'public bool $productTaxonomyReady = false;', 'Create Product is missing the taxonomy readiness flag.');
$assertContains($masterIndex, 'public bool $productShipmentOptionsReady = false;', 'Create Product is missing the shipment urgency readiness flag.');
$assertContains($masterEditor, 'if ($section === \'product-taxonomy\')', 'Create Product taxonomy is not viewport-gated.');
$assertContains($masterEditor, 'if ($section === \'product-shipping-urgencies\')', 'Create Product shipment urgencies are not viewport-gated.');
$assertContains($masterData, '$this->productTaxonomyReady', 'Create Product taxonomy queries are not guarded by readiness.');
$assertContains($productForm, 'section="product-taxonomy"', 'Create Product is missing the taxonomy placeholder.');
$assertContains($productForm, 'section="product-shipping-urgencies"', 'Create Product is missing the shipment urgency placeholder.');

$workflowFormClass = $read('app/Livewire/WorkflowSetup/Form.php');
$workflowFormView = $read('resources/views/livewire/workflow-setup/form.blade.php');
$assertContains($workflowFormClass, 'public bool $sourceOptionsReady = false;', 'Create Workflow is missing the source-options readiness flag.');
$assertContains($workflowFormClass, 'if ($section === \'source-workflows\')', 'Create Workflow source templates are not viewport-gated.');
$assertContains($workflowFormView, 'section="source-workflows"', 'Create Workflow is missing the source-template placeholder.');

$taskPackFormClass = $read('app/Livewire/TaskPackSetup/Form.php');
$taskPackFormView = $read('resources/views/livewire/task-pack-setup/form.blade.php');
$assertContains($taskPackFormClass, 'if ($section === \'task-options\')', 'Create Task Pack option references are not viewport-gated.');
$assertNotMatches($taskPackFormView, '/wire:init=["\']loadTaskPackOptions["\']/', 'Create Task Pack must not eagerly hydrate task options on mount.');
$assertContains($taskPackFormView, 'section="task-options"', 'Create Task Pack is missing the progressive option placeholder.');

$administration = $read('app/Livewire/Administration/Index.php');
$assertContains($administration, '\'roles\' => $this->showUserModal', 'Add User role options should load only when the modal is opened.');
$assertContains($administration, '\'departments\' => $this->showUserModal', 'Add User department options should load only when the modal is opened.');
if (! preg_match('/<livewire:dashboard\.tagged-comments[\s\S]*?\slazy(?:\s|\/\>)/', $dashboardView)) {
    $failures[] = 'Dashboard tagged comments must remain viewport-lazy.';
}


/* Detail pages mount immediately; expensive lower relations hydrate by viewport. */
$detailLoader = $read('resources/views/components/ui/progressive-section-loader.blade.php');
$assertContains($detailLoader, "'method' => 'loadCreateSection'", 'Progressive section loader must preserve the Create-form default method.');
$assertContains($detailLoader, '$wire[@js($method)]', 'Progressive section loader must support page-specific detail loader methods.');

$jobDetailLoading = $read('app/Livewire/Jobs/Concerns/ManagesDetailProgressiveLoading.php');
$orderData = $read('app/Livewire/Jobs/Concerns/BuildsOrderPageData.php');
$orderService = $read('app/Services/LegacyJobService.php');
$orderOverview = $read('resources/views/components/jobs/detail-overview.blade.php');
$taskDetail = $read('resources/views/components/jobs/task-detail.blade.php');
foreach (['products', 'workflow', 'attachments', 'activity'] as $section) {
    $assertContains($jobDetailLoading, "'{$section}' => false", 'Order Details is missing the '.$section.' readiness boundary.');
}
foreach (['checklist', 'attachments', 'activity'] as $section) {
    $assertContains($taskDetail, 'section="'.$section.'"', 'Task Details is missing the '.$section.' progressive placeholder.');
}
foreach (['loadVisibleOverviewSummary', 'loadVisibleOverviewProducts', 'loadVisibleOverviewWorkflow', 'loadVisibleOverviewDocuments'] as $method) {
    $assertContains($orderService, 'function '.$method, 'Order Details query boundary '.$method.' is missing.');
}
$assertContains($orderData, "if (\$orderDetailSectionsReady['products'])", 'Order product relations are not readiness-gated.');
$assertContains($orderData, "if (\$orderDetailSectionsReady['workflow'])", 'Order workflow relations are not readiness-gated.');
$assertContains($orderOverview, 'method="loadDetailSection"', 'Order Details is missing viewport-triggered section hydration.');
$assertContains($taskDetail, '$task->relationLoaded(\'comments\')', 'Task activity must not touch unloaded comment relations.');

$inquiryDetail = $read('app/Livewire/Inquiries/Concerns/ManagesInquiryDetail.php');
$inquiryDetailView = $read('resources/views/livewire/inquiries/sections/detail.blade.php');
$assertContains($inquiryIndex, 'public array $inquiryDetailSectionsReady', 'Inquiry Details is missing section readiness state.');
$assertContains($inquiryDetail, 'loadDetailSection(string $section)', 'Inquiry Details is missing its viewport section loader.');
foreach (['products', 'taskflow', 'documents', 'activity'] as $section) {
    $assertContains($inquiryDetailView, 'section="'.$section.'"', 'Inquiry Details is missing the '.$section.' progressive placeholder.');
}
$assertContains($inquiryData, "\$detailSectionsReady['taskflow']", 'Inquiry Taskflow data is not guarded by readiness.');
$assertContains($inquiryData, "\$detailSectionsReady['documents']", 'Inquiry documents are not guarded by readiness.');
$assertContains($inquiryData, "\$detailSectionsReady['activity']", 'Inquiry activity is not guarded by readiness.');

$productManager = $read('app/Livewire/MasterData/Concerns/ManagesProductRecords.php');
$productView = $read('resources/views/components/catalog/product-view.blade.php');
$assertContains($masterIndex, 'public array $productDetailSectionsReady', 'Product Details is missing progressive section state.');
$assertContains($productManager, 'loadProductDetailSection(string $section)', 'Product Details is missing its section loader.');
foreach (['pricing', 'options', 'documents'] as $section) {
    $assertContains($productView, 'section="'.$section.'"', 'Product Details is missing the '.$section.' progressive placeholder.');
}
$assertContains($productView, '$pricingReady ? collect($product->productPriceBreakpoints())', 'Product pricing parsing is still eager.');

$clientDetail = $read('app/Livewire/Clients/Concerns/ManagesClientDetail.php');
$clientService = $read('app/Services/ClientService.php');
$clientDetailView = $read('resources/views/components/clients/detail.blade.php');
$assertContains($clientIndex, 'public array $clientDetailSectionsReady', 'Client Details is missing progressive section state.');
$assertContains($clientDetail, 'loadClientDetailSection(string $section)', 'Client Details is missing its address section loader.');
$assertContains($clientService, 'function detailShell', 'Client Details is missing the lightweight shell query.');
$assertContains($clientDetailView, 'section="addresses"', 'Client Details is missing the address progressive placeholder.');
$assertContains($clientData, "\$this->clientDetailTab === 'orders'", 'Client order metrics must remain tab-demand loaded.');

$livewireConfig = $read('config/livewire.php');
$assertContains($livewireConfig, "'component_placeholder' => 'livewire.shared.page-placeholder'", 'config/livewire.php is missing the centralized component placeholder.');

$placeholder = $read('resources/views/livewire/shared/page-placeholder.blade.php');
foreach (['aria-busy="true"', 'ft-progressive-skeleton', 'table-rows-placeholder'] as $needle) {
    $assertContains($placeholder, $needle, 'Page placeholder is missing '.$needle.'.');
}

if ($failures !== []) {
    fwrite(STDERR, "Progressive page loading policy FAIL\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "Progressive page loading policy PASS\n";
echo " - route-sensitive Create/Detail/editor components mount on the original GET\n";
echo " - plain read/list workspaces may defer safely after the application shell\n";
echo " - heavy sections keep internal wire:init / viewport-lazy query boundaries\n";
echo " - centralized query-free placeholders remain available for deferred sections\n";
