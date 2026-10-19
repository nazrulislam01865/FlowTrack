<?php
    $masterData = app(\App\Services\MasterDataService::class);
    $tone = static function (string $status): string {
        return match (true) {
            str_contains($status, 'Converted'), str_contains($status, 'Completed') => 'green',
            str_contains($status, 'Dead'), str_contains($status, 'Closed') => 'red',
            str_contains($status, 'Ready'), str_contains($status, 'On Hold') => 'amber',
            str_contains($status, 'Waiting') => 'purple',
            default => 'blue',
        };
    };
    $priorityTone = static function (string $priority): string {
        return match (strtolower(trim($priority))) {
            'critical', 'urgent' => 'red',
            'high' => 'amber',
            'low' => 'green',
            default => 'blue',
        };
    };
    $initials = static function (?string $name): string {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        return strtoupper(substr(implode('', array_map(fn ($part) => substr($part, 0, 1), $parts)), 0, 2)) ?: '—';
    };
    $mentionText = static function (?string $text): string {
        $escaped = e((string) $text);
        return preg_replace('/(?<![\pL\pN._-])@([\pL\pN][\pL\pN._-]*)/u', '<span class="mention">@$1</span>', $escaped) ?? $escaped;
    };
    $inquiryToolbarIsClear = trim((string) $search) === ''
        && $quick === 'all'
        && $listStatus === ''
        && $listClient === ''
        && $dateFrom === ''
        && $dateTo === ''
        && ! $hideCompleted;
    $inquiryPendingToolbarIsClear = $pendingListStatus === ''
        && $pendingListClient === ''
        && $pendingDateFrom === ''
        && $pendingDateTo === ''
        && ! $pendingHideCompleted;
    $inquiryFilterDraftDirty = $pendingListStatus !== $listStatus
        || $pendingListClient !== $listClient
        || $pendingDateFrom !== $dateFrom
        || $pendingDateTo !== $dateTo
        || $pendingHideCompleted !== $hideCompleted;
    $inquiryAnyFilterActive = $metricFilter !== '' || ! $inquiryToolbarIsClear || ! $inquiryPendingToolbarIsClear;
    $canDeleteInquiries = auth()->user()->canModule('inquiries', 'delete');
    $inquiryExportQuery = array_filter([
        'search' => filled($search) ? $search : null,
        'quick' => $quick !== 'all' ? $quick : null,
        'metric' => filled($metricFilter) ? $metricFilter : null,
        'client' => filled($listClient) ? $listClient : null,
        'status' => filled($listStatus) ? $listStatus : null,
        'hide_completed' => $hideCompleted ? 1 : null,
        'date_from' => filled($dateFrom) ? $dateFrom : null,
        'date_to' => filled($dateTo) ? $dateTo : null,
    ], static fn ($value) => $value !== null && $value !== '');
    // RFQ delivery failures are represented in the product workspace itself.
    // Keep the generic page banner for genuine form/validation errors only.
    $pageError = collect($errors->getBag('default')->messages())
        ->except(['rfqDelivery'])
        ->flatten()
        ->first();
?>

<div class="ft-inquiry-prototype">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('success')): ?><div class="flash-inline"><?php echo e(session('success')); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mode !== 'create' && filled($pageError)): ?><div class="error-inline"><?php echo e($pageError); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($mode === 'list'): ?>
        <?php echo $__env->make('livewire.inquiries.sections.list', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <?php elseif($mode === 'create'): ?>
        <?php echo $__env->make('livewire.inquiries.sections.create', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <?php else: ?>
        <?php echo $__env->make('livewire.inquiries.sections.detail', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if (isset($component)) { $__componentOriginal01cd1b9acb87f79a52a4de5154a1445e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal01cd1b9acb87f79a52a4de5154a1445e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.catalog.missing-product-supplier-modal','data' => ['show' => $showMissingProductSupplierModal,'productName' => $missingProductSupplierName,'choice' => $missingProductSupplierChoice,'existingSupplierId' => $missingProductExistingSupplierId,'existingSupplierLabel' => $missingProductExistingSupplierLabel,'newSupplierName' => $missingProductNewSupplierName,'newSupplierEmail' => $missingProductNewSupplierEmail,'allowSkip' => $missingProductSupplierAllowSkip,'recordLabel' => $missingProductSupplierRecordLabel,'submitMode' => $missingProductSupplierSubmitMode,'selectorContext' => $missingProductSupplierSelectorContext]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('catalog.missing-product-supplier-modal'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['show' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($showMissingProductSupplierModal),'product-name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductSupplierName),'choice' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductSupplierChoice),'existing-supplier-id' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductExistingSupplierId),'existing-supplier-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductExistingSupplierLabel),'new-supplier-name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductNewSupplierName),'new-supplier-email' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductNewSupplierEmail),'allow-skip' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductSupplierAllowSkip),'record-label' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductSupplierRecordLabel),'submit-mode' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductSupplierSubmitMode),'selector-context' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($missingProductSupplierSelectorContext)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal01cd1b9acb87f79a52a4de5154a1445e)): ?>
<?php $attributes = $__attributesOriginal01cd1b9acb87f79a52a4de5154a1445e; ?>
<?php unset($__attributesOriginal01cd1b9acb87f79a52a4de5154a1445e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal01cd1b9acb87f79a52a4de5154a1445e)): ?>
<?php $component = $__componentOriginal01cd1b9acb87f79a52a4de5154a1445e; ?>
<?php unset($__componentOriginal01cd1b9acb87f79a52a4de5154a1445e); ?>
<?php endif; ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/inquiries/index.blade.php ENDPATH**/ ?>