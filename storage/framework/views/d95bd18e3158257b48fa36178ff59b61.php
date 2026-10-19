<?php
    $today = app(\App\Services\WorkspaceSettingsService::class)->localToday();
    $masterData = app(\App\Services\MasterDataService::class);
    $taskFlagService = app(\App\Services\TaskFlagService::class);
    $inquiryService = app(\App\Services\InquiryService::class);
    $canCreateOrder = auth()->user()->canAccess('jobs.create');
    $canCreateClient = auth()->user()->canModule('clients', 'create');
    $canCreateInquiry = auth()->user()->canModule('inquiries', 'create');

    $badgeTone = static function (?string $value): string {
        $value = mb_strtolower(trim((string) $value));
        if (str_contains($value, 'overdue') || str_contains($value, 'attention') || str_contains($value, 'blocked') || str_contains($value, 'risk')) return 'red';
        if (str_contains($value, 'due') || str_contains($value, 'waiting') || str_contains($value, 'hold') || str_contains($value, 'payment')) return 'amber';
        if (str_contains($value, 'complete') || str_contains($value, 'track') || str_contains($value, 'healthy') || str_contains($value, 'ready')) return 'green';
        if (str_contains($value, 'artwork') || str_contains($value, 'review') || str_contains($value, 'revision')) return 'purple';
        if (str_contains($value, 'unassigned') || str_contains($value, 'no flag')) return 'gray';
        return 'blue';
    };

    $taskFlag = static function ($task) use ($today, $taskFlagService, $badgeTone): array {
        $label = $taskFlagService->labelForTask($task);
        if ($label) return [$label, $badgeTone($label)];
        if ($task->due_date && $task->due_date->lt($today)) return ['Overdue '.$task->due_date->diffInDays($today).'d', 'red'];
        if ($task->due_date && $task->due_date->isSameDay($today)) return ['Due today', 'amber'];
        if ((bool) $task->needs_attention) return ['Needs attention', 'red'];
        return ['On track', 'green'];
    };

    $jobFlag = static function ($job) use ($taskFlagService, $badgeTone): array {
        if ((bool) ($job->attention_requested ?? false) || (bool) ($job->needs_attention ?? false)) return ['Needs attention', 'red'];
        $label = $taskFlagService->labelForOrder($job);
        if ($label) return [$label, $badgeTone($label)];
        return ['No flag', 'gray'];
    };

    $inquiryFlag = static function ($inquiry) use ($today): array {
        $task = $inquiry->currentTask;
        $status = mb_strtolower((string) ($task?->status ?: $inquiry->status));
        if ($task?->needs_attention) return ['Needs attention', 'red'];
        if ($task?->due_date && $task->due_date->lt($today)) return ['Overdue '.$task->due_date->diffInDays($today).'d', 'red'];
        if ($task?->due_date && $task->due_date->isSameDay($today)) return ['Due today', 'amber'];
        if (str_contains($status, 'block')) return ['Blocked', 'red'];
        if (str_contains($status, 'revision')) return ['Revision required', 'red'];
        if (str_contains($status, 'wait')) return ['Waiting', 'amber'];
        if (!$inquiry->owner_id) return ['Unassigned', 'gray'];
        return ['On track', 'green'];
    };

    $attentionOverdueLabel = static function ($dueDate) use ($today): string {
        $overdueDays = (float) $dueDate->diffInDays($today);

        return 'Overdue '.number_format($overdueDays, 2, '.', '').'d';
    };

    $attentionInquiryFlag = static function ($inquiry) use ($today, $inquiryFlag, $attentionOverdueLabel): array {
        $task = $inquiry->currentTask;
        if ($task?->due_date && $task->due_date->lt($today)) {
            return [$attentionOverdueLabel($task->due_date), 'red'];
        }

        return $inquiryFlag($inquiry);
    };

    $attentionJobFlag = static function ($job) use ($today, $jobFlag, $attentionOverdueLabel): array {
        $task = $job->tasks?->first();
        if ($task?->due_date && $task->due_date->lt($today)) {
            return [$attentionOverdueLabel($task->due_date), 'red'];
        }
        if ($task?->due_date && $task->due_date->isSameDay($today)) return ['Due today', 'amber'];
        $taskStatus = mb_strtolower(trim((string) ($task?->status ?? '')));
        if (str_contains($taskStatus, 'block')) return ['Blocked', 'red'];
        if ($task?->needs_attention) return ['Needs attention', 'red'];
        if ($job->delivery_date && $job->delivery_date->lt($today)) {
            return [$attentionOverdueLabel($job->delivery_date), 'red'];
        }
        if ($job->delivery_date && $job->delivery_date->isSameDay($today)) return ['Due today', 'amber'];
        if (!$job->owner_id) return ['Unassigned', 'gray'];
        return $jobFlag($job);
    };

    $attentionViewAllRoute = match ($attentionTab ?? 'all') {
        'inquiries' => route('inquiries.index', ['metric' => 'attention']),
        default => route('jobs.index', ['metric' => 'dashboardAttention']),
    };

    $orderTerminology = static function (?string $value): string {
        return preg_replace_callback('/\bjobs?\b/i', static function (array $match): string {
            return match ($match[0]) {
                'Jobs' => 'Orders', 'jobs' => 'orders', 'JOB' => 'ORDER', 'JOBS' => 'ORDERS',
                default => ctype_upper($match[0][0] ?? '') ? 'Order' : 'order',
            };
        }, (string) $value) ?: (string) $value;
    };

    $flowRows = collect($flowDistribution[$flowTab] ?? []);
    $flowMax = max(1, (int) $flowRows->max('count'));
    $flowRows = $flowRows->map(static function (array $row) use ($flowMax): array {
        $count = (int) ($row['count'] ?? 0);
        $scopeText = trim((string) ($row['scope_text'] ?? ''));

        return [
            'label' => (string) ($row['label'] ?? 'Unassigned'),
            'short_label' => (string) ($row['short_label'] ?? $row['label'] ?? 'Unassigned'),
            'color' => $row['color'] ?? null,
            'count' => $count,
            'width' => $count > 0 ? max(2, (int) round(($count / $flowMax) * 100)) : 0,
            'scope_text' => $scopeText,
            'scope_label' => trim((string) ($row['scope_label'] ?? '')),
            'is_mismatch' => (bool) ($row['is_mismatch'] ?? false),
        ];
    })->values();
    $selectedTaskStatusDistribution = $taskStatusDistribution[$taskStatusTab] ?? ['total' => 0, 'rows' => []];
    $statusRows = collect($selectedTaskStatusDistribution['rows'] ?? [])->values();
    $statusTotal = max(0, (int) ($selectedTaskStatusDistribution['total'] ?? 0));
    $cursor = 0.0;
    $gradientSegments = [];
    foreach ($statusRows as $row) {
        $count = max(0, (int) ($row['count'] ?? 0));
        if ($count <= 0 || $statusTotal <= 0) continue;
        $start = $cursor;
        $cursor += ($count / $statusTotal) * 100;
        $color = (string) ($row['color'] ?? '#64748B');
        $gradientSegments[] = $color.' '.$start.'% '.$cursor.'%';
    }
    if ($cursor < 100) $gradientSegments[] = '#edf2f5 '.$cursor.'% 100%';
    $donutBackground = $statusTotal > 0 ? 'conic-gradient('.implode(',', $gradientSegments).')' : '#edf2f5';
