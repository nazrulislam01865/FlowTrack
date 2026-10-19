<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'action',
    'filters' => [],
    'buttonClass' => '',
    'entityLabel' => 'records',
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
    'action',
    'filters' => [],
    'buttonClass' => '',
    'entityLabel' => 'records',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $exportModalId = 'ft-export-period-'.substr(md5((string) $action.'|'.(string) $entityLabel), 0, 10);
    $currentMonth = app(\App\Services\WorkspaceSettingsService::class)->localNow()->format('Y-m');
    $safeFilters = collect($filters)
        ->except(['date_from', 'date_to', 'export_period', 'export_month'])
        ->filter(static fn ($value) => $value !== null && $value !== '')
        ->all();
?>
<div
    class="ft-export-period"
    x-data="{ open: false, period: 'this_month', selectedMonth: <?php echo \Illuminate\Support\Js::from($currentMonth)->toHtml() ?> }"
    x-on:keydown.escape.window="open = false"
>
    <button
        type="button"
        class="<?php echo e($buttonClass); ?> ft-export-period-trigger"
        x-on:click="open = true"
        aria-haspopup="dialog"
        :aria-expanded="open ? 'true' : 'false'"
        aria-controls="<?php echo e($exportModalId); ?>"
        title="Choose a report period and export <?php echo e($entityLabel); ?>"
    >⇩ Export</button>

    <div
        x-cloak
        x-show="open"
        x-transition.opacity.duration.150ms
        class="ft-export-period-layer"
        x-on:click.self="open = false"
    >
        <section
            id="<?php echo e($exportModalId); ?>"
            class="ft-export-period-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="<?php echo e($exportModalId); ?>-title"
            x-on:click.stop
        >
            <div class="ft-export-period-head">
                <div>
                    <h2 id="<?php echo e($exportModalId); ?>-title">Export <?php echo e(ucfirst($entityLabel)); ?></h2>
                    <p>Choose the created-date period for the report. Other active list filters and your access scope will still apply.</p>
                </div>
                <button type="button" class="ft-export-period-close" x-on:click="open = false" aria-label="Close export options">×</button>
            </div>

            <form class="ft-export-period-body" method="GET" action="<?php echo e($action); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $safeFilters; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $name => $value): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <input type="hidden" name="<?php echo e($name); ?>" value="<?php echo e($value); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>

                <span class="ft-export-period-label">Report period</span>
                <div class="ft-export-period-options">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = [
                        'today' => ['Today', 'Created today'],
                        'last_7_days' => ['Last 7 days', 'Today + previous 6 days'],
                        'last_30_days' => ['Last 30 days', 'Today + previous 29 days'],
                        'this_month' => ['This month', 'Current calendar month'],
                        'selected_month' => ['Selected month', 'Choose any calendar month'],
                        'all_time' => ['All time', 'No created-date restriction'],
                    ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value => [$label, $help]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <label class="ft-export-period-option" :class="period === '<?php echo e($value); ?>' ? 'is-active' : ''">
                            <input type="radio" name="export_period" value="<?php echo e($value); ?>" x-model="period">
                            <span class="ft-export-period-copy">
                                <strong><?php echo e($label); ?></strong>
                                <small><?php echo e($help); ?></small>
                            </span>
                        </label>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </div>

                <div class="ft-export-period-month" x-cloak x-show="period === 'selected_month'">
                    <label for="<?php echo e($exportModalId); ?>-month">Select month</label>
                    <div
                        class="ft-export-period-month-control"
                        role="button"
                        tabindex="0"
                        aria-label="Open month picker"
                        x-on:click="const picker = $refs.monthPicker; if (!picker) return; picker.focus({ preventScroll: true }); if (typeof picker.showPicker === 'function') { try { picker.showPicker(); } catch (e) {} }"
                        x-on:keydown.enter.prevent="const picker = $refs.monthPicker; if (!picker) return; picker.focus({ preventScroll: true }); if (typeof picker.showPicker === 'function') { try { picker.showPicker(); } catch (e) {} }"
                        x-on:keydown.space.prevent="const picker = $refs.monthPicker; if (!picker) return; picker.focus({ preventScroll: true }); if (typeof picker.showPicker === 'function') { try { picker.showPicker(); } catch (e) {} }"
                    >
                        <input
                            id="<?php echo e($exportModalId); ?>-month"
                            x-ref="monthPicker"
                            type="month"
                            name="export_month"
                            x-model="selectedMonth"
                            :required="period === 'selected_month'"
                        >
                    </div>
                </div>

                <p class="ft-export-period-note">The export includes all matching records in the chosen period, not only the rows currently visible on the page.</p>

                <div class="ft-export-period-actions">
                    <button type="button" class="ft-export-period-cancel" x-on:click="open = false">Cancel</button>
                    <button type="submit" class="ft-export-period-submit">Generate &amp; Export</button>
                </div>
            </form>
        </section>
    </div>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/ui/list-export-period-modal.blade.php ENDPATH**/ ?>