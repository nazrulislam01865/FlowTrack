        @php
            $logoPreview = $logoUpload && in_array(strtolower((string) $logoUpload->getClientOriginalExtension()), ['jpg','jpeg','png','webp'], true) ? $logoUpload->temporaryUrl() : null;
            $faviconPreview = $faviconUpload && in_array(strtolower((string) $faviconUpload->getClientOriginalExtension()), ['jpg','jpeg','png','webp'], true) ? $faviconUpload->temporaryUrl() : null;
        @endphp
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
                    @if($logoPreview ?: ($branding['logo_url'] ?? null))
                        <img src="{{ $logoPreview ?: $branding['logo_url'] }}" alt="Current system logo">
                    @else
                        <div class="ft-branding-fallback"><span>FT</span><b>{{ $branding['name'] ?? 'FlowTrack' }}</b></div>
                    @endif
                </div>

                <div class="ft-branding-upload-row">
                    <label class="ft-branding-file-button">
                        <input type="file" wire:model="logoUpload" accept="image/jpeg,image/png,image/webp">
                        <span>{{ ($branding['logo_path'] ?? null) ? 'Choose new logo' : 'Choose logo' }}</span>
                    </label>
                    @if($logoUpload)
                        <button type="button" class="primary" wire:click="saveLogo" wire:loading.attr="disabled" wire:target="saveLogo,logoUpload">
                            <span wire:loading.remove wire:target="saveLogo">Save logo</span>
                            <span wire:loading wire:target="saveLogo">Saving…</span>
                        </button>
                    @endif
                    @if($branding['logo_path'] ?? null)
                        <button type="button" class="danger-btn" wire:click="removeLogo" wire:confirm="Remove the custom system logo and return to the FlowTrack fallback logo?">Remove</button>
                    @endif
                </div>
                @error('logoUpload')<div class="validation-error">{{ $message }}</div>@enderror
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
                    @if($faviconPreview ?: ($branding['favicon_url'] ?? null))
                        <img src="{{ $faviconPreview ?: $branding['favicon_url'] }}" alt="Current favicon">
                    @else
                        <div class="ft-branding-favicon-fallback">FT</div>
                    @endif
                    <div><b>Browser tab preview</b><span>{{ $branding['name'] ?? 'FlowTrack' }}</span></div>
                </div>

                <div class="ft-branding-upload-row">
                    <label class="ft-branding-file-button">
                        <input type="file" wire:model="faviconUpload" accept="image/png,image/webp,image/jpeg,image/x-icon,.ico">
                        <span>{{ ($branding['favicon_path'] ?? null) ? 'Choose new favicon' : 'Choose favicon' }}</span>
                    </label>
                    @if($faviconUpload)
                        <button type="button" class="primary" wire:click="saveFavicon" wire:loading.attr="disabled" wire:target="saveFavicon,faviconUpload">
                            <span wire:loading.remove wire:target="saveFavicon">Save favicon</span>
                            <span wire:loading wire:target="saveFavicon">Saving…</span>
                        </button>
                    @endif
                    @if($branding['favicon_path'] ?? null)
                        <button type="button" class="danger-btn" wire:click="removeFavicon" wire:confirm="Remove the custom favicon and return to the default FlowTrack favicon?">Remove</button>
                    @endif
                </div>
                @error('faviconUpload')<div class="validation-error">{{ $message }}</div>@enderror
                <div wire:loading wire:target="faviconUpload" class="small muted">Preparing favicon…</div>
                <div class="ft-branding-help"><b>Recommended:</b> 64 × 64 or 128 × 128 px. ICO/PNG/WebP/JPG, maximum 1 MB.</div>
            </section>
        </div>
        <div class="ft-access-info ft-branding-note">Branding files are stored with the workspace and served through FlowTrack itself, so they do not depend on a <code>public/storage</code> symlink. Only Admin and Super Admin accounts can change or remove these files.</div>

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
                <input id="supplier-footer-copyright" type="text" wire:model="footerCopyright" maxlength="255" placeholder="© {{ date('Y') }} FlowTrack">
                @error('footerCopyright')<div class="validation-error">{{ $message }}</div>@enderror
                <div class="ft-supplier-footer-links">
                    @foreach(['privacy'=>'Privacy','terms'=>'Terms','help'=>'Help center'] as $key=>$defaultLabel)
                        <div class="ft-supplier-footer-link-row" wire:key="supplier-footer-{{ $key }}">
                            <label class="ft-supplier-footer-check"><input type="checkbox" wire:model="footerLinks.{{ $key }}.enabled"> Show {{ $defaultLabel }}</label>
                            <div>
                                <label class="ft-supplier-footer-label" for="supplier-footer-{{ $key }}-label">Link label</label>
                                <input id="supplier-footer-{{ $key }}-label" type="text" wire:model="footerLinks.{{ $key }}.label" maxlength="80">
                                @error('footerLinks.'.$key.'.label')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label class="ft-supplier-footer-label" for="supplier-footer-{{ $key }}-url">Destination URL (HTTPS or HTTP)</label>
                                <input id="supplier-footer-{{ $key }}-url" type="url" wire:model="footerLinks.{{ $key }}.url" maxlength="2048" placeholder="https://example.com/{{ $key }}">
                                @error('footerLinks.'.$key.'.url')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    @endforeach
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