?>

<?php if (isset($component)) { $__componentOriginalcfea599f97a0d6266449c21c198d875e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcfea599f97a0d6266449c21c198d875e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.management-theme','data' => ['class' => 'ft-mgmt-dashboard']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.management-theme'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-mgmt-dashboard']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

    <div class="ft-mgmt-page-head">
        <div>
            <h1>Management Dashboard</h1>
            <p>Live operational overview across inquiries, orders, tasks, clients and product data.</p>
        </div>
        <div class="ft-mgmt-head-actions">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canCreateOrder): ?><a class="ft-mgmt-btn primary" href="<?php echo e(route('jobs.index', ['create' => 1])); ?>" wire:navigate>＋ Create Order</a><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canCreateInquiry): ?><a class="ft-mgmt-btn" href="<?php echo e(route('inquiries.index', ['create' => 1])); ?>" wire:navigate>＋ Create Inquiry</a><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($canCreateClient): ?><a class="ft-mgmt-btn" href="<?php echo e(route('clients.index', ['create' => 1])); ?>" wire:navigate>＋ Add Client</a><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>

    <section class="ft-mgmt-control-bar" aria-label="Dashboard filters">
        <div class="ft-mgmt-range" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'dashboard-range-control'; ?>wire:key="dashboard-range-control" wire:loading.class="is-loading" wire:target="setRange">
            <button type="button" wire:click="setRange(1)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="<?php echo e($rangeDays === 1 ? 'true' : 'false'); ?>" class="<?php echo e($rangeDays === 1 ? 'active' : ''); ?>">Today</button>
            <button type="button" wire:click="setRange(7)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="<?php echo e($rangeDays === 7 ? 'true' : 'false'); ?>" class="<?php echo e($rangeDays === 7 ? 'active' : ''); ?>">7 days</button>
            <button type="button" wire:click="setRange(30)" wire:loading.attr="disabled" wire:target="setRange" aria-pressed="<?php echo e($rangeDays === 30 ? 'true' : 'false'); ?>" class="<?php echo e($rangeDays === 30 ? 'active' : ''); ?>">30 days</button>
        </div>
        <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-mgmt-remote-filter ft-mgmt-client-filter','label' => 'Client','property' => 'clientFilter','type' => 'clients','context' => 'dashboard','action' => 'setDashboardFilter','value' => $clientFilter,'placeholder' => 'All clients','initialOptions' => $dashboardClientFilterOptions,'menuWidth' => 300,'fixedMenu' => true,'wire:key' => 'dashboard-client-filter-'.e($clientFilter ?: 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-mgmt-remote-filter ft-mgmt-client-filter','label' => 'Client','property' => 'clientFilter','type' => 'clients','context' => 'dashboard','action' => 'setDashboardFilter','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clientFilter),'placeholder' => 'All clients','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dashboardClientFilterOptions),'menu-width' => 300,'fixed-menu' => true,'wire:key' => 'dashboard-client-filter-'.e($clientFilter ?: 'all').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $attributes = $__attributesOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__attributesOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $component = $__componentOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__componentOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
        <?php if (isset($component)) { $__componentOriginal655167214ff7da69eb027810b956fa88 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal655167214ff7da69eb027810b956fa88 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.search-select','data' => ['class' => 'ft-mgmt-remote-filter ft-mgmt-team-filter','label' => 'Team','property' => 'teamFilter','type' => 'departments','context' => 'dashboard','action' => 'setDashboardFilter','value' => $teamFilter,'placeholder' => 'All teams','initialOptions' => $dashboardTeamFilterOptions,'menuWidth' => 300,'fixedMenu' => true,'wire:key' => 'dashboard-team-filter-'.e($teamFilter ?: 'all').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.search-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'ft-mgmt-remote-filter ft-mgmt-team-filter','label' => 'Team','property' => 'teamFilter','type' => 'departments','context' => 'dashboard','action' => 'setDashboardFilter','value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($teamFilter),'placeholder' => 'All teams','initial-options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dashboardTeamFilterOptions),'menu-width' => 300,'fixed-menu' => true,'wire:key' => 'dashboard-team-filter-'.e($teamFilter ?: 'all').'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $attributes = $__attributesOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__attributesOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal655167214ff7da69eb027810b956fa88)): ?>
