<div
    class="ft-user-editor"
    x-data="{
        dirty: false,
        editorName: $wire.entangle('name'),
        editorEmail: $wire.entangle('email'),
        editorStatus: $wire.entangle('accountStatus')
    }"
    x-on:input.capture="if ({{ $isEditing ? 'true' : 'false' }}) { const isPasswordField = $event.target?.matches?.('[data-user-password-field]'); if (!isPasswordField) dirty = true }"
    x-on:change.capture="if ({{ $isEditing ? 'true' : 'false' }}) { const isPasswordField = $event.target?.matches?.('[data-user-password-field]'); if (!isPasswordField) dirty = true }"
    x-on:user-editor-generated-password.window="dirty = true"
    x-on:user-editor-saved.window="dirty = false"
    x-on:user-editor-editing-enabled.window="dirty = false"
    x-on:user-editor-editing-cancelled.window="dirty = false"
>
    @php
        $profilePreviewUrl = $profileImage && str_starts_with((string) $profileImage->getMimeType(), 'image/')
            ? $profileImage->temporaryUrl()
            : null;
    @endphp

    <div class="ft-user-editor-breadcrumb">
        @if($profileMode)
            <span>Profile</span>
        @elseif($canManageAccess)
            <a href="{{ route('administration', ['tab' => 'users']) }}" wire:navigate>Roles &amp; Access</a>
            <span>/</span>
            <a href="{{ route('administration', ['tab' => 'users']) }}" wire:navigate>Users &amp; Assignments</a>
            <span>/</span>
            <span>{{ $createMode ? 'Create user' : 'Edit user' }}</span>
        @else
            <span>{{ $createMode ? 'Create user' : 'Edit user' }}</span>
        @endif
    </div>

    <div class="ft-user-editor-head">
        <div>
            <h1>{{ $profileMode ? 'My profile' : ($createMode ? 'Create user' : 'Edit user') }}</h1>
            <p>
                @if($createMode)
                    Add identity, contact information, access, status, and sign-in security for the new user.
                @elseif($profileMode && !$isEditing)
                    Review your identity, contact information, access details, and account information.
                @else
                    Update identity, contact information, access, status, and sign-in security.
                @endif
            </p>
        </div>
        <div class="ft-user-editor-head-actions">
            <span class="ft-user-editor-ref">{{ $createMode ? 'New user' : 'User ID · '.$userReference }}</span>
            @if($profileMode && !$isEditing)
                <button class="ft-user-editor-button is-save ft-user-editor-edit-profile" type="button" wire:click="enableEditing" wire:loading.attr="disabled" wire:target="enableEditing">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5 4 4M4 20l3.5-.7L19 7.8a2.1 2.1 0 0 0-3-3L4.6 16.2 4 20Z"/></svg>
                    <span wire:loading.remove wire:target="enableEditing">Edit profile</span>
                    <span wire:loading wire:target="enableEditing">Opening…</span>
                </button>
            @endif
        </div>
    </div>

    <form wire:submit="saveChanges" novalidate data-ft-feedback-scope="form">
        <div class="ft-user-editor-layout">
            <aside class="card ft-user-editor-profile-card">
                <div class="ft-user-editor-avatar-wrap">
                    @if($profilePreviewUrl)
                        <span class="ft-user-editor-avatar"><img src="{{ $profilePreviewUrl }}" alt="Profile image preview"></span>
                    @else
                        <x-ui.avatar :name="$name" :src="$profileImageUrl ?: null" :size="96" class="ft-user-editor-avatar" />
                    @endif

                    @if($isEditing)
                        <label class="ft-user-editor-avatar-edit" for="ft-user-editor-avatar-input" aria-label="Choose profile image">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5 4 4M4 20l3.5-.7L19 7.8a2.1 2.1 0 0 0-3-3L4.6 16.2 4 20Z"/></svg>
                        </label>
                    @endif
                </div>

                <div class="ft-user-editor-profile-copy">
                    <h2 x-text="editorName || 'Unnamed user'">{{ $name !== '' ? $name : 'Unnamed user' }}</h2>
                    <span x-text="editorEmail">{{ $email }}</span>
                    <div
                        class="ft-user-editor-status"
                        x-bind:class="{
                            'is-active': editorStatus === 'active',
                            'is-inactive': editorStatus === 'inactive',
                            'is-suspended': editorStatus === 'suspended'
                        }"
                    >
                        <i></i>
                        <span x-text="editorStatus === 'suspended' ? 'Suspended' : (editorStatus === 'inactive' ? 'Inactive' : 'Active')"></span>
                    </div>
                </div>

                @if($isEditing)
                    <label
                        class="ft-user-editor-upload-zone"
                        for="ft-user-editor-avatar-input"
                        x-data="{ dragging: false }"
                        x-bind:class="{ 'is-dragging': dragging }"
                        x-on:dragenter.prevent="dragging = true"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="
                            dragging = false;
                            const file = $event.dataTransfer.files && $event.dataTransfer.files[0];
                            if (file) {
                                const transfer = new DataTransfer();
                                transfer.items.add(file);
                                $refs.profileImageInput.files = transfer.files;
                                $refs.profileImageInput.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        "
                    >
                        <strong>Drag &amp; drop profile image</strong>
                        <span>or click to choose JPG, PNG or WebP</span>
                        <input
                            id="ft-user-editor-avatar-input"
                            x-ref="profileImageInput"
                            type="file"
                            wire:model="profileImage"
                            accept="image/jpeg,image/png,image/webp"
                        >
                    </label>
                    @error('profileImage')<div class="validation-error ft-user-editor-image-error">{{ $message }}</div>@enderror
                    <div wire:loading wire:target="profileImage" class="ft-user-editor-image-help">Preparing image preview…</div>
                    <p class="ft-user-editor-image-help">Recommended: square image, at least 256 × 256 px, maximum 250 KB.</p>
                @else
                    <p class="ft-user-editor-image-help ft-user-editor-view-help">Profile photo can be changed after clicking Edit profile.</p>
                @endif

                <div class="ft-user-editor-facts">
                    <div><span>Last active</span><b>{{ $lastActiveLabel }}</b></div>
                    <div><span>Open tasks</span><b>{{ number_format($openTasks) }}</b></div>
                    <div><span>Created</span><b>{{ $createdLabel }}</b></div>
                </div>
            </aside>

            <div class="ft-user-editor-stack">
                <section class="card ft-user-editor-card">
                    <header class="ft-user-editor-section-head">
                        <div>
                            <h2>Profile &amp; contact</h2>
                            <p>Information used throughout assignments, comments, and notifications.</p>
                        </div>
                        <span class="ft-user-editor-section-icon">A</span>
                    </header>
                    <div class="ft-user-editor-section-body">
                        <div class="ft-user-editor-fields">
                            <div class="field">
                                <label for="ft-edit-name">Full name *</label>
                                <input id="ft-edit-name" x-model="editorName" autocomplete="name" required @disabled(!$isEditing)>
                                @error('name')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>

                            <div class="field">
                                <label for="ft-edit-position">Position / job title <span>Optional</span></label>
                                <input id="ft-edit-position" wire:model="position" placeholder="e.g. Production Manager" maxlength="120" @disabled(!$isEditing)>
                                @error('position')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>

                            <div class="field">
                                <label for="ft-edit-email">Official email *</label>
                                <input id="ft-edit-email" x-model="editorEmail" type="email" autocomplete="email" required @disabled(!$isEditing)>
                                <small>Used for sign-in, reset links, and official notifications.</small>
                                @error('email')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>

                            <div class="field">
                                <label for="ft-edit-wechat">WeChat ID <span>Optional</span></label>
                                <input id="ft-edit-wechat" wire:model="wechatId" placeholder="e.g. Amanda_IID" maxlength="80" @disabled(!$isEditing)>
                                <small>For internal reference only; FlowTrack will not message WeChat automatically.</small>
                                @error('wechatId')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>

                            <div class="field">
                                <label for="ft-edit-phone">Mobile number <span>Optional</span></label>
                                <input id="ft-edit-phone" wire:model="phone" type="tel" autocomplete="tel" placeholder="Include country code" maxlength="60" @disabled(!$isEditing)>
                                @error('phone')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card ft-user-editor-card">
                    <header class="ft-user-editor-section-head">
                        <div>
                            <h2>Access &amp; employment</h2>
                            <p>Controls the user’s role, department, business unit, and account availability.</p>
                        </div>
                        <span class="ft-user-editor-section-icon">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12a2 2 0 0 1 2 2v6H4v-6a2 2 0 0 1 2-2Z"/></svg>
                        </span>
                    </header>
                    <div class="ft-user-editor-section-body">
                        <div class="ft-user-editor-fields ft-user-editor-access-fields">
                            <div class="field">
                                <label>Roles *</label>
                                <x-ui.multi-role-select model="roleIds" :options="$roleOptions" :disabled="!$isEditing || !$canManageAccess || $targetIsSuperAdmin" placeholder="Select one or more roles" />
                                <small>{{ $canManageAccess ? 'Permissions and record scopes are combined from all selected roles.' : 'Role permissions are managed by an administrator.' }}</small>
                                @error('roleIds')<div class="validation-error">{{ $message }}</div>@enderror
                                @error('roleIds.*')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field" x-on:flowtrack-selection-changed="if ($event.detail?.property === 'departmentId') dirty = true">
                                <x-ui.search-select
                                    class="ft-user-editor-department-picker"
                                    label="Department"
                                    property="departmentId"
                                    required
                                    type="departments"
                                    context="user-editor"
                                    action="setDepartmentSelection"
                                    :value="$departmentId"
                                    placeholder="No department"
                                    :initial-options="$departmentOptions"
                                    :disabled="!$isEditing || !$canManageAccess"
                                    :menu-width="320"
                                    :fixed-menu="true"
                                    wire:key="user-editor-department-{{ $departmentId ?? 'none' }}-{{ (!$isEditing || !$canManageAccess) ? 'disabled' : 'enabled' }}"
                                />
                                @error('departmentId')<x-ui.validation-message :message="$message" />@enderror
                            </div>

                            <div class="field">
                                <label for="ft-edit-business-unit">Business unit *</label>
                                <select id="ft-edit-business-unit" wire:model="businessUnit" @disabled(!$isEditing || !$canManageAccess)>
                                    <option value="iid">IID</option>
                                    <option value="nep">NEP</option>
                                    <option value="both">Both IID &amp; NEP</option>
                                </select>
                                @error('businessUnit')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>

                            <div class="field">
                                <label for="ft-edit-status">Account status *</label>
                                <select id="ft-edit-status" x-model="editorStatus" @disabled(!$isEditing || !$canManageAccess || $targetIsSuperAdmin)>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="suspended">Suspended</option>
                                </select>
                                @error('accountStatus')<div class="validation-error">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="ft-user-editor-permission-note">
                            <span aria-hidden="true">⌑</span>
                            <span><b>Access remains role-based.</b> Permissions are combined from every selected role. Department and business unit do not silently add permissions.</span>
                        </div>

                        @if(!$canManageAccess)
                            <div class="ft-user-editor-managed-note">Role, department, business unit, and account status are managed by an administrator.</div>
                        @elseif($isEditing)
                            <div class="ft-user-editor-status-warning" x-show="editorStatus !== 'active'" x-cloak><b>Account access will stop.</b> Existing assignments and activity history remain unchanged.</div>
                        @endif
                    </div>
                </section>

                @if($isEditing)
                    <section
                        class="card ft-user-editor-card"
                        x-data="{
                            // Keep password fields local until the user intentionally interacts with them.
                            // This prevents browser/password-manager autofill from changing Livewire state
                            // or showing a false password mismatch when an existing user is opened.
                            password: '',
                            confirmation: '',
                            passwordFocused: false,
                            confirmationFocused: false,
                            passwordTouched: false,
                            confirmationTouched: false,
                            showPassword: false,
                            showConfirmation: false,
                            syncPassword(value) {
                                this.password = value;
                                if (this.passwordTouched || this.passwordFocused) {
                                    this.passwordTouched = true;
                                    $wire.$set('newPassword', value);
                                    dirty = true;
                                }
                            },
                            syncConfirmation(value) {
                                this.confirmation = value;
                                if (this.confirmationTouched || this.confirmationFocused) {
                                    this.confirmationTouched = true;
                                    $wire.$set('newPasswordConfirmation', value);
                                    dirty = true;
                                }
                            },
                            strength() {
                                const p = this.passwordTouched ? (this.password || '') : '';
                                if (!p) return 0;
                                let level = 0;
                                if (p.length >= 8) level++;
                                if (p.length >= 12) level++;
                                if (/[A-Z]/.test(p) && /[a-z]/.test(p)) level++;
                                if (/\d/.test(p) && /[^A-Za-z0-9]/.test(p)) level++;
                                return level;
                            },
                            strengthLabel() {
                                if (!this.passwordTouched || !this.password) return @js($createMode ? 'Use at least 12 characters.' : 'Leave blank or use at least 12 characters.');
                                return ['Use at least 12 characters.', 'Weak', 'Fair', 'Good', 'Strong'][this.strength()];
                            }
                        }"
                        x-on:user-editor-generated-password.window="password = $wire.newPassword; confirmation = $wire.newPasswordConfirmation; passwordTouched = true; confirmationTouched = true; dirty = true"
                    >
                        <header class="ft-user-editor-section-head">
                            <div>
                                <h2>Set password</h2>
                                <p>{{ $createMode ? 'Create a secure password for the new user.' : 'Leave both fields blank to keep the user’s current password.' }}</p>
                            </div>
                            <span class="ft-user-editor-section-icon">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 11V8a5 5 0 0 1 10 0v3M6 11h12a2 2 0 0 1 2 2v6H4v-6a2 2 0 0 1 2-2Z"/></svg>
                            </span>
                        </header>
                        <div class="ft-user-editor-section-body">
                            <div class="ft-user-editor-fields">
                                <div class="field">
                                    <label for="ft-edit-password">{{ $createMode ? 'Password *' : 'New password' }} @unless($createMode)<span>Optional</span>@endunless</label>
                                    <div class="ft-user-editor-password-wrap">
                                        <input id="ft-edit-password" data-user-password-field x-model="password" x-on:focus="passwordFocused = true" x-on:blur="passwordFocused = false" x-on:keydown="passwordTouched = true" x-on:paste="passwordTouched = true" x-on:input.stop="syncPassword($event.target.value)" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" data-1p-ignore data-lpignore="true">
                                        <div class="ft-user-editor-password-tools">
                                            <button type="button" wire:click="generatePassword" x-on:click="passwordTouched = false; confirmationTouched = false; dirty = true">Generate</button>
                                            <button type="button" x-on:click="showPassword = !showPassword" x-text="showPassword ? 'Hide' : 'Show'"></button>
                                        </div>
                                    </div>
                                    <div class="ft-user-editor-strength" :data-level="strength()"><i></i><i></i><i></i><i></i></div>
                                    <div class="ft-user-editor-password-feedback"><span x-text="strengthLabel()"></span><span>12+ chars recommended</span></div>
                                    @error('newPassword')<div class="validation-error">{{ $message }}</div>@enderror
                                </div>

                                <div class="field">
                                    <label for="ft-edit-password-confirmation">{{ $createMode ? 'Confirm password *' : 'Confirm new password' }} @unless($createMode)<span>Optional</span>@endunless</label>
                                    <div class="ft-user-editor-password-wrap">
                                        <input id="ft-edit-password-confirmation" data-user-password-field x-model="confirmation" x-on:focus="confirmationFocused = true" x-on:blur="confirmationFocused = false" x-on:keydown="confirmationTouched = true" x-on:paste="confirmationTouched = true" x-on:input.stop="syncConfirmation($event.target.value)" :type="showConfirmation ? 'text' : 'password'" autocomplete="new-password" data-1p-ignore data-lpignore="true">
                                        <div class="ft-user-editor-password-tools">
                                            <button type="button" x-on:click="showConfirmation = !showConfirmation" x-text="showConfirmation ? 'Hide' : 'Show'"></button>
                                        </div>
                                    </div>
                                    {{-- Password mismatch is intentionally server-validated only.
                                         Browser/password-manager autofill must never create a false warning on page load. --}}
                                    @error('newPasswordConfirmation')<div class="validation-error">{{ $message }}</div>@enderror
                                </div>
                            </div>

                            @unless($createMode)
                                <label class="ft-user-editor-check">
                                    <input type="checkbox" wire:model="signOutSessions">
                                    <span>Sign the user out of other existing sessions when the password changes.</span>
                                </label>
                            @endunless
                        </div>
                    </section>

                    <div class="ft-user-editor-action-bar">
                        <span class="ft-user-editor-save-state" :class="dirty ? 'is-dirty' : ''">
                            <span x-show="dirty" x-cloak>Unsaved changes</span>
                            <span x-show="!dirty" x-cloak>{{ $saveMessage !== '' ? $saveMessage : 'No unsaved changes' }}</span>
                        </span>
                        <div class="ft-user-editor-actions">
                            @if($profileMode)
                                <button class="ft-user-editor-button is-cancel" type="button" wire:click="cancelEditing" x-on:click="dirty = false" wire:loading.attr="disabled" wire:target="cancelEditing">Cancel</button>
                            @else
                                <a class="ft-user-editor-button is-cancel" href="{{ $cancelUrl }}" wire:navigate>Cancel</a>
                            @endif
                            <button class="ft-user-editor-button is-save" type="submit" disabled :disabled="!dirty" wire:loading.attr="disabled" wire:target="saveChanges,profileImage">
                                <span wire:loading.remove wire:target="saveChanges">{{ $createMode ? 'Create user' : 'Save changes' }}</span>
                                <span wire:loading wire:target="saveChanges">{{ $createMode ? 'Creating…' : 'Saving…' }}</span>
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </form>
</div>
