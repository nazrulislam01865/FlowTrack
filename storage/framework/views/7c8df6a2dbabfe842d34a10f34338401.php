<div class="ft-access-admin">
    <div class="ft-access-head">
        <div>
            <h1><?php echo e($tab === 'branding' ? 'System Branding' : ($tab === 'settings' ? 'System Settings' : 'Access Roles & Permissions')); ?></h1>
            <p><?php echo e($tab === 'branding' ? 'Manage the system name, logo, favicon, application footer and supplier portal footer.' : ($tab === 'settings' ? 'Configure workspace-wide operational settings used throughout FlowTrack.' : 'Control who can view, create, edit, assign, delete, link, export or manage every FlowTrack module.')); ?></p>
        </div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!in_array($tab, ['branding','settings'], true)): ?>
            <div class="ft-access-actions"><button class="ghost" wire:click="setTab('audit')">Audit Log</button><button class="primary" wire:click="openRole">＋ New Role</button></div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <div class="ft-access-tabs">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = ['dashboard'=>'Access Dashboard','roles'=>'Roles & Policies','matrix'=>'Permission Matrix','users'=>'Users & Assignments','audit'=>'Audit Log','security'=>'Security Settings','settings'=>'Settings','branding'=>'Branding']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key=>$label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
            <button class="<?php echo e($tab===$key?'active':''); ?>" wire:click="setTab('<?php echo e($key); ?>')"><?php echo e($label); ?></button>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tab==='dashboard'): ?>
        <div class="ft-access-metrics">
            <div class="card"><span>Active users</span><b><?php echo e($summary['users']); ?></b><small>Current workspace members</small></div>
            <div class="card"><span>Active roles</span><b><?php echo e($summary['roles']); ?></b><small>Reusable permission profiles</small></div>
            <div class="card"><span>Access changes</span><b><?php echo e($summary['access_changes']); ?></b><small>Last 30 days</small></div>
            <div class="card"><span>Notification rules</span><b><?php echo e($summary['rules']); ?></b><small>Active system rules</small></div>
        </div>
        <div class="ft-access-grid-2">
            <section class="card ft-access-panel"><div class="section-head"><div><h3>Role coverage</h3><div class="small muted">Module permissions always control actions. Operational records also use record scope; shared workspace reference data does not.</div></div><button class="link-btn" wire:click="setTab('roles')">Manage roles</button></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <div class="ft-role-line"><div><b><?php echo e($role->name); ?></b><small><?php echo e((int) ($role->users_count ?? 0)); ?> users · <?php echo e(str_replace('_',' ',$role->default_scope)); ?></small></div><span class="badge <?php echo e($role->is_active?'b-green':'b-gray'); ?>"><?php echo e($role->is_active?'Active':'Inactive'); ?></span></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            </section>
            <section class="card ft-access-panel"><div class="section-head"><div><h3>Enforcement model</h3><div class="small muted">The same rules are applied by routes, queries and update actions.</div></div></div>
                <div class="ft-control-note"><b>1. Module permission</b><span>The role must allow the requested action.</span></div>
                <div class="ft-control-note"><b>2. Record scope</b><span>Assigned users see scoped operational Jobs, Tasks, Inquiries and Documents. Clients and setup/reference data are shared workspace-wide once the relevant action is granted.</span></div>
                <div class="ft-control-note"><b>3. Record ownership</b><span>Edit own allows task assignees or Job owners/coordinators to update only their records. Job workflow/status changes are stricter: only the assigned Job owner (or Admin/Super Admin) can transition a Job.</span></div>
                <div class="ft-control-note"><b>4. Audit trail</b><span>Role, scope and user assignment changes are recorded with actor and time.</span></div>
            </section>
        </div>
    <?php elseif($tab==='roles'): ?>
        <div class="ft-role-grid">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                <article class="card ft-role-card <?php echo e($selectedRoleId===$role->id?'selected':''); ?>">
                    <div class="ft-role-card-top"><div class="ft-role-symbol"><?php echo e(collect(explode(' ',$role->name))->map(fn($w)=>strtoupper(substr($w,0,1)))->take(2)->implode('')); ?></div><span class="badge <?php echo e($role->is_active?'b-green':'b-gray'); ?>"><?php echo e($role->is_active?'Active':'Inactive'); ?></span></div>
                    <h3><?php echo e($role->name); ?></h3><div class="small muted"><?php echo e($role->code ?: strtoupper($role->slug)); ?></div>
                    <p><?php echo e($role->description ?: 'Reusable FlowTrack role with module and action permissions.'); ?></p>
                    <div class="ft-role-meta"><span><?php echo e(max((int) ($role->users_count ?? 0), (int) ($role->primary_users_count ?? 0), (int) ($role->memberships_count ?? 0))); ?> users</span><span><?php echo e(str_replace('_',' ',$role->default_scope)); ?></span></div>
                    <div class="ft-role-buttons">
                        <button class="ghost" wire:click="openRole(<?php echo e($role->id); ?>)" <?php if(in_array($role->slug,['super-admin','admin','administrator'],true)): echo 'disabled'; endif; ?>>Details</button>
                        <button class="secondary" wire:click="selectRole(<?php echo e($role->id); ?>)">Permissions</button>
                        <button
                            type="button"
                            class="danger-btn ft-role-delete-btn"
                            wire:click="deleteRole(<?php echo e($role->id); ?>)"
                            wire:loading.attr="disabled"
                            wire:target="deleteRole(<?php echo e($role->id); ?>)"
                            wire:confirm="Permanently delete <?php echo e(addslashes($role->name)); ?>? This role will be deleted from FlowTrack and removed from <?php echo e(max((int) ($role->users_count ?? 0), (int) ($role->primary_users_count ?? 0), (int) ($role->memberships_count ?? 0))); ?> assigned user(s). Users are NOT deleted. If a user has another role, that role stays assigned. This cannot be undone."
                        >Delete</button>
                    </div>
                </article>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
        </div>
    <?php elseif($tab==='matrix'): ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($selectedRole): ?>
            <div
                class="ft-matrix-editor"
                x-data="{ pendingSaves: 0, saveState: '', saveTimer: null }"
                x-on:matrix-save-start.window="
                    pendingSaves++;
                    saveState = 'saving';
                    if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
                "
                x-on:matrix-save-finish.window="
                    pendingSaves = Math.max(0, pendingSaves - 1);
                    if (!$event.detail?.ok) {
                        saveState = 'error';
                        if (saveTimer) clearTimeout(saveTimer);
                        saveTimer = setTimeout(() => { if (saveState === 'error') saveState = ''; }, 4200);
                    } else if (pendingSaves === 0) {
                        saveState = 'saved';
                        if (saveTimer) clearTimeout(saveTimer);
                        saveTimer = setTimeout(() => { if (saveState === 'saved') saveState = ''; }, 1400);
                    }
                "
            >
            <div class="ft-matrix-toolbar card">
                <div>
                    <label>Role</label>
                    <select
                        wire:change="selectMatrixRole($event.target.value)"
                        wire:loading.attr="disabled"
                        wire:target="selectMatrixRole"
                        x-bind:disabled="pendingSaves > 0"
                        aria-label="Select role permissions"
                    >
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $roles; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $role): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                            <option value="<?php echo e($role->id); ?>" <?php if((int) $selectedRole->id === (int) $role->id): echo 'selected'; endif; ?>><?php echo e($role->name); ?></option>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    </select>
                </div>
                <div class="ft-matrix-role-info" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'matrix-role-info-'.e($selectedRole->id).''; ?>wire:key="matrix-role-info-<?php echo e($selectedRole->id); ?>">
                    <div>
                        <b><?php echo e($selectedRole->name); ?></b>
                        <span><?php echo e($selectedRole->description ?: 'Configure the module actions and effective record scope.'); ?></span>
                    </div>
                    <div class="ft-matrix-save-summary" aria-live="polite" aria-atomic="true">
                        <span x-cloak x-show="saveState === 'saving'" class="is-saving">
                            <i class="ft-matrix-save-spinner" aria-hidden="true"></i>
                            <span x-text="pendingSaves > 1 ? `Saving ${pendingSaves} changes…` : 'Saving change…'"></span>
                        </span>
                        <span x-cloak x-show="saveState === 'saved'" class="is-saved"><b aria-hidden="true">✓</b> All changes saved</span>
                        <span x-cloak x-show="saveState === 'error'" class="is-error"><b aria-hidden="true">!</b> Change not saved — use Retry</span>
                    </div>
                </div>
            </div>
            <div class="ft-role-matrix-stage">
                <div class="ft-matrix-loading" wire:loading.flex wire:target="selectMatrixRole">
                    <span class="ft-matrix-spinner" aria-hidden="true"></span>
                    <span>Loading role permissions…</span>
                </div>
                <div class="ft-role-matrix-wrap card" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'permission-matrix-'.e($selectedRole->id).''; ?>wire:key="permission-matrix-<?php echo e($selectedRole->id); ?>" wire:loading.class="is-switching-role" wire:target="selectMatrixRole">
                <table class="ft-role-matrix">
                    <thead><tr><th>Module</th><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $actions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $action): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><th><?php echo e(ucwords(str_replace('_',' ',$action))); ?></th><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?><th>Record scope</th></tr></thead>
                    <tbody>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $modules; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $code=>$meta): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <?php
                            $access = $selectedRole->moduleAccess->firstWhere('module_code', $code);
                        ?>
                        <tr>
                            <td><b><?php echo e($meta['name']); ?></b><small><?php echo e($meta['group']); ?></small></td>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $actions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $action): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                                <?php
                                    $permissionLocked = in_array($selectedRole->slug, ['super-admin', 'admin', 'administrator'], true);
                                    $permissionSupported = \App\Services\AccessControlService::supportsAction($code, $action);
                                    $permissionEnabled = $permissionSupported && ($permissionLocked || in_array($action, $access?->actions ?? [], true) || in_array('manage', $access?->actions ?? [], true));
                                ?>
                                <td
                                    data-label="<?php echo e(ucwords(str_replace('_',' ',$action))); ?>"
                                    class="ft-inline-edit-shell"
                                    x-data="window.FlowTrack.ui.inlineEdit({ key: <?php echo \Illuminate\Support\Js::from('role-'.$selectedRole->id.'-'.$code.'-'.$action)->toHtml() ?>, label: <?php echo \Illuminate\Support\Js::from(str_replace('_',' ',$action).' permission')->toHtml() ?>, value: <?php echo \Illuminate\Support\Js::from($permissionEnabled ? '1' : '0')->toHtml() ?>, display: <?php echo \Illuminate\Support\Js::from($permissionEnabled ? 'Enabled' : 'Disabled')->toHtml() ?> })"
                                    x-on:matrix-permission-synced.window="
                                        if (Number($event.detail.roleId) === <?php echo e((int) $selectedRole->id); ?> && $event.detail.module === '<?php echo e($code); ?>') {
                                            const enabled = Array.isArray($event.detail.actions) && $event.detail.actions.includes('<?php echo e($action); ?>');
                                            value = enabled ? '1' : '0'; savedValue = value; draftValue = value;
                                            display = enabled ? 'Enabled' : 'Disabled'; savedDisplay = display;
                                        }
                                    "
                                    :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                                >
                                    <input type="checkbox" class="ft-perm-check" :checked="value === '1'" :disabled="status === 'saving'"
                                        x-on:change="
                                            window.dispatchEvent(new CustomEvent('matrix-save-start'));
                                            commit($event.target.checked ? '1' : '0', $event.target.checked ? 'Enabled' : 'Disabled', () => $wire.setMatrixAction(<?php echo e($selectedRole->id); ?>, '<?php echo e($code); ?>', '<?php echo e($action); ?>', draftValue === '1')).then(ok => {
                                                if (ok && lastResponse) window.dispatchEvent(new CustomEvent('matrix-permission-synced', { detail: lastResponse }));
                                                window.dispatchEvent(new CustomEvent('matrix-save-finish', { detail: { ok } }));
                                            });
                                        "
                                        <?php if($permissionLocked): echo 'disabled'; endif; ?>>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($permissionLocked)): ?>
                                        <span class="ft-matrix-cell-feedback" aria-hidden="true">
                                            <i x-cloak x-show="status === 'saving'" class="ft-matrix-cell-spinner"></i>
                                            <b x-cloak x-show="status === 'error'" class="ft-matrix-cell-error">!</b>
                                        </span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </td>
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                            <?php
                                $scopeSupported = \App\Services\AccessControlService::supportsScope($code);
                                $administratorRole = in_array($selectedRole->slug, ['super-admin', 'admin', 'administrator'], true);
                                $scopeLocked = !$scopeSupported || $administratorRole;
                                $effectiveScope = $administratorRole ? 'all_records' : ($access?->record_scope ?? 'none');
                            ?>
                            <td
                                data-label="Record scope"
                                class="ft-inline-edit-shell"
                                x-data="window.FlowTrack.ui.inlineEdit({ key: <?php echo \Illuminate\Support\Js::from('role-'.$selectedRole->id.'-'.$code.'-scope')->toHtml() ?>, label: 'record scope', value: <?php echo \Illuminate\Support\Js::from($effectiveScope)->toHtml() ?>, display: <?php echo \Illuminate\Support\Js::from(str_replace('_',' ',$effectiveScope))->toHtml() ?> })"
                                x-on:matrix-permission-synced.window="
                                    if (Number($event.detail.roleId) === <?php echo e((int) $selectedRole->id); ?> && $event.detail.module === '<?php echo e($code); ?>' && $event.detail.recordScope) {
                                        value = String($event.detail.recordScope); savedValue = value; draftValue = value; display = value.replaceAll('_', ' '); savedDisplay = display;
                                    }
                                "
                                :class="{ 'is-inline-saving': status === 'saving', 'is-inline-error': status === 'error' }"
                            >
                                <select class="ft-scope-select" x-model="draftValue" :disabled="status === 'saving'"
                                    x-on:change="
                                        window.dispatchEvent(new CustomEvent('matrix-save-start'));
                                        commit($event.target.value, selectedLabel($event), () => $wire.setModuleScope(<?php echo e($selectedRole->id); ?>, '<?php echo e($code); ?>', draftValue)).then(ok => {
                                            if (ok && lastResponse) window.dispatchEvent(new CustomEvent('matrix-permission-synced', { detail: lastResponse }));
                                            window.dispatchEvent(new CustomEvent('matrix-save-finish', { detail: { ok } }));
                                        });
                                    "
                                    <?php if($scopeLocked): echo 'disabled'; endif; ?>>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = [
                                        'none' => 'None',
                                        'own_records' => 'Own records',
                                        'assigned_jobs' => 'Assigned / related',
                                        'department' => 'Department',
                                        'all_records' => 'All records',
                                    ]; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $value => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                                        <option value="<?php echo e($value); ?>"><?php echo e($label); ?></option>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                                </select>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if (! ($scopeLocked)): ?>
                                    <span class="ft-matrix-scope-feedback" aria-hidden="true">
                                        <i x-cloak x-show="status === 'saving'" class="ft-matrix-cell-spinner"></i>
                                        <b x-cloak x-show="status === 'error'" class="ft-matrix-cell-error">!</b>
                                    </span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </td>
                        </tr>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="ft-access-info">Every permission cell is selectable by an administrator. Users may hold multiple roles; effective actions and record visibility are the union of their active assigned roles. Changes save automatically; the status beside the selected role confirms when the matrix is saved. The saved matrix is the authoritative role capability set; FlowTrack enforces the relevant module/action wherever that operation is available. <b>View</b> controls visibility; enabling another record action automatically enables View. <b>Edit Own</b> means the record owner/coordinator for Orders, owner for Inquiries, and assignee for Tasks. <b>Edit All</b> applies to every record inside the selected scope. Every module now uses the same configurable scope choices: <b>None, Own records, Assigned / related, Department, and All records</b>. Operational record modules (Inquiries, Orders, Tasks, Documents and Document Archive) enforce those scopes directly. Document Archive permissions control the standalone archive page independently from document actions inside Inquiry/Order screens. <b>Report → View</b> controls both Inquiry Intelligence and Team Performance Report; users without Report access do not see report navigation or dashboard report panels. <b>Report → Export</b> controls report exports where an export action is available. Shared reference/setup modules keep their existing workspace-safe visibility rules, while Finance continues to inherit parent Inquiry/Order access. Administrator and Super Admin roles remain unrestricted by design.</div>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <?php elseif($tab==='users'): ?>
        <div class="section-head"><div><h3>Users & role assignments</h3><div class="small muted">Create, edit, assign roles, change passwords or remove users from FlowTrack.</div></div><a class="primary" href="<?php echo e(route('users.create')); ?>" wire:navigate>＋ Add User</a></div>

        
        <div class="ft-user-assignment-toolbar">
            <label class="ft-user-assignment-search" aria-label="Search users">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="11" cy="11" r="6.5"></circle>
                    <path d="m16 16 4 4"></path>
                </svg>
                <input
                    type="search"
                    wire:model.live.debounce.500ms="userSearch"
                    placeholder="Search user by name, email or position"
                    autocomplete="off"
                >
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($userSearch)): ?>
                    <button type="button" wire:click="clearUserSearch" aria-label="Clear user search">×</button>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </label>

            <span class="ft-user-assignment-search-result">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($userSearch)): ?>
                    <?php echo e($users->total()); ?> matching user<?php echo e($users->total() === 1 ? '' : 's'); ?>

                <?php else: ?>
                    <?php echo e($users->total()); ?> user<?php echo e($users->total() === 1 ? '' : 's'); ?>

                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </span>
        </div>

        <div class="card table-wrap"><table class="data-table ft-user-access-table"><thead><tr><th>User</th><th>Department</th><th>Roles</th><th>Effective scope</th><th>Open tasks</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $u): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
            <?php
                $adminUserStatus = in_array((string) ($u->account_status ?? ''), ['active','inactive','suspended'], true)
                    ? (string) $u->account_status
                    : ($u->is_active ? 'active' : 'inactive');
                $adminUserStatusClass = $adminUserStatus === 'active' ? 'b-green' : ($adminUserStatus === 'suspended' ? 'b-amber' : 'b-gray');
            ?>
            <tr <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'admin-user-'.e($u->id).''; ?>wire:key="admin-user-<?php echo e($u->id); ?>">
                <td><div class="person"><?php if (isset($component)) { $__componentOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginald04dd79f9e235eb8e58dee4526a2f3c2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.avatar','data' => ['user' => $u,'name' => $u->name]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($u),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($u->name)]); ?>
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
<?php endif; ?><div><b><?php echo e($u->name); ?></b><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($u->workspaceMemberships->first()?->job_title): ?><div class="small muted"><?php echo e($u->workspaceMemberships->first()->job_title); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?><div class="small muted"><?php echo e($u->email); ?></div></div></div></td>
                <td><?php echo e($u->department?->name ?? '—'); ?></td>
                <?php ($assignedRoleNames = $u->assignedRoles()->pluck('name')->filter()->values()); ?>
                <td><span class="ft-user-role-list <?php echo e($assignedRoleNames->isEmpty() ? 'muted' : ''); ?>"><?php echo e($assignedRoleNames->isNotEmpty() ? $assignedRoleNames->join(', ') : 'No role'); ?></span></td>
                <td><?php ($roleScopes = $u->assignedRoles()->pluck('default_scope')->filter()->unique()->values()); ?><span class="tag"><?php echo e($roleScopes->count() > 1 ? 'Combined scopes' : str_replace('_',' ',$roleScopes->first() ?: 'none')); ?></span></td>
                <td><?php echo e($u->open_tasks_count); ?></td>
                <td><button class="mini-btn" wire:click="toggleUserActive(<?php echo e($u->id); ?>)" <?php if($u->isSuperAdmin()): echo 'disabled'; endif; ?>><span class="badge <?php echo e($adminUserStatusClass); ?>"><?php echo e(ucfirst($adminUserStatus)); ?></span></button></td>
                <td data-label="Actions"><div class="ft-user-row-actions"><a class="ghost ft-user-edit-link" href="<?php echo e(route('users.edit', ['user' => $u->id, 'from' => 'administration'])); ?>" wire:navigate>Edit</a><button type="button" class="ft-user-delete-btn" wire:click="deleteUser(<?php echo e($u->id); ?>)" wire:confirm="Delete <?php echo e(addslashes($u->name)); ?>? Existing Job/Task history will be preserved, but this user account will be removed." <?php if($u->isSuperAdmin() || $u->id === auth()->id()): echo 'disabled'; endif; ?>>Delete</button></div></td>
            </tr>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            <tr>
                <td colspan="7">
                    <div class="ft-user-assignment-empty">
                        <b>No users found</b>
                        <span>Try another name, email or position.</span>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(filled($userSearch)): ?>
                            <button type="button" class="ghost" wire:click="clearUserSearch">Clear search</button>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </tbody></table></div>
        <div class="ft-list-pagination ft-user-pagination" aria-label="Users pagination">
            <span>Showing <b><?php echo e($users->firstItem() ?? 0); ?>–<?php echo e($users->lastItem() ?? 0); ?></b> of <?php echo e($users->total()); ?> users</span>
            <div class="ft-page-actions">
                <button type="button" wire:click="previousPage('usersPage')" <?php if($users->onFirstPage()): echo 'disabled'; endif; ?>>Previous</button>
                <span>Page <?php echo e($users->currentPage()); ?> of <?php echo e(max(1, $users->lastPage())); ?></span>
                <button type="button" wire:click="nextPage('usersPage')" <?php if(!$users->hasMorePages()): echo 'disabled'; endif; ?>>Next</button>
            </div>
        </div>
    <?php elseif($tab==='audit'): ?>
        <section class="card ft-access-panel"><div class="section-head"><div><h3>Access audit log</h3><div class="small muted">Role, permission, scope, security and assignment changes.</div></div></div>
            <div class="ft-access-audit"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $auditLog; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $event): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="ft-audit-row"><div class="ft-audit-dot"><?php echo e(strtoupper(substr($event->user?->name ?? 'S',0,1))); ?></div><div><b><?php echo e($event->description); ?></b><span><?php echo e($event->user?->name ?? 'System'); ?> · <?php echo e(\App\Support\UserLocalTime::format($event->created_at, 'M j, Y g:i A')); ?></span></div><code><?php echo e($event->event); ?></code></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?><div class="empty">No access changes recorded yet.</div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?></div>
        </section>
    <?php elseif($tab==='security'): ?>
        <div class="ft-access-grid-2">
            <section class="card ft-access-panel"><div class="section-head"><div><h3>Security controls</h3><div class="small muted">Workspace access-control policy flags stored in Master Data.</div></div></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $securitySettings; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $setting): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><div class="ft-security-row"><div><b><?php echo e($setting['label']); ?></b><span>Administrative access policy</span></div><label class="ft-switch"><input type="checkbox" wire:click="toggleSecurity('<?php echo e($setting['code']); ?>')" <?php if($setting['enabled']): echo 'checked'; endif; ?>><i></i></label></div><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
            </section>
            <section class="card ft-access-panel"><div class="section-head"><div><h3>Access policy</h3></div></div><div class="ft-control-note"><b>Administrator/Super Admin</b><span>Unrestricted application access. These roles can configure all permissions.</span></div><div class="ft-control-note"><b>All other roles</b><span>Users can hold multiple roles. FlowTrack combines granted actions and the allowed record scopes from all active assigned roles.</span></div><div class="ft-control-note"><b>Assignments</b><span>Task assignees see their assigned tasks; associated Job visibility follows from those assignments.</span></div></section>
        </div>
    <?php elseif($tab==='settings'): ?>
        <div class="ft-access-grid-2 ft-system-settings-grid">
            <section class="card ft-access-panel ft-workspace-settings-card">
                <div class="section-head">
                    <div><h3>Email service controls</h3><div class="small muted">Enable or disable Inquiry and Order business emails independently. Only Admin and Super Admin can change these switches.</div></div>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $emailServiceSettings; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $emailSetting): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <div class="ft-security-row ft-email-service-row" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'email-service-'.e($emailSetting['module']).''; ?>wire:key="email-service-<?php echo e($emailSetting['module']); ?>">
                        <div>
                            <b><?php echo e($emailSetting['label']); ?></b>
                            <span><?php echo e($emailSetting['description']); ?></span>
                        </div>
                        <div class="ft-email-service-toggle">
                            <div class="ft-email-service-state <?php echo e($emailSetting['enabled'] ? 'is-enabled' : 'is-disabled'); ?>" aria-live="polite">
                                <span class="ft-email-service-state-dot" aria-hidden="true"></span>
                                <span><?php echo e($emailSetting['enabled'] ? 'Email sending is on' : 'Email sending is off'); ?></span>
                            </div>
                            <div class="ft-email-service-segmented" role="group" aria-label="<?php echo e($emailSetting['label']); ?>">
                                <button
                                    type="button"
                                    class="ft-email-service-option is-on <?php echo e($emailSetting['enabled'] ? 'is-active' : ''); ?>"
                                    wire:click="setEmailService('<?php echo e($emailSetting['module']); ?>', true)"
                                    wire:loading.attr="disabled"
                                    wire:target="setEmailService('<?php echo e($emailSetting['module']); ?>')"
                                    aria-pressed="<?php echo e($emailSetting['enabled'] ? 'true' : 'false'); ?>"
                                    <?php if($emailSetting['enabled']): echo 'disabled'; endif; ?>
                                >
                                    <span class="ft-email-service-option-icon" aria-hidden="true">✓</span>
                                    <span>On</span>
                                </button>
                                <button
                                    type="button"
                                    class="ft-email-service-option is-off <?php echo e(! $emailSetting['enabled'] ? 'is-active' : ''); ?>"
                                    wire:click="requestDisableEmailService('<?php echo e($emailSetting['module']); ?>')"
                                    wire:loading.attr="disabled"
                                    wire:target="requestDisableEmailService('<?php echo e($emailSetting['module']); ?>')"
                                    aria-pressed="<?php echo e(! $emailSetting['enabled'] ? 'true' : 'false'); ?>"
                                    <?php if(! $emailSetting['enabled']): echo 'disabled'; endif; ?>
                                >
                                    <span class="ft-email-service-option-icon" aria-hidden="true">×</span>
                                    <span>Off</span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                <div class="ft-control-note ft-email-service-note">
                    <b>Safe workflow behavior</b>
                    <span>Turning a module off blocks outbound email only. Inquiry RFQ records and Order workflow tasks continue normally, and each skipped delivery is recorded for audit.</span>
                </div>
            </section>

            <section class="card ft-access-panel ft-workspace-settings-card">
                <div class="section-head">
                    <div><h3>Company & invoice identity</h3><div class="small muted">Legal company, address, tax, contact and payment details used on newly generated invoices.</div></div>
                </div>
                <div class="ft-workspace-setting-row">
                    <div><b>Company Setup</b><span>Keep invoice issuer details in one location instead of entering company information on every invoice.</span></div>
                    <a href="<?php echo e(route('company.setup')); ?>" wire:navigate class="secondary">Open Company Setup</a>
                </div>
            </section>
            <section class="card ft-access-panel ft-workspace-settings-card">
                <div class="section-head">
                    <div><h3>Local time</h3><div class="small muted">FlowTrack automatically uses each signed-in user's current device/browser time zone.</div></div>
                </div>
                <div class="ft-workspace-setting-row ft-auto-timezone-row">
                    <div><b>Automatic local time</b><span>No manual selection is required. If the user's device time zone changes, FlowTrack updates it for that session automatically. Database timestamps remain unchanged.</span></div>
                    <div class="ft-access-info"><b><?php echo e(app(\App\Services\WorkspaceSettingsService::class)->displayTimezone()); ?></b><span><?php echo e(app(\App\Services\WorkspaceSettingsService::class)->localNow()->format('M j, Y · g:i A')); ?></span></div>
                </div>
            </section>
        </div>

    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tab === 'settings' && $pendingEmailServiceModule): ?>
        <div
            class="overlay livewire-overlay ft-email-service-confirm-overlay"
            wire:click.self="cancelDisableEmailService"
            <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'email-service-disable-overlay-'.e($pendingEmailServiceModule).''; ?>wire:key="email-service-disable-overlay-<?php echo e($pendingEmailServiceModule); ?>"
        ></div>
        <section
            class="modal livewire-modal ft-email-service-confirm-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ft-email-service-confirm-title"
            aria-describedby="ft-email-service-confirm-description"
            <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'email-service-disable-modal-'.e($pendingEmailServiceModule).''; ?>wire:key="email-service-disable-modal-<?php echo e($pendingEmailServiceModule); ?>"
            x-data
            x-on:keydown.escape.window="$wire.cancelDisableEmailService()"
        >
            <div class="ft-email-service-confirm-head">
                <div class="ft-email-service-confirm-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M4.75 6.75h14.5v10.5H4.75z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                        <path d="m5.4 7.4 6.6 5 6.6-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M5 5 19 19" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/>
                    </svg>
                </div>
                <button
                    type="button"
                    class="ft-email-service-confirm-close"
                    wire:click="cancelDisableEmailService"
                    aria-label="Close confirmation"
                >×</button>
            </div>

            <div class="ft-email-service-confirm-body">
                <span class="ft-email-service-confirm-eyebrow">Email service control</span>
                <h2 id="ft-email-service-confirm-title">
                    Turn off <?php echo e($pendingEmailServiceModule === 'inquiry' ? 'Inquiry email service' : 'Order email service'); ?>?
                </h2>
                <p id="ft-email-service-confirm-description">
                    Outbound <?php echo e(ucfirst($pendingEmailServiceModule)); ?> emails will pause immediately and remain paused until an Admin or Super Admin turns the service back on.
                </p>

                <div class="ft-email-service-confirm-impact">
                    <div class="ft-email-service-confirm-impact-icon" aria-hidden="true">!</div>
                    <div>
                        <b>Emails affected</b>
                        <span>
                            <?php echo e($pendingEmailServiceModule === 'inquiry'
                                ? 'RFQ invitations, quotation acknowledgements and supplier award notifications.'
                                : 'Order workflow handoffs, invoice emails and payment reminders.'); ?>

                        </span>
                    </div>
                </div>

                <div class="ft-email-service-confirm-safe">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="m7.5 12.2 2.9 2.9 6.2-6.2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.7"/>
                    </svg>
                    <div>
                        <b>Workflow remains active</b>
                        <span>Inquiry and Order records, tasks and workflow actions continue normally. Only outbound email delivery for this module is blocked.</span>
                    </div>
                </div>
            </div>

            <div class="ft-email-service-confirm-actions">
                <button
                    type="button"
                    class="ft-email-service-confirm-cancel"
                    wire:click="cancelDisableEmailService"
                    wire:loading.attr="disabled"
                    wire:target="confirmDisableEmailService"
                >Keep email on</button>
                <button
                    type="button"
                    class="ft-email-service-confirm-danger"
                    wire:click="confirmDisableEmailService"
                    wire:loading.attr="disabled"
                    wire:target="confirmDisableEmailService"
                >
                    <span wire:loading.remove wire:target="confirmDisableEmailService">Turn off email service</span>
                    <span wire:loading wire:target="confirmDisableEmailService">Turning off…</span>
                </button>
            </div>
        </section>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tab === 'branding'): ?>
        <?php echo $__env->make('livewire.administration.partials.branding', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showRoleModal): ?>
        <div class="overlay livewire-overlay" wire:click.self="closeRole"></div>
        <form class="modal livewire-modal" wire:submit="saveRole" data-ft-feedback-scope="form" role="dialog" aria-modal="true" aria-label="<?php echo e($editingRoleId ? 'Edit Role' : 'Create Role'); ?>">
            <div class="modal-head">
                <h2><?php echo e($editingRoleId ? 'Edit Role' : 'Create Role'); ?></h2>
                <button type="button" class="close-btn" wire:click="closeRole" aria-label="Close role form">×</button>
            </div>

            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="ft-role-name">Role name *</label>
                        <input id="ft-role-name" wire:model="roleName" maxlength="255" required <?php $__errorArgs = ['roleName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> aria-invalid="true" aria-describedby="ft-role-name-error" <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?> placeholder="e.g. Product Manager">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['roleName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><?php if (isset($component)) { $__componentOriginalce11a07acd8b47e338d25689bef957cf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalce11a07acd8b47e338d25689bef957cf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.validation-message','data' => ['id' => 'ft-role-name-error','message' => $message]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.validation-message'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'ft-role-name-error','message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($message)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $attributes = $__attributesOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__attributesOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $component = $__componentOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__componentOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="ft-role-code">Role code</label>
                        <input id="ft-role-code" wire:model="roleCode" maxlength="80" <?php $__errorArgs = ['roleCode'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> aria-invalid="true" aria-describedby="ft-role-code-error" <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?> placeholder="e.g. PRODUCT_MANAGER">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['roleCode'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><?php if (isset($component)) { $__componentOriginalce11a07acd8b47e338d25689bef957cf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalce11a07acd8b47e338d25689bef957cf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.validation-message','data' => ['id' => 'ft-role-code-error','message' => $message]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.validation-message'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['id' => 'ft-role-code-error','message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($message)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $attributes = $__attributesOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__attributesOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $component = $__componentOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__componentOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        <small class="muted">Optional. Generated from the name if blank.</small>
                    </div>
                    <div class="field full">
                        <label for="ft-role-description">Description</label>
                        <textarea id="ft-role-description" wire:model="roleDescription" maxlength="2000" rows="3"></textarea>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['roleDescription'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><?php if (isset($component)) { $__componentOriginalce11a07acd8b47e338d25689bef957cf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalce11a07acd8b47e338d25689bef957cf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.validation-message','data' => ['message' => $message]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.validation-message'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($message)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $attributes = $__attributesOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__attributesOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $component = $__componentOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__componentOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="ft-role-scope">Default record scope</label>
                        <select id="ft-role-scope" wire:model="roleDefaultScope">
                            <option value="none">None</option>
                            <option value="own_records">Own records</option>
                            <option value="assigned_jobs">Assigned Jobs</option>
                            <option value="department">Department</option>
                            <option value="all_records">All records</option>
                        </select>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['roleDefaultScope'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><?php if (isset($component)) { $__componentOriginalce11a07acd8b47e338d25689bef957cf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalce11a07acd8b47e338d25689bef957cf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.ui.validation-message','data' => ['message' => $message]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('ui.validation-message'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['message' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($message)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $attributes = $__attributesOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__attributesOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalce11a07acd8b47e338d25689bef957cf)): ?>
<?php $component = $__componentOriginalce11a07acd8b47e338d25689bef957cf; ?>
<?php unset($__componentOriginalce11a07acd8b47e338d25689bef957cf); ?>
<?php endif; ?><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="ft-role-active">Status</label>
                        <select id="ft-role-active" wire:model="roleActive">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(!$editingRoleId): ?>
                        <div class="field full">
                            <small class="muted">New Product Manager roles receive Products and Product Categories permissions only. Other roles start without permissions. All permissions can be reviewed in the Permission Matrix after saving.</small>
                        </div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="ghost" wire:click="closeRole">Cancel</button>
                <button type="submit" class="primary" wire:loading.attr="disabled" wire:target="saveRole">
                    <span wire:loading.remove wire:target="saveRole"><?php echo e($editingRoleId ? 'Save Changes' : 'Create Role'); ?></span>
                    <span wire:loading wire:target="saveRole">Saving…</span>
                </button>
            </div>
        </form>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/administration/index.blade.php ENDPATH**/ ?>