<?php $component = $__componentOriginal655167214ff7da69eb027810b956fa88; ?>
<?php unset($__componentOriginal655167214ff7da69eb027810b956fa88); ?>
<?php endif; ?>
        <input class="ft-mgmt-search" wire:model.live.debounce.300ms="search" type="search" placeholder="Search orders, inquiries or tasks" aria-label="Search dashboard">
    </section>

    <?php if (isset($component)) { $__componentOriginalee5bb7364c37061cbe535f4c41f9060f = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalee5bb7364c37061cbe535f4c41f9060f = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.orders.workflow-stage-overview','data' => ['stages' => $orderStages,'mode' => 'navigate','showHeader' => false,'navigationQuery' => $orderStageNavigationQuery]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('orders.workflow-stage-overview'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['stages' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderStages),'mode' => 'navigate','show-header' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false),'navigation-query' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($orderStageNavigationQuery)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalee5bb7364c37061cbe535f4c41f9060f)): ?>
<?php $attributes = $__attributesOriginalee5bb7364c37061cbe535f4c41f9060f; ?>
<?php unset($__attributesOriginalee5bb7364c37061cbe535f4c41f9060f); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalee5bb7364c37061cbe535f4c41f9060f)): ?>
<?php $component = $__componentOriginalee5bb7364c37061cbe535f4c41f9060f; ?>
<?php unset($__componentOriginalee5bb7364c37061cbe535f4c41f9060f); ?>
<?php endif; ?>



    <section class="ft-mgmt-panel ft-mgmt-panel-spaced">
        <div class="ft-mgmt-panel-head">
            <div><h2>Priority work</h2><p>Top urgent Orders, Inquiries and Tasks ranked by attention, due date and priority</p></div>
            <div class="ft-mgmt-tabs">
                <button type="button" wire:click="setPriorityTab('orders')" class="ft-mgmt-tab <?php echo e($priorityTab === 'orders' ? 'active' : ''); ?>">Orders</button>
                <button type="button" wire:click="setPriorityTab('inquiries')" class="ft-mgmt-tab <?php echo e($priorityTab === 'inquiries' ? 'active' : ''); ?>">Inquiries</button>
                <button type="button" wire:click="setPriorityTab('tasks')" class="ft-mgmt-tab <?php echo e($priorityTab === 'tasks' ? 'active' : ''); ?>">Tasks</button>
            </div>
        </div>
        <div class="ft-mgmt-table-wrap">
            <table class="ft-mgmt-table">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($priorityTab === 'orders'): ?>
                    <thead><tr><th>Order</th><th>Client</th><th>Stage</th><th>Progress</th><th>Attention</th><th>Owner</th><th>Delivery</th><th></th></tr></thead>
                    <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $priorityJobs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $job): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <?php
                                [$flagLabel, $flagTone] = $jobFlag($job);
                            ?>
                            <tr <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'mgmt-priority-job-'.e($job->id).''; ?>wire:key="mgmt-priority-job-<?php echo e($job->id); ?>">
                                <td><a class="ft-mgmt-primary-text" href="<?php echo e(route('jobs.index', ['open' => $job->id])); ?>" wire:navigate><?php echo e($job->displayOrderNumber()); ?></a><div class="ft-mgmt-sub"><?php echo e($job->title); ?></div></td>
                                <td><?php echo e($job->client?->name ?? '—'); ?></td><td><?php if (isset($component)) { $__componentOriginal9414ddaaf6095649bba169634abf8f57 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9414ddaaf6095649bba169634abf8f57 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.phase-label','data' => ['phase' => $job->phase,'short' => true,'fallback' => 'Unassigned','class' => 'ft-mgmt-badge']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.phase-label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['phase' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($job->phase),'short' => true,'fallback' => 'Unassigned','class' => 'ft-mgmt-badge']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9414ddaaf6095649bba169634abf8f57)): ?>
<?php $attributes = $__attributesOriginal9414ddaaf6095649bba169634abf8f57; ?>
<?php unset($__attributesOriginal9414ddaaf6095649bba169634abf8f57); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9414ddaaf6095649bba169634abf8f57)): ?>
<?php $component = $__componentOriginal9414ddaaf6095649bba169634abf8f57; ?>
<?php unset($__componentOriginal9414ddaaf6095649bba169634abf8f57); ?>
<?php endif; ?></td>
                                <td><div class="ft-mgmt-progress-cell"><div class="ft-mgmt-track"><span class="ft-mgmt-fill" style="width:<?php echo e(min(100, max(0, (int) $job->progress))); ?>%"></span></div><b><?php echo e((int) $job->progress); ?>%</b></div></td>
                                <td><span class="ft-mgmt-badge <?php echo e($flagTone); ?>"><?php echo e($flagLabel); ?></span></td>
                                <td><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($job->owner): ?><span class="ft-mgmt-person"><?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['user' => $job->owner,'name' => $job->owner->name,'size' => 27]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($job->owner),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($job->owner->name),'size' => 27]); ?>
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
<?php endif; ?><?php echo e($job->owner->name); ?></span><?php else: ?> Unassigned <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></td>
                                <td><?php echo e($job->delivery_date?->format('M j') ?? '—'); ?></td><td><a class="ft-mgmt-tiny-action" href="<?php echo e(route('jobs.index', ['open' => $job->id])); ?>" wire:navigate>View</a></td>
                            </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?><tr><td colspan="8" class="ft-mgmt-empty">No matching orders found.</td></tr><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                <?php elseif($priorityTab === 'inquiries'): ?>
                    <thead><tr><th>Inquiry</th><th>Client</th><th>Current task</th><th>Status</th><th>Flag</th><th>Owner</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $priorityInquiries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $inquiry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <?php
                                [$flagLabel, $flagTone] = $inquiryFlag($inquiry);
                                $statusColor = $inquiryService->inquiryStatusColor($inquiry->status ?: 'To do', (string) ($inquiry->currentTask?->status ?: ''));
                            ?>
                            <tr <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'mgmt-priority-inquiry-'.e($inquiry->id).''; ?>wire:key="mgmt-priority-inquiry-<?php echo e($inquiry->id); ?>">
                                <td><a class="ft-mgmt-primary-text" href="<?php echo e(route('inquiries.index', ['open' => $inquiry->id])); ?>" wire:navigate><?php echo e($inquiry->inquiry_number); ?></a><div class="ft-mgmt-sub"><?php echo e($inquiry->subject); ?></div></td>
                                <td><?php echo e($inquiry->client?->name ?? '—'); ?></td><td><?php echo e($inquiry->currentTask?->title ?? 'No current task'); ?></td>
                                <td><span class="ft-mgmt-badge <?php echo e($badgeTone($inquiry->status)); ?>"><?php echo e($inquiry->status ?: 'To do'); ?></span></td><td><span class="ft-mgmt-badge <?php echo e($flagTone); ?>"><?php echo e($flagLabel); ?></span></td>
                                <td><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($inquiry->owner): ?><span class="ft-mgmt-person"><?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['user' => $inquiry->owner,'name' => $inquiry->owner->name,'size' => 27]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($inquiry->owner),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($inquiry->owner->name),'size' => 27]); ?>
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
<?php endif; ?><?php echo e($inquiry->owner->name); ?></span><?php else: ?> Unassigned <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></td>
                                <td><?php echo e($inquiry->currentTask?->due_date?->format('M j') ?? '—'); ?></td><td><a class="ft-mgmt-tiny-action" href="<?php echo e(route('inquiries.index', ['open' => $inquiry->id])); ?>" wire:navigate>View</a></td>
                            </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?><tr><td colspan="8" class="ft-mgmt-empty">No matching inquiries found.</td></tr><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                <?php else: ?>
                    <thead><tr><th>Task</th><th>Order</th><th>Phase</th><th>Status</th><th>Attention</th><th>Assignee</th><th>Due</th><th></th></tr></thead>
                    <tbody>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $priorityTasks; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $task): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <?php
                            [$flagLabel, $flagTone] = $taskFlag($task);
                        ?>
                            <tr <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'mgmt-priority-task-'.e($task->id).''; ?>wire:key="mgmt-priority-task-<?php echo e($task->id); ?>">
                                <td><a class="ft-mgmt-primary-text" href="<?php echo e(route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id])); ?>" wire:navigate><?php echo e($task->title); ?></a><div class="ft-mgmt-sub"><?php echo e($task->task_number); ?></div></td>
                                <td><?php echo e($task->job?->displayOrderNumber() ?? '—'); ?></td><td><?php if (isset($component)) { $__componentOriginal9414ddaaf6095649bba169634abf8f57 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9414ddaaf6095649bba169634abf8f57 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.phase-label','data' => ['phase' => $task->phase,'short' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.phase-label'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['phase' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($task->phase),'short' => true]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9414ddaaf6095649bba169634abf8f57)): ?>
