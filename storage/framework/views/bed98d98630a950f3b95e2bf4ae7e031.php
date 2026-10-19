<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'lookupType' => 'order',
    'identifier' => 'FO-337118',
    'email' => '',
    'lookupError' => null,
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
    'lookupType' => 'order',
    'identifier' => 'FO-337118',
    'email' => '',
    'lookupError' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<section class="ft-tracking-search-card" aria-labelledby="tracking-search-title">
    <div class="ft-tracking-search-header">
        <h2 id="tracking-search-title">Track your order</h2>
        <p>Check the latest progress of your order.</p>
    </div>

    <form method="POST" action="<?php echo e(route('order.track.lookup')); ?>" class="ft-tracking-form" data-order-tracking-form>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="lookup_type" value="<?php echo e($lookupType === 'reference' ? 'reference' : 'order'); ?>" data-tracking-lookup-type>

        <div class="ft-tracking-tabs" role="tablist" aria-label="Tracking lookup type">
            <button type="button" role="tab" data-tracking-tab="order" aria-selected="<?php echo e($lookupType !== 'reference' ? 'true' : 'false'); ?>" class="<?php echo e($lookupType !== 'reference' ? 'is-active' : ''); ?>">Order number</button>
            <button type="button" role="tab" data-tracking-tab="reference" aria-selected="<?php echo e($lookupType === 'reference' ? 'true' : 'false'); ?>" class="<?php echo e($lookupType === 'reference' ? 'is-active' : ''); ?>">Reference number</button>
        </div>

        <div class="ft-tracking-field">
            <label for="trackingIdentifier" data-tracking-identifier-label><?php echo e($lookupType === 'reference' ? 'Reference number' : 'Order number'); ?></label>
            <input
                id="trackingIdentifier"
                name="identifier"
                type="text"
                value="<?php echo e(old('identifier', $identifier)); ?>"
                placeholder="<?php echo e($lookupType === 'reference' ? 'Reference number' : 'Order number'); ?>"
                maxlength="120"
                autocomplete="off"
                required
                data-tracking-identifier
            >
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['identifier'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="ft-tracking-error" role="alert"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <div class="ft-tracking-field">
            <label for="trackingEmail">Email address</label>
            <input
                id="trackingEmail"
                name="email"
                type="email"
                value="<?php echo e(old('email', $email)); ?>"
                placeholder="Email used for this order"
                maxlength="255"
                autocomplete="email"
                required
            >
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="ft-tracking-error" role="alert"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($lookupError): ?>
            <div class="ft-tracking-error ft-tracking-error--lookup" role="alert"><?php echo e($lookupError); ?></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if (isset($component)) { $__componentOriginala8bb031a483a05f647cb99ed3a469847 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginala8bb031a483a05f647cb99ed3a469847 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.button','data' => ['type' => 'submit','class' => 'ft-tracking-submit']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['type' => 'submit','class' => 'ft-tracking-submit']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>
Track order <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginala8bb031a483a05f647cb99ed3a469847)): ?>
<?php $attributes = $__attributesOriginala8bb031a483a05f647cb99ed3a469847; ?>
<?php unset($__attributesOriginala8bb031a483a05f647cb99ed3a469847); ?>
<?php endif; ?>
<?php if (isset($__componentOriginala8bb031a483a05f647cb99ed3a469847)): ?>
<?php $component = $__componentOriginala8bb031a483a05f647cb99ed3a469847; ?>
<?php unset($__componentOriginala8bb031a483a05f647cb99ed3a469847); ?>
<?php endif; ?>

        <p class="ft-tracking-helper">
            <?php if (isset($component)) { $__componentOriginal0d78ef242c7848a0ad8a9198097ba1d5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0d78ef242c7848a0ad8a9198097ba1d5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.order-tracking.icon','data' => ['name' => 'info','size' => 18]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('order-tracking.icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'info','size' => 18]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0d78ef242c7848a0ad8a9198097ba1d5)): ?>
<?php $attributes = $__attributesOriginal0d78ef242c7848a0ad8a9198097ba1d5; ?>
<?php unset($__attributesOriginal0d78ef242c7848a0ad8a9198097ba1d5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0d78ef242c7848a0ad8a9198097ba1d5)): ?>
<?php $component = $__componentOriginal0d78ef242c7848a0ad8a9198097ba1d5; ?>
<?php unset($__componentOriginal0d78ef242c7848a0ad8a9198097ba1d5); ?>
<?php endif; ?>
            <span>Enter the email address linked to your order.</span>
        </p>
    </form>

    <div class="ft-tracking-qr-card">
        <div class="ft-tracking-qr-copy">
            <h3>Have a QR code?</h3>
            <p>Scan the QR code on your order confirmation<br>to open tracking directly.</p>
        </div>
        <div class="ft-tracking-qr-illustration" aria-hidden="true">
            <div class="ft-tracking-document-shape">
                <span></span><span></span><span></span>
            </div>
            <div class="ft-tracking-qr-shape">
                <i></i><i></i><i></i><i></i><i></i>
            </div>
        </div>
    </div>
</section>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/components/order-tracking/search-card.blade.php ENDPATH**/ ?>