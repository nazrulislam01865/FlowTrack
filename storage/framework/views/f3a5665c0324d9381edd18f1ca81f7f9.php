<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($branding['name'] ?? 'FlowTrack'); ?></title>
    <link rel="icon" href="<?php echo e($branding['favicon_url'] ?? asset('images/flowtrack-favicon.svg')); ?>">
    <script
        src="<?php echo e(asset('js/flowtrack-image-fallback.js')); ?>?v=<?php echo e(\App\Support\FrontendBuildVersion::current()); ?>"
        data-fallback-src="<?php echo e(asset('images/flowtrack-image-fallback.svg')); ?>"
    ></script>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/login.css', 'resources/theme/flowtrack/core.css']); ?>
    <link rel="stylesheet" href="<?php echo e(asset('css/flowtrack-system-branding.css')); ?>?v=20261019">
</head>
<body class="<?php echo e(($branding['system_footer']['enabled'] ?? true) ? 'ft-has-footer' : ''); ?>">
<div class="login screen">
    <section class="login-visual" aria-label="<?php echo e($branding['name'] ?? 'FlowTrack'); ?>">
        <div class="login-visual-content">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($branding['logo_url'] ?? null): ?>
                <img class="ft-login-hero-logo" src="<?php echo e($branding['logo_url']); ?>" alt="<?php echo e($branding['name'] ?? 'FlowTrack'); ?>">
            <?php else: ?>
                <div class="ft-login-hero-name"><?php echo e($branding['name'] ?? 'FlowTrack'); ?></div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="login-message">
                <h1>Every order,<br>clearly on track.</h1>
                <p>Manage every quote, proof, sample, production<br class="desktop-break"> milestone and delivery from one clear workspace.</p>

                <div class="flow-preview" aria-label="Order workflow stages">
                    <div>01 · Quote</div>
                    <div>02 · Artwork</div>
                    <div>03 · Production</div>
                    <div>04 · Delivery</div>
                </div>
            </div>
        </div>

        <div class="login-visual-footer">Built for smoother promotional workflows</div>
    </section>

    <section class="login-form-wrap">
        <form class="login-form" method="POST" action="<?php echo e(route('login.store')); ?>">
            <?php echo csrf_field(); ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->query('reason') === 'other-device'): ?>
                <div class="validation-error ft-login-session-message" role="alert">
                    Another device logged in with the same user ID and password. Your previous session was logged out.
                </div>
            <?php elseif(request()->query('reason') === 'timeout'): ?>
                <div class="validation-error ft-login-session-message" role="alert">
                    Your <?php echo e(config('session.lifetime', 30)); ?>-minute session has expired. Please sign in again.
                </div>
            <?php elseif(request()->query('reason') === 'session-refresh'): ?>
                <div class="validation-error ft-login-session-message" role="alert">
                    Your browser session was refreshed securely. Please sign in again.
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <div class="login-form-brand">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($branding['logo_url'] ?? null): ?>
                    <img src="<?php echo e($branding['logo_url']); ?>" alt="" aria-hidden="true">
                <?php else: ?>
                    <span class="ft-login-brand-mark" aria-hidden="true">FT</span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <span><?php echo e($branding['name'] ?? 'FlowTrack'); ?></span>
            </div>

            <h2>Welcome back</h2>
            <p class="login-form-intro">Sign in to manage Orders, assignments and client delivery.</p>

            <div class="field">
                <label for="email">Email</label>
                <input
                    id="email"
                    name="email"
                    value="<?php echo e(old('email')); ?>"
                    type="email"
                    autocomplete="email"
                    required
                    autofocus
                >
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <div class="validation-error" role="alert"><?php echo e($message); ?></div>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="password-field">
                    <input
                        id="password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                    <button
                        type="button"
                        id="togglePassword"
                        class="password-toggle"
                        tabindex="-1"
                        title="Toggle password visibility"
                        aria-label="Show password"
                    >
                        <svg class="eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        <svg class="eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                            <line x1="1" y1="1" x2="23" y2="23"></line>
                        </svg>
                    </button>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <div class="validation-error" role="alert"><?php echo e($message); ?></div>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <label class="check-row">
                <input type="checkbox" name="remember" value="1" <?php if(old('remember')): echo 'checked'; endif; ?>>
                <span>Remember me</span>
            </label>

            <button class="primary" type="submit">Sign in</button>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if((bool) config('flowtrack.order_tracking_enabled', false)): ?>
                <hr class="login-tracking-divider">

                <div class="login-tracking-prompt">
                    <span class="login-tracking-prompt-label">Just checking an order?</span>
                    <a href="<?php echo e(route('order.track')); ?>" class="login-tracking-link">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                            <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                            <line x1="12" y1="22.08" x2="12" y2="12"></line>
                        </svg>
                        <span>Track your order &rarr;</span>
                    </a>
                    <p class="login-tracking-subtext">Use your order or reference number and email. No sign-in needed.</p>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </form>
    </section>
</div>
<?php if (isset($component)) { $__componentOriginal7800c327eec291517b20175d0dfe4a85 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7800c327eec291517b20175d0dfe4a85 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.system-footer','data' => ['branding' => $branding]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('system-footer'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['branding' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($branding)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7800c327eec291517b20175d0dfe4a85)): ?>
<?php $attributes = $__attributesOriginal7800c327eec291517b20175d0dfe4a85; ?>
<?php unset($__attributesOriginal7800c327eec291517b20175d0dfe4a85); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7800c327eec291517b20175d0dfe4a85)): ?>
<?php $component = $__componentOriginal7800c327eec291517b20175d0dfe4a85; ?>
<?php unset($__componentOriginal7800c327eec291517b20175d0dfe4a85); ?>
<?php endif; ?>

<script>
    const togglePassword = document.getElementById('togglePassword');
    const passwordInput = document.getElementById('password');

    togglePassword?.addEventListener('click', function () {
        const showPassword = passwordInput.type === 'password';
        passwordInput.type = showPassword ? 'text' : 'password';
        this.classList.toggle('is-visible', showPassword);
        this.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
    });
</script>
</body>
</html>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/auth/login.blade.php ENDPATH**/ ?>