<?php $attributes = $__attributesOriginal9414ddaaf6095649bba169634abf8f57; ?>
<?php unset($__attributesOriginal9414ddaaf6095649bba169634abf8f57); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9414ddaaf6095649bba169634abf8f57)): ?>
<?php $component = $__componentOriginal9414ddaaf6095649bba169634abf8f57; ?>
<?php unset($__componentOriginal9414ddaaf6095649bba169634abf8f57); ?>
<?php endif; ?></td>
                                <td><span class="ft-mgmt-badge <?php echo e($badgeTone($task->status)); ?>"><?php echo e($task->status); ?></span></td><td><span class="ft-mgmt-badge <?php echo e($flagTone); ?>"><?php echo e($flagLabel); ?></span></td>
                                <td><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($task->assignee): ?><span class="ft-mgmt-person"><?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['user' => $task->assignee,'name' => $task->assignee->name,'size' => 27]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($task->assignee),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($task->assignee->name),'size' => 27]); ?>
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
<?php endif; ?><?php echo e($task->assignee->name); ?></span><?php else: ?> Unassigned <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></td>
                                <td><?php echo e($task->due_date?->format('M j') ?? '—'); ?></td><td><a class="ft-mgmt-tiny-action" href="<?php echo e(route('jobs.index', ['open' => $task->flow_job_id, 'task' => $task->id])); ?>" wire:navigate>View</a></td>
                            </tr>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?><tr><td colspan="8" class="ft-mgmt-empty">No matching tasks found.</td></tr><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </tbody>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </table>
        </div>
        <div class="ft-mgmt-priority-pagination" aria-label="Priority work pagination">
            <span class="ft-mgmt-priority-page-status">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(($priorityPagination['total'] ?? 0) > 0): ?>
                    <?php echo e($priorityPagination['from']); ?>–<?php echo e($priorityPagination['to']); ?> of <?php echo e($priorityPagination['total']); ?>

                <?php else: ?>
                    0 items
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </span>
            <button
                type="button"
                class="ft-mgmt-priority-page-btn"
                wire:click="previousPriorityPage"
                wire:loading.attr="disabled"
                wire:target="previousPriorityPage,nextPriorityPage"
                <?php if(!($priorityPagination['hasPrevious'] ?? false)): echo 'disabled'; endif; ?>
                aria-label="Previous priority work page"
                title="Previous page"
            >←</button>
            <button
                type="button"
                class="ft-mgmt-priority-page-btn"
                wire:click="nextPriorityPage"
                wire:loading.attr="disabled"
                wire:target="previousPriorityPage,nextPriorityPage"
                <?php if(!($priorityPagination['hasNext'] ?? false)): echo 'disabled'; endif; ?>
                aria-label="Next priority work page"
                title="Next page"
            >→</button>
        </div>
    </section>

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <article class="ft-mgmt-panel ft-mgmt-attention-prototype ft-mgmt-attention-compact ft-mgmt-dashboard-half-panel">
            <div class="ft-mgmt-panel-head">
                <div><h2>Needs attention</h2><p>Orders and Inquiries ranked by urgency and impact</p></div>
                <a class="ft-mgmt-link" href="<?php echo e($attentionViewAllRoute); ?>" wire:navigate>View all</a>
            </div>
            <div class="ft-mgmt-attention-tabs" role="tablist" aria-label="Needs attention type">
                <button type="button" wire:click="setAttentionTab('all')" class="<?php echo e($attentionTab === 'all' ? 'active' : ''); ?>">All <?php echo e($attentionTotalCount); ?></button>
                <button type="button" wire:click="setAttentionTab('orders')" class="<?php echo e($attentionTab === 'orders' ? 'active' : ''); ?>">Orders <?php echo e($attentionOrderCount); ?></button>
                <button type="button" wire:click="setAttentionTab('inquiries')" class="<?php echo e($attentionTab === 'inquiries' ? 'active' : ''); ?>">Inquiries <?php echo e($attentionInquiryCount); ?></button>
            </div>
            <div class="ft-mgmt-attention-list">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $attentionItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $attentionItem): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <?php
                        $kind = $attentionItem['kind'];
                        $record = $attentionItem['record'];
                        $isOrder = $kind === 'orders';
                        [$flagLabel, $flagTone] = $isOrder ? $attentionJobFlag($record) : $attentionInquiryFlag($record);
                        $reference = $isOrder ? $record->displayOrderNumber() : $record->inquiry_number;
                        $headline = trim((string) ($isOrder ? ($record->tasks?->first()?->title ?: $record->title) : ($record->currentTask?->title ?: $record->subject)));
                        $ownerName = $isOrder ? ($record->owner?->name ?? 'Unassigned') : ($record->owner?->name ?? 'Unassigned');
                        $reason = trim((string) ($isOrder
                            ? ($record->attention_reason ?: $record->tasks?->first()?->attention_reason ?: $record->flaggedTasks?->first()?->attention_reason ?: 'Attention required')
                            : ($record->currentTask?->attention_reason ?: ($record->needs_attention ? 'Attention required' : $record->currentTask?->status))));
                        $rowRoute = $isOrder
                            ? route('jobs.index', ['open' => $record->id])
                            : route('inquiries.index', ['open' => $record->id]);
                    ?>
                    <a class="ft-mgmt-attention" href="<?php echo e($rowRoute); ?>" wire:navigate <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'mgmt-attention-'.e($kind).'-'.e($record->id).''; ?>wire:key="mgmt-attention-<?php echo e($kind); ?>-<?php echo e($record->id); ?>">
                        <span class="ft-mgmt-severity <?php echo e($flagTone); ?>"></span>
                        <span class="ft-mgmt-attention-type-icon <?php echo e($isOrder ? 'order' : 'inquiry'); ?>" aria-hidden="true">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($isOrder): ?>
                                <svg viewBox="0 0 24 24"><path d="M3 4h2l2.2 10.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L20 8H7"/><circle cx="10" cy="20" r="1.3"/><circle cx="17" cy="20" r="1.3"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24"><path d="M20 15a4 4 0 0 1-4 4H9l-5 3 1.4-4.2A7 7 0 1 1 20 15Z"/></svg>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </span>
                        <span class="ft-mgmt-attention-kind <?php echo e($isOrder ? 'order' : 'inquiry'); ?>"><?php echo e($isOrder ? 'Order' : 'Inquiry'); ?></span>
                        <span class="ft-mgmt-attention-copy">
                            <strong><?php echo e($reference); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($headline !== ''): ?> · <?php echo e($headline); ?><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></strong>
                            <small><?php echo e($reason !== '' ? $reason : 'Requires attention'); ?> · Owner <?php echo e($ownerName); ?></small>
                        </span>
                        <span class="ft-mgmt-badge <?php echo e($flagTone); ?>"><?php echo e($flagLabel); ?></span>
                        <span class="ft-mgmt-attention-arrow" aria-hidden="true">›</span>
                    </a>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    <div class="ft-mgmt-empty">No attention items match the current filters.</div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </article>

    <?php
