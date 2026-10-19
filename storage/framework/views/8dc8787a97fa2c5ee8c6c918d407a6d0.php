<?php
    $orderId = (int) data_get($row, 'order_id', 0);
    $taskId = (int) data_get($row, 'next_task_id', 0);
    $label = (string) data_get($row, 'next_action', 'Open order');
    $useInlineListAction = (bool) ($inlineListActions ?? true);
?>


<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($useInlineListAction && $orderId > 0 && $taskId > 0): ?>
    <button
        type="button"
        class="stage-action stage-action-button"
        wire:click="openListWorkflowAction(<?php echo e($orderId); ?>, <?php echo e($taskId); ?>)"
        wire:loading.attr="disabled"
        x-on:click.stop
    >
        <?php echo e($label); ?>

    </button>
    <span class="stage-table-note">Perform action here</span>
<?php else: ?>
    <a class="stage-action" href="<?php echo e($detailUrl); ?>" wire:navigate x-on:click.stop><?php echo e($label); ?></a>
    <span class="stage-table-note">Open order to continue</span>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/orders/prototype-next-action.blade.php ENDPATH**/ ?>