<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['person']));

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

foreach (array_filter((['person']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
    $total = (int) $person->total_task_count;
    $open = (int) $person->open_count;
    $completed = (int) $person->completed_count;
    $completionRate = $person->completion_rate;
    $inquiryCount = (int) $person->inquiry_task_count;
    $orderCount = (int) $person->order_task_count;
    $departmentColor = $person->department_color ?? null;
?>
<article
    <?php echo e($attributes->class(['ft-mgmt-team-card', 'has-department-color' => filled($departmentColor)])); ?>

    style="<?php echo e(\App\Support\MasterColor::style($departmentColor)); ?>"
>
    <div class="ft-mgmt-team-prototype-head">
        <div class="ft-mgmt-person ft-mgmt-team-person">
            <?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['user' => $person,'name' => $person->name,'size' => 44]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($person),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($person->name),'size' => 44]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2)): ?>
<?php $attributes = $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2; ?>
<?php unset($__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2)): ?>
<?php $component = $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2; ?>
<?php unset($__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2); ?>
<?php endif; ?>
            <div class="ft-mgmt-team-person-copy">
                <strong title="<?php echo e($person->name); ?>"><?php echo e($person->name); ?></strong>
                <span><?php echo e($person->department?->name ?? 'Team member'); ?></span>
            </div>
        </div>

        <div class="ft-mgmt-team-head-metric">
            <b><?php echo e($completionRate === null ? '—' : $completionRate.'%'); ?></b>
            <span>Completion rate</span>
        </div>

        <div class="ft-mgmt-team-head-metric ft-mgmt-team-head-metric-last">
            <b aria-hidden="true">&nbsp;</b>
            <span>On-time rate</span>
        </div>
    </div>

    <div class="ft-mgmt-team-source-row">
        <div class="ft-mgmt-team-source-pills">
            <span>Inquiry <b><?php echo e($inquiryCount); ?></b></span>
            <span>Order <b><?php echo e($orderCount); ?></b></span>
        </div>
        <span class="ft-mgmt-team-assigned-count"><?php echo e($total); ?> <?php echo e($total === 1 ? 'task' : 'tasks'); ?> assigned</span>
    </div>

    <div class="ft-mgmt-team-divider"></div>

    <div class="ft-mgmt-team-stat-list">
        <div class="ft-mgmt-team-stat ft-mgmt-team-stat-total">
            <span class="ft-mgmt-team-stat-chip">Total tasks</span>
            <b><?php echo e($total); ?></b>
        </div>
        <div class="ft-mgmt-team-stat ft-mgmt-team-stat-open">
            <span class="ft-mgmt-team-stat-chip">Open tasks</span>
            <b><?php echo e($open); ?></b>
        </div>
        <div class="ft-mgmt-team-stat ft-mgmt-team-stat-completed">
            <span class="ft-mgmt-team-stat-chip">Completed tasks</span>
            <b><?php echo e($completed); ?></b>
        </div>
    </div>

    <div class="ft-mgmt-team-divider"></div>

    <div class="ft-mgmt-team-completion-section">
        <div class="ft-mgmt-team-completion-title">
            <span>Completion rate</span>
            <b><?php echo e($completionRate === null ? '—' : $completionRate.'%'); ?></b>
        </div>
        <div class="ft-mgmt-completion-bar" role="progressbar" aria-label="Completion rate" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo e($completionRate ?? 0); ?>">
            <span style="width:<?php echo e($completionRate ?? 0); ?>%"></span>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($completionRate === null): ?>
            <div class="ft-mgmt-team-empty-copy">No assigned tasks in this reporting period.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <div class="ft-mgmt-team-divider"></div>

    <div class="ft-mgmt-team-workload-footer">
        <div class="ft-mgmt-team-workload-left">
            <span>Workload</span>
            <b class="ft-mgmt-team-workload-blank" aria-hidden="true"></b>
        </div>
        <span class="ft-mgmt-team-combined-copy">Inquiry and Order tasks combined</span>
    </div>
</article>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/dashboard/team-performance-card.blade.php ENDPATH**/ ?>