$__split = function ($name, $params = []) {
    return [$name, $params];
};
[$__name, $__params] = $__split('dashboard.tagged-comments', ['range-days' => $rangeDays,'client-filter' => $clientFilter,'team-filter' => $teamFilter,'search' => $search,'lazy' => true]);

$__keyOuter = $__key ?? null;

$__key = null;
$__componentSlots = [];

$__key ??= \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::generateKey('lw-3691867577-0', $__key);

$__html = app('livewire')->mount($__name, $__params, $__key, $__componentSlots);

echo $__html;

unset($__html);
unset($__key);
$__key = $__keyOuter;
unset($__keyOuter);
unset($__name);
unset($__params);
unset($__componentSlots);
unset($__split);
?>
    </section>

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <article class="ft-mgmt-panel ft-mgmt-flow-prototype">
            <div class="ft-mgmt-panel-head">
                <div><h2>Work moving through FlowTrack</h2><p>Active records grouped by configured workflow phase. Client-specific phase differences are labelled.</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setFlowTab('orders')" class="ft-mgmt-tab <?php echo e($flowTab === 'orders' ? 'active' : ''); ?>">Orders</button>
                    <button type="button" wire:click="setFlowTab('inquiries')" class="ft-mgmt-tab <?php echo e($flowTab === 'inquiries' ? 'active' : ''); ?>">Inquiries</button>
                </div>
            </div>
            <div class="ft-mgmt-panel-body">
                <div class="ft-mgmt-flow-bars">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $flowRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <div class="ft-mgmt-flow-row">
                            <div class="ft-mgmt-flow-label-wrap" title="<?php echo e($row['label']); ?><?php echo e($row['scope_text'] !== '' ? ' — '.$row['scope_text'] : ''); ?>">
                                <span class="ft-mgmt-flow-label"><?php echo e($row['short_label'] ?? $row['label']); ?></span>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($row['is_mismatch'] && $row['scope_text'] !== ''): ?>
                                    <span class="ft-mgmt-flow-scope"><?php echo e($row['scope_text']); ?></span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div class="ft-mgmt-track">
                                <span class="ft-mgmt-fill ft-phase-fill <?php echo e((int) $row['count'] === 0 ? 'is-empty' : ''); ?>" style="<?php echo e(\App\Support\MasterColor::style($row['color'] ?? null)); ?>width:<?php echo e($row['width']); ?>%"></span>
                            </div>
                            <span class="ft-mgmt-flow-value"><?php echo e($row['count']); ?></span>
                        </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        <div class="ft-mgmt-empty">No active workflow data.</div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        </article>

        <article class="ft-mgmt-panel">
            <div class="ft-mgmt-panel-head">
                <div><h2>Task status distribution</h2><p>Current <?php echo e($taskStatusTab === 'orders' ? 'Order' : 'Inquiry'); ?> task status from Master Data</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setTaskStatusTab('orders')" class="ft-mgmt-tab <?php echo e($taskStatusTab === 'orders' ? 'active' : ''); ?>">Orders</button>
                    <button type="button" wire:click="setTaskStatusTab('inquiries')" class="ft-mgmt-tab <?php echo e($taskStatusTab === 'inquiries' ? 'active' : ''); ?>">Inquiries</button>
                </div>
            </div>
            <div class="ft-mgmt-panel-body ft-mgmt-status-layout">
                <div class="ft-mgmt-donut" style="background:<?php echo e($donutBackground); ?>"><div class="ft-mgmt-donut-center"><strong><?php echo e($statusTotal); ?></strong><span>tasks</span></div></div>
                <div class="ft-mgmt-legend">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $statusRows; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <a href="<?php echo e(route('my-work', ['source' => $taskStatusTab, 'status' => $row['label']])); ?>" wire:navigate title="View My Tasks filtered by <?php echo e($row['label']); ?>"><span class="dot" style="background:<?php echo e($row['color']); ?>"></span><?php echo e($row['label']); ?></a><b><?php echo e($row['count']); ?></b>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        <span class="ft-mgmt-sub">No active task statuses.</span><b>0</b>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>
        </article>
    </section>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->canAccess('reports.view')): ?>
    <?php
        $teamReportParams = array_filter([
            // Keep "View all" aligned with the dashboard's global period. The
            // full report still supports its own reporting-period controls after
            // the user leaves the dashboard.
            'period' => 'custom',
            'from' => $teamReportingPeriod['from'] ?? null,
            'to' => $teamReportingPeriod['to'] ?? null,
            'client' => $clientFilter,
            'department' => $teamFilter,
            'q' => $search,
        ], static fn ($value) => $value !== null && $value !== '');
    ?>
    <section class="ft-mgmt-panel ft-mgmt-team-panel ft-mgmt-panel-spaced">
        <div class="ft-mgmt-panel-head ft-mgmt-team-panel-head">
            <div>
                <h2>Team performance &amp; workload</h2>
                <p>Top 4 assignees from actual Inquiry and Order task records · <?php echo e($teamReportingPeriod['label'] ?? 'Last 7 days'); ?>.</p>
            </div>
            <a class="ft-mgmt-btn ft-mgmt-team-view-all" href="<?php echo e(route('team-performance.report', $teamReportParams)); ?>" wire:navigate>View all</a>
        </div>
        <div class="ft-mgmt-panel-body">
            <div class="ft-mgmt-team-grid">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $assigneePerformance; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $person): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <?php if (isset($component)) { $__componentOriginal09bad63cc66db31fb9cc464e04232869 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal09bad63cc66db31fb9cc464e04232869 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.dashboard.team-performance-card','data' => ['person' => $person,'wire:key' => 'mgmt-person-'.e($person->id).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('dashboard.team-performance-card'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['person' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($person),'wire:key' => 'mgmt-person-'.e($person->id).'']); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal09bad63cc66db31fb9cc464e04232869)): ?>
<?php $attributes = $__attributesOriginal09bad63cc66db31fb9cc464e04232869; ?>
<?php unset($__attributesOriginal09bad63cc66db31fb9cc464e04232869); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal09bad63cc66db31fb9cc464e04232869)): ?>
<?php $component = $__componentOriginal09bad63cc66db31fb9cc464e04232869; ?>
<?php unset($__componentOriginal09bad63cc66db31fb9cc464e04232869); ?>
<?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    <div class="ft-mgmt-empty">No team workload matches the current dashboard filters and period.</div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    </section>

    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <section class="ft-mgmt-grid ft-mgmt-dashboard-pair-grid">
        <article class="ft-mgmt-panel ft-mgmt-client-panel">
            <div class="ft-mgmt-panel-head"><div><h2>Client portfolio</h2><p>Activity and completion in the selected dashboard period</p></div><a class="ft-mgmt-link" href="<?php echo e(route('clients.index')); ?>" wire:navigate>All clients</a></div>
            <div class="ft-mgmt-panel-body ft-mgmt-client-portfolio-body">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($clientPortfolio->isNotEmpty()): ?>
                    <div class="ft-mgmt-client-head" aria-hidden="true">
                        <span>Client</span>
                        <span>Inquiries</span>
                        <span>Orders</span>
                        <span>Created vs completed</span>
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $clientPortfolio; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $portfolioClient): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <?php
                        $inquiries = (int) ($portfolioClient->inquiries_count ?? 0);
                        $orders = (int) ($portfolioClient->orders_count ?? 0);
                        $created = (int) ($portfolioClient->total_records_count ?? ($inquiries + $orders));
                        $completed = (int) ($portfolioClient->completed_records_count ?? 0);
                        $completion = $created > 0 ? min(100, max(0, (int) round(($completed / $created) * 100))) : 0;
                        $attention = (int) ($portfolioClient->attention_items_count ?? 0);
                    ?>
                    <div class="ft-mgmt-client-row" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'mgmt-client-'.e($portfolioClient->id).''; ?>wire:key="mgmt-client-<?php echo e($portfolioClient->id); ?>">
                        <div class="ft-mgmt-client-name">
                            <span class="ft-mgmt-client-logo"><?php if (isset($component)) { $__componentOriginalb7fdbb44e2f28c5f803966058155c072 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb7fdbb44e2f28c5f803966058155c072 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.client-logo','data' => ['client' => $portfolioClient,'name' => $portfolioClient->name,'size' => 38]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.client-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['client' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($portfolioClient),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($portfolioClient->name),'size' => 38]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb7fdbb44e2f28c5f803966058155c072)): ?>
<?php $attributes = $__attributesOriginalb7fdbb44e2f28c5f803966058155c072; ?>
<?php unset($__attributesOriginalb7fdbb44e2f28c5f803966058155c072); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb7fdbb44e2f28c5f803966058155c072)): ?>
<?php $component = $__componentOriginalb7fdbb44e2f28c5f803966058155c072; ?>
<?php unset($__componentOriginalb7fdbb44e2f28c5f803966058155c072); ?>
<?php endif; ?></span>
                            <div class="ft-mgmt-client-copy">
                                <strong><?php echo e($portfolioClient->name); ?></strong>
                                <span><?php echo e($attention); ?> attention item<?php echo e($attention === 1 ? '' : 's'); ?></span>
                            </div>
                        </div>
                        <b class="ft-mgmt-client-number"><?php echo e($inquiries); ?></b>
                        <b class="ft-mgmt-client-number"><?php echo e($orders); ?></b>
                        <div class="ft-mgmt-client-completion">
                            <div class="ft-mgmt-client-progress"><span style="width:<?php echo e($completion); ?>%"></span></div>
                            <div><?php echo e($completed); ?> of <?php echo e($created); ?> completed · <?php echo e($completion); ?>%</div>
                        </div>
                    </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    <div class="ft-mgmt-empty">No client portfolio data matches the current filters.</div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </article>

        <article class="ft-mgmt-panel ft-mgmt-catalogue-side ft-mgmt-catalogue-prototype ft-mgmt-dashboard-half-panel">
            <div class="ft-mgmt-catalogue-head">
                <div class="ft-mgmt-catalogue-title">
                    <h2>Catalogue readiness</h2>
                    <p>Product data, classification, availability and document coverage</p>
                </div>
                <div class="ft-mgmt-catalogue-actions">
                    <span class="ft-mgmt-catalogue-ready"><?php echo e((int) ($catalogueReadiness['readyPercent'] ?? 0)); ?>% ready</span>
                    <a class="ft-mgmt-link" href="<?php echo e(route('master-data', ['group' => 'product'])); ?>" wire:navigate>Open catalogue</a>
                </div>
            </div>

            <div class="ft-mgmt-catalogue-body">
                <div class="ft-mgmt-catalogue-summary">
                    <span><strong><?php echo e(number_format((int) ($catalogueReadiness['activeProducts'] ?? 0))); ?></strong> products</span>
                    <i aria-hidden="true"></i>
                    <span><?php echo e(number_format((int) ($catalogueReadiness['mainCategories'] ?? 0))); ?> main categories</span>
                    <i aria-hidden="true"></i>
                    <span><?php echo e(number_format((int) ($catalogueReadiness['activeSuppliers'] ?? 0))); ?> active <?php echo e((int) ($catalogueReadiness['activeSuppliers'] ?? 0) === 1 ? 'supplier' : 'suppliers'); ?></span>
                </div>

                <div class="ft-mgmt-catalogue-rows">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $catalogueReadiness['rows'] ?? []; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $row): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <?php
                            $tone = in_array(($row['tone'] ?? ''), ['amber', 'green', 'red', 'blue'], true)
                                ? $row['tone']
                                : 'green';
                        ?>
                        <div class="ft-mgmt-catalogue-row">
                            <div class="ft-mgmt-catalogue-label">
                                <span class="ft-mgmt-catalogue-dot <?php echo e($tone); ?>" aria-hidden="true"></span>
                                <strong><?php echo e($row['label']); ?></strong>
                            </div>
                            <div class="ft-mgmt-catalogue-track" aria-label="<?php echo e($row['label']); ?> <?php echo e((int) $row['value']); ?> percent">
                                <span class="ft-mgmt-catalogue-fill <?php echo e($tone); ?>" style="width:<?php echo e(max(0, min(100, (int) $row['value']))); ?>%"></span>
                            </div>
                            <div class="ft-mgmt-catalogue-value">
                                <strong><?php echo e((int) $row['value']); ?>%</strong>
                                <span><?php echo e($row['detail'] ?? ''); ?></span>
                            </div>
                        </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </div>
            </div>
        </article>
    </section>

    <article class="ft-mgmt-panel ft-mgmt-recent-prototype">
            <div class="ft-mgmt-panel-head">
                <div><h2>Recent activity</h2><p>Latest changes from Orders, Inquiries and Tasks</p></div>
                <div class="ft-mgmt-tabs">
                    <button type="button" wire:click="setActivityTab('all')" class="ft-mgmt-tab <?php echo e($activityTab === 'all' ? 'active' : ''); ?>">All</button>
                    <button type="button" wire:click="setActivityTab('orders')" class="ft-mgmt-tab <?php echo e($activityTab === 'orders' ? 'active' : ''); ?>">Orders</button>
                    <button type="button" wire:click="setActivityTab('inquiries')" class="ft-mgmt-tab <?php echo e($activityTab === 'inquiries' ? 'active' : ''); ?>">Inquiries</button>
                    <button type="button" wire:click="setActivityTab('tasks')" class="ft-mgmt-tab <?php echo e($activityTab === 'tasks' ? 'active' : ''); ?>">Tasks</button>
                </div>
            </div>
            <div class="ft-mgmt-activity-list">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $recentActivity; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $activity): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <?php
                        $activityDetail = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $orderTerminology($activity->dashboard_detail), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
                        $activityKind = (string) ($activity->dashboard_kind ?? 'orders');
                        $activityIsInquiry = $activity->subject_type === \App\Models\Inquiry::class;
                        $activityRoute = ($activityKind === 'inquiries' || $activityIsInquiry)
                            ? route('inquiries.index', ['open' => (int) ($activity->dashboard_parent_id ?? 0)])
                            : route('jobs.index', array_filter([
                                'open' => (int) ($activity->dashboard_parent_id ?? 0),
                                'task' => $activityKind === 'tasks' && (int) ($activity->dashboard_task_id ?? 0) > 0 ? (int) $activity->dashboard_task_id : null,
                            ]));
                    ?>
                    <a class="ft-mgmt-activity" href="<?php echo e($activityRoute); ?>" wire:navigate <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'mgmt-activity-'.e($activity->id).''; ?>wire:key="mgmt-activity-<?php echo e($activity->id); ?>">
                        <?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['user' => $activity->user,'name' => $activity->user?->name ?? 'FlowTrack','size' => 38]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($activity->user),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($activity->user?->name ?? 'FlowTrack'),'size' => 38]); ?>
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
                        <span class="ft-mgmt-activity-copy">
                            <strong><?php echo e($orderTerminology($activity->dashboard_title)); ?></strong>
                            <p><?php echo e($activityDetail !== '' ? $activityDetail : 'Record updated'); ?></p>
                        </span>
                        <time><?php echo e($activity->created_at?->diffForHumans(short: true)); ?></time>
                    </a>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?><div class="ft-mgmt-empty">No Order, Inquiry or Task changes match the selected period or filters.</div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </article>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcfea599f97a0d6266449c21c198d875e)): ?>
<?php $attributes = $__attributesOriginalcfea599f97a0d6266449c21c198d875e; ?>
<?php unset($__attributesOriginalcfea599f97a0d6266449c21c198d875e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcfea599f97a0d6266449c21c198d875e)): ?>
<?php $component = $__componentOriginalcfea599f97a0d6266449c21c198d875e; ?>
<?php unset($__componentOriginalcfea599f97a0d6266449c21c198d875e); ?>
<?php endif; ?>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/dashboard/index.blade.php ENDPATH**/ ?>