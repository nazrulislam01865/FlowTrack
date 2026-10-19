<div
    class="ft-progressive-section-placeholder"
    role="status"
    aria-live="polite"
    aria-busy="true"
    aria-label="Loading page content"
>
    <div class="ft-progressive-skeleton" aria-hidden="true">
        <span style="--ft-skeleton-width: 22%; height: 18px"></span>
        <span style="--ft-skeleton-width: 38%; height: 28px"></span>
        <span style="--ft-skeleton-width: 54%; height: 14px"></span>
    </div>

    <?php echo $__env->make('livewire.shared.card-list-placeholder', ['cards' => 3], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>

    <div style="height: 14px" aria-hidden="true"></div>

    <?php echo $__env->make('livewire.shared.table-rows-placeholder', ['columns' => 6, 'rows' => 6], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/shared/page-placeholder.blade.php ENDPATH**/ ?>