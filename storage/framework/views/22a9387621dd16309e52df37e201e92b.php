        <?php
            $logoPreview = $logoUpload && in_array(strtolower((string) $logoUpload->getClientOriginalExtension()), ['jpg','jpeg','png','webp'], true) ? $logoUpload->temporaryUrl() : null;
            $faviconPreview = $faviconUpload && in_array(strtolower((string) $faviconUpload->getClientOriginalExtension()), ['jpg','jpeg','png','webp'], true) ? $faviconUpload->temporaryUrl() : null;
        ?>
        <div class="ft-branding-grid" data-ft-feedback-scope="form">
            <section class="card ft-branding-card">
                <div class="ft-branding-card-head">
                    <div>
                        <span class="ft-branding-kicker">Primary identity</span>
                        <h3>System logo</h3>
                        <p>Displayed in the sidebar and on the sign-in screen. Clicking the in-app logo always opens Dashboard.</p>
                    </div>
                    <span class="ft-branding-access-badge">Admin only</span>
                </div>

                <div class="ft-branding-preview ft-branding-logo-preview">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($logoPreview ?: ($branding['logo_url'] ?? null)): ?>
                        <img src="<?php echo e($logoPreview ?: $branding['logo_url']); ?>" alt="Current system logo">
                    <?php else: ?>
                        <div class="ft-branding-fallback"><span>FT</span><b><?php echo e($branding['name'] ?? 'FlowTrack'); ?></b></div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <div class="ft-branding-upload-row">
                    <label class="ft-branding-file-button">
                        <input type="file" wire:model="logoUpload" accept="image/jpeg,image/png,image/webp">
                        <span><?php echo e(($branding['logo_path'] ?? null) ? 'Choose new logo' : 'Choose logo'); ?></span>
                    </label>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($logoUpload): ?>
                        <button type="button" class="primary" wire:click="saveLogo" wire:loading.attr="disabled" wire:target="saveLogo,logoUpload">
                            <span wire:loading.remove wire:target="saveLogo">Save logo</span>
                            <span wire:loading wire:target="saveLogo">Saving…</span>
                        </button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($branding['logo_path'] ?? null): ?>
                        <button type="button" class="danger-btn" wire:click="removeLogo" wire:confirm="Remove the custom system logo and return to the FlowTrack fallback logo?">Remove</button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['logoUpload'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div wire:loading wire:target="logoUpload" class="small muted">Preparing logo preview…</div>
                <div class="ft-branding-help"><b>Recommended:</b> transparent PNG/WebP, up to 800 × 240 px. JPG/PNG/WebP, maximum 2 MB.</div>
            </section>

            <section class="card ft-branding-card">
                <div class="ft-branding-card-head">
                    <div>
                        <span class="ft-branding-kicker">Browser identity</span>
                        <h3>Favicon</h3>
                        <p>Shown in browser tabs and bookmarks. A square image gives the clearest result.</p>
                    </div>
                    <span class="ft-branding-access-badge">Admin only</span>
                </div>

                <div class="ft-branding-preview ft-branding-favicon-preview">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($faviconPreview ?: ($branding['favicon_url'] ?? null)): ?>
                        <img src="<?php echo e($faviconPreview ?: $branding['favicon_url']); ?>" alt="Current favicon">
                    <?php else: ?>
                        <div class="ft-branding-favicon-fallback">FT</div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <div><b>Browser tab preview</b><span><?php echo e($branding['name'] ?? 'FlowTrack'); ?></span></div>
                </div>

                <div class="ft-branding-upload-row">
                    <label class="ft-branding-file-button">
                        <input type="file" wire:model="faviconUpload" accept="image/png,image/webp,image/jpeg,image/x-icon,.ico">
                        <span><?php echo e(($branding['favicon_path'] ?? null) ? 'Choose new favicon' : 'Choose favicon'); ?></span>
                    </label>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($faviconUpload): ?>
                        <button type="button" class="primary" wire:click="saveFavicon" wire:loading.attr="disabled" wire:target="saveFavicon,faviconUpload">
                            <span wire:loading.remove wire:target="saveFavicon">Save favicon</span>
                            <span wire:loading wire:target="saveFavicon">Saving…</span>
                        </button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($branding['favicon_path'] ?? null): ?>
                        <button type="button" class="danger-btn" wire:click="removeFavicon" wire:confirm="Remove the custom favicon and return to the default FlowTrack favicon?">Remove</button>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['faviconUpload'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div wire:loading wire:target="faviconUpload" class="small muted">Preparing favicon…</div>
                <div class="ft-branding-help"><b>Recommended:</b> 64 × 64 or 128 × 128 px. ICO/PNG/WebP/JPG, maximum 1 MB.</div>
            </section>
        </div>
        <div class="ft-access-info ft-branding-note">Branding files are stored with the workspace and served through FlowTrack itself, so they do not depend on a <code>public/storage</code> symlink. Only Admin and Super Admin accounts can change or remove these files.</div>

        <section class="card ft-branding-card ft-supplier-footer-card" data-ft-feedback-scope="form">
            <div class="ft-branding-card-head">
                <div>
                    <span class="ft-branding-kicker">System identity</span>
                    <h3>System name</h3>
                    <p>Used in the sign-in screen, sidebar, browser titles and customer-facing tracking pages.</p>
                </div>
                <span class="ft-branding-access-badge">Admin &amp; Super Admin</span>
            </div>
            <div class="ft-supplier-footer-fields">
                <div>
                    <label class="ft-supplier-footer-label" for="ft-system-brand-name">Display name</label>
                    <input id="ft-system-brand-name" type="text" wire:model="brandingName" maxlength="255" placeholder="FlowTrack">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['brandingName'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
                <div class="ft-branding-upload-row">
                    <button type="button" class="primary" wire:click="saveBrandingName" wire:loading.attr="disabled" wire:target="saveBrandingName">
                        <span wire:loading.remove wire:target="saveBrandingName">Save system name</span>
                        <span wire:loading wire:target="saveBrandingName">Saving…</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="card ft-branding-card ft-supplier-footer-card" data-ft-feedback-scope="form">
            <div class="ft-branding-card-head">
                <div>
                    <span class="ft-branding-kicker">Application &amp; public pages</span>
                    <h3>Copyright footer</h3>
                    <p>Shown at the bottom of the main system, login and public order-tracking pages. Independent from the supplier portal footer.</p>
                </div>
                <span class="ft-branding-access-badge">Admin &amp; Super Admin</span>
            </div>
            <div class="ft-supplier-footer-fields">
                <label class="ft-supplier-footer-check" for="ft-footer-enabled">
                    <input id="ft-footer-enabled" type="checkbox" wire:model="systemFooter.enabled">
                    Show footer on application and public pages
                </label>
                <div class="ft-system-footer-editor-grid">
                    <div>
                        <label class="ft-supplier-footer-label" for="ft-footer-copyright">Copyright text</label>
                        <input id="ft-footer-copyright" type="text" wire:model="systemFooter.copyright" maxlength="80" placeholder="© <?php echo e(date('Y')); ?>">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['systemFooter.copyright'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div>
                        <label class="ft-supplier-footer-label" for="ft-footer-owner">Copyright owner</label>
                        <input id="ft-footer-owner" type="text" wire:model="systemFooter.owner" maxlength="120" placeholder="Itqan Consulting">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['systemFooter.owner'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div>
                        <label class="ft-supplier-footer-label" for="ft-footer-url">Owner website</label>
                        <input id="ft-footer-url" type="url" wire:model="systemFooter.website_url" maxlength="2048" placeholder="https://itqanconsulting.com">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['systemFooter.website_url'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                    <div>
                        <label class="ft-supplier-footer-label" for="ft-footer-suffix">Closing text</label>
                        <input id="ft-footer-suffix" type="text" wire:model="systemFooter.suffix" maxlength="120" placeholder="All rights reserved.">
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['systemFooter.suffix'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                </div>
                <div class="ft-branding-help">Default: © <?php echo e(date('Y')); ?> <b>Itqan Consulting</b>. All rights reserved. The owner name links to <b>itqanconsulting.com</b>; leave the URL blank for plain text.</div>
                <div class="ft-branding-upload-row">
                    <button type="button" class="primary" wire:click="saveSystemFooter" wire:loading.attr="disabled" wire:target="saveSystemFooter">
                        <span wire:loading.remove wire:target="saveSystemFooter">Save application footer</span>
                        <span wire:loading wire:target="saveSystemFooter">Saving…</span>
                    </button>
                </div>
            </div>
        </section>

        <section class="card ft-branding-card ft-supplier-footer-card" data-ft-feedback-scope="form">
            <div class="ft-branding-card-head">
                <div>
                    <span class="ft-branding-kicker">Supplier portal</span>
                    <h3>Sign-in footer</h3>
                    <p>Manage the footer on the supplier portal login page. These settings sync securely from FlowTracker; suppliers cannot edit them.</p>
                </div>
                <span class="ft-branding-access-badge">Admin only</span>
            </div>
            <div class="ft-supplier-footer-fields">
                <label class="ft-supplier-footer-label" for="supplier-footer-copyright">Copyright text</label>
                <input id="supplier-footer-copyright" type="text" wire:model="footerCopyright" maxlength="255" placeholder="© <?php echo e(date('Y')); ?> Itqan Consulting. All rights reserved.">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['footerCopyright'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <div class="ft-supplier-footer-links">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = ['privacy'=>'Privacy','terms'=>'Terms','help'=>'Help center']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key=>$defaultLabel): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <div class="ft-supplier-footer-link-row" <?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::$currentLoop['key'] = 'supplier-footer-'.e($key).''; ?>wire:key="supplier-footer-<?php echo e($key); ?>">
                            <label class="ft-supplier-footer-check"><input type="checkbox" wire:model="footerLinks.<?php echo e($key); ?>.enabled"> Show <?php echo e($defaultLabel); ?></label>
                            <div>
                                <label class="ft-supplier-footer-label" for="supplier-footer-<?php echo e($key); ?>-label">Link label</label>
                                <input id="supplier-footer-<?php echo e($key); ?>-label" type="text" wire:model="footerLinks.<?php echo e($key); ?>.label" maxlength="80">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['footerLinks.'.$key.'.label'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                            <div>
                                <label class="ft-supplier-footer-label" for="supplier-footer-<?php echo e($key); ?>-url">Destination URL (HTTPS or HTTP)</label>
                                <input id="supplier-footer-<?php echo e($key); ?>-url" type="url" wire:model="footerLinks.<?php echo e($key); ?>.url" maxlength="2048" placeholder="https://example.com/<?php echo e($key); ?>">
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['footerLinks.'.$key.'.url'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="validation-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </div>
                        </div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                </div>
                <div class="ft-branding-help">Leave a URL blank to show its label as plain text instead of a dead link. Uncheck a link to hide it.</div>
                <div class="ft-branding-upload-row">
                    <button type="button" class="primary" wire:click="saveSupplierFooter" wire:loading.attr="disabled" wire:target="saveSupplierFooter">
                        <span wire:loading.remove wire:target="saveSupplierFooter">Save supplier footer</span>
                        <span wire:loading wire:target="saveSupplierFooter">Saving…</span>
                    </button>
                </div>
            </div>
        </section>
<?php /**PATH /Applications/XAMPP/xamppfiles/htdocs/laravel/Flowtrackfor/resources/views/livewire/administration/partials/branding.blade.php ENDPATH**/ ?>