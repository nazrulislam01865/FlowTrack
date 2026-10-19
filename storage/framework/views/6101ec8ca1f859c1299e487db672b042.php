<section class="ft-mgmt-panel ft-mgmt-mentions-panel" id="mentions-for-you">
    <div class="ft-mgmt-mentions-head">
        <div class="ft-mgmt-mentions-heading">
            <div class="ft-mgmt-mentions-title-line">
                <h2>Mentions for you</h2>
                <span class="ft-mgmt-mentions-unread-count"><?php echo e($unreadMentionCount); ?> unread</span>
            </div>
            <p><?php echo e(app(\App\Services\AccessControlService::class)->isAdministrator(auth()->user()) ? 'All mentions across Orders, Tasks and Inquiries' : 'Comments where teammates tagged you in Orders or Inquiries'); ?></p>
        </div>
        <button class="ft-mgmt-mentions-mark-read" type="button" wire:click="markAllRead" <?php if($unreadMentionCount === 0): echo 'disabled'; endif; ?>>Mark all as read</button>
    </div>

    <div class="ft-mgmt-mentions-tabs" role="tablist" aria-label="Mention type">
        <button type="button" class="<?php echo e($filter === 'all' ? 'active' : ''); ?>" wire:click="setFilter('all')">All</button>
        <button type="button" class="<?php echo e($filter === 'unread' ? 'active' : ''); ?>" wire:click="setFilter('unread')">Unread (<?php echo e($unreadMentionCount); ?>)</button>
        <button type="button" class="<?php echo e($filter === 'orders' ? 'active' : ''); ?>" wire:click="setFilter('orders')">Orders</button>
        <button type="button" class="<?php echo e($filter === 'inquiries' ? 'active' : ''); ?>" wire:click="setFilter('inquiries')">Inquiries</button>
    </div>

    <div class="ft-mgmt-mentions-list">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $mentions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $mention): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
            <?php
                $route = app(\App\Services\NotificationService::class)->urlFor($mention);
                $actor = $mention->actor;
                $actorName = trim((string) ($actor?->name ?? ''));

                if ($actorName === '' && preg_match('/^(.*?) mentioned (?:you|a user) in /u', (string) $mention->title, $actorMatch)) {
                    $actorName = trim((string) ($actorMatch[1] ?? ''));
                }
                $actorName = $actorName !== '' ? $actorName : 'FlowTrack';

                $isInquiry = (bool) ($mention->inquiry_id || $mention->inquiry_task_id);
                $reference = $isInquiry
                    ? ($mention->inquiry?->inquiry_number ?: 'Inquiry')
                    : ($mention->job?->displayOrderNumber() ?: 'Order');

                $message = str(app(\App\Services\MentionService::class)->displayText($mention->message))
                    ->squish()
                    ->limit(180)
                    ->toString();
                $escapedMessage = e($message);
                $currentMention = '@'.auth()->user()->name;
                $messageHtml = str_replace(
                    e($currentMention),
                    '<span class="ft-mgmt-mention-user">'.e($currentMention).'</span>',
                    $escapedMessage
                );

                if ($isInquiry) {
                    $contextTitle = $mention->inquiryTask?->title ?: 'Inquiry activity';
                    $contextLabel = 'Inquiry · '.$contextTitle;
                } else {
                    $phaseName = $mention->task?->phase?->short_name
                        ?: $mention->task?->phase?->name
                        ?: $mention->job?->phase?->short_name
                        ?: $mention->job?->phase?->name
                        ?: 'Order';
                    $contextTitle = $mention->task?->title ?: 'Order activity';
                    $contextLabel = $phaseName.' · '.$contextTitle;
                }
            ?>
            <div class="ft-mgmt-mention-row <?php echo e($mention->read_at ? '' : 'is-unread'); ?>" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'dashboard-mention-'.e($mention->id).''; ?>wire:key="dashboard-mention-<?php echo e($mention->id); ?>">
                <span class="ft-mgmt-mention-unread-dot" aria-hidden="true"></span>
                <?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['class' => 'ft-mgmt-mention-avatar','user' => $actor,'name' => $actorName,'size' => 42]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-mgmt-mention-avatar','user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($actor),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($actorName),'size' => 42]); ?>
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
                <div class="ft-mgmt-mention-copy">
                    <strong><?php echo e($actorName); ?> <?php echo e($mention->type === 'mention_admin' ? 'mentioned a user in' : 'mentioned you in'); ?> <?php echo e($reference); ?></strong>
                    <p><?php echo $messageHtml !== '' ? $messageHtml : 'Mentioned you in a comment.'; ?></p>
                    <small><?php echo e($contextLabel); ?></small>
                </div>
                <time><?php echo e($mention->created_at?->diffForHumans(short: true)); ?></time>
                <a class="ft-mgmt-mention-view" href="<?php echo e($route); ?>">View</a>
            </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            <div class="ft-mgmt-mentions-empty">No mentions in this view.</div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</section>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/dashboard/tagged-comments.blade.php ENDPATH**/ ?>