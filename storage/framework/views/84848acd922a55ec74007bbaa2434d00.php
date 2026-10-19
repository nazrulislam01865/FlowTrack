<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'stages' => collect(),
    'selectedStageId' => null,
    'selectedStageValue' => null,
    'mode' => 'navigate',
    'title' => 'Orders by workflow stage',
    'description' => 'Click a stage to view the matching orders.',
    'showAllLabel' => 'Show all',
    'showHeader' => true,
    'filterMethod' => 'selectStage',
    'countLabel' => 'Current orders',
    'navigationQuery' => [],
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'stages' => collect(),
    'selectedStageId' => null,
    'selectedStageValue' => null,
    'mode' => 'navigate',
    'title' => 'Orders by workflow stage',
    'description' => 'Click a stage to view the matching orders.',
    'showAllLabel' => 'Show all',
    'showHeader' => true,
    'filterMethod' => 'selectStage',
    'countLabel' => 'Current orders',
    'navigationQuery' => [],
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $filterMode = $mode === 'filter';
    $wireFilterMode = $mode === 'wire-filter';
    $interactiveFilterMode = $filterMode || $wireFilterMode;
    $selectedKey = filled($selectedStageValue)
        ? (string) $selectedStageValue
        : (filled($selectedStageId) ? (string) $selectedStageId : '');

    $stageTextColor = static function (?string $color): string {
        $hex = trim((string) $color);

        if (! preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $matches)) {
            return '#ffffff';
        }

        $rgb = $matches[1];
        $red = hexdec(substr($rgb, 0, 2));
        $green = hexdec(substr($rgb, 2, 2));
        $blue = hexdec(substr($rgb, 4, 2));
        $luminance = (0.299 * $red) + (0.587 * $green) + (0.114 * $blue);

        return $luminance >= 170 ? '#16324f' : '#ffffff';
    };
?>

<section class="ft-order-workflow-stage-overview" aria-label="<?php echo e($title); ?>">
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showHeader): ?>
        <div class="ft-order-workflow-stage-head">
            <div>
                <h2><?php echo e($title); ?></h2>
                <p><?php echo e($description); ?></p>
            </div>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($filterMode): ?>
                <button
                    class="ft-order-workflow-stage-show-all"
                    type="button"
                    wire:click="selectStage(null)"
                >
                    <?php echo e($showAllLabel); ?>

                </button>
            <?php elseif($wireFilterMode): ?>
                <button
                    class="ft-order-workflow-stage-show-all"
                    type="button"
                    wire:click="<?php echo e($filterMethod); ?>('')"
                >
                    <?php echo e($showAllLabel); ?>

                </button>
            <?php else: ?>
                <a
                    class="ft-order-workflow-stage-show-all"
                    href="<?php echo e(route('jobs.index')); ?>"
                    wire:navigate.hover
                >
                    <?php echo e($showAllLabel); ?>

                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <div class="ft-order-workflow-stage-strip">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $stages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $stage): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
            <?php
                $stageId = (int) data_get($stage, 'id');
                $stageValue = data_get($stage, 'filter_value', $stageId);
                $stageColor = (string) data_get($stage, 'color', '#2d72d9');
                $isSelectedStage = $interactiveFilterMode
                    && $selectedKey !== ''
                    && $selectedKey === (string) $stageValue;
                $stageName = (string) (data_get($stage, 'short_name') ?: data_get($stage, 'name'));
                $stageCountLabel = (string) data_get($stage, 'count_label', $countLabel);
                $style = '--stage:'.$stageColor.';--stage-text:'.$stageTextColor($stageColor);
            ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($filterMode): ?>
                <button
                    type="button"
                    class="ft-order-workflow-stage-card <?php echo e($isSelectedStage ? 'active' : ''); ?>"
                    style="<?php echo e($style); ?>"
                    wire:click="selectStage(<?php echo e($stageId); ?>)"
                    aria-pressed="<?php echo e($isSelectedStage ? 'true' : 'false'); ?>"
                >
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isSelectedStage): ?>
                        <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <span class="ft-order-workflow-stage-kicker">Stage <?php echo e((int) data_get($stage, 'sequence')); ?></span>
                    <b title="<?php echo e(data_get($stage, 'name')); ?>"><?php echo e($stageName); ?></b>
                    <span class="ft-order-workflow-stage-count">
                        <em><?php echo e($stageCountLabel); ?></em>
                        <strong><?php echo e(number_format((int) data_get($stage, 'count', 0))); ?></strong>
                    </span>
                </button>
            <?php elseif($wireFilterMode): ?>
                <button
                    type="button"
                    class="ft-order-workflow-stage-card <?php echo e($isSelectedStage ? 'active' : ''); ?>"
                    style="<?php echo e($style); ?>"
                    wire:click="<?php echo e($filterMethod); ?>('<?php echo e(str_replace("'", "\\'", (string) $stageValue)); ?>')"
                    aria-pressed="<?php echo e($isSelectedStage ? 'true' : 'false'); ?>"
                >
                    <?php if($isSelectedStage): ?>
                        <span class="ft-order-workflow-stage-selected" aria-hidden="true" title="Selected">✓</span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                    <span class="ft-order-workflow-stage-kicker">Stage <?php echo e((int) data_get($stage, 'sequence')); ?></span>
                    <b title="<?php echo e(data_get($stage, 'name')); ?>"><?php echo e($stageName); ?></b>
                    <span class="ft-order-workflow-stage-count">
                        <em><?php echo e($stageCountLabel); ?></em>
                        <strong><?php echo e(number_format((int) data_get($stage, 'count', 0))); ?></strong>
                    </span>
                </button>
            <?php else: ?>
                <a
                    class="ft-order-workflow-stage-card"
                    style="<?php echo e($style); ?>"
                    href="<?php echo e(route('jobs.index', array_merge($navigationQuery, ['phase' => $stageId]))); ?>"
                    wire:navigate.hover
                >
                    <span class="ft-order-workflow-stage-kicker">Stage <?php echo e((int) data_get($stage, 'sequence')); ?></span>
                    <b title="<?php echo e(data_get($stage, 'name')); ?>"><?php echo e($stageName); ?></b>
                    <span class="ft-order-workflow-stage-count">
                        <em><?php echo e($stageCountLabel); ?></em>
                        <strong><?php echo e(number_format((int) data_get($stage, 'count', 0))); ?></strong>
                    </span>
                </a>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($summaryCards)): ?>
        <div class="ft-order-workflow-metric-strip" aria-label="Order summary filters">
            <?php echo e($summaryCards); ?>

        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</section>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/orders/workflow-stage-overview.blade.php ENDPATH**/ ?>