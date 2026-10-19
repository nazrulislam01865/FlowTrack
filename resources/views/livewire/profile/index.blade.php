<div class="ft-profile-page" data-ft-feedback-scope="form">
    <x-ui.page-head title="Edit Profile" subtitle="Update your account details, profile photo and security settings" />

    @if(session('success'))
        <div class="flash ft-profile-flash">{{ session('success') }}</div>
    @endif

    <div class="ft-profile-layout ft-profile-edit-layout">
        <div class="ft-profile-main-column">
            <section class="card ft-profile-main-card">
                <div class="ft-profile-hero ft-profile-edit-hero">
                    <div class="ft-profile-avatar-wrap">
                        @php
                            $profilePreviewUrl = $profileImage && str_starts_with((string) $profileImage->getMimeType(), 'image/') ? $profileImage->temporaryUrl() : null;
                        @endphp

                        @if($profilePreviewUrl)
                            <span class="avatar ft-profile-avatar ft-profile-avatar-preview">
                                <img src="{{ $profilePreviewUrl }}" alt="Profile image preview">
                            </span>
                        @else
                            <x-ui.avatar :user="$user" :name="$user->name" :size="96" class="ft-profile-avatar" />
                        @endif
                    </div>

                    <div class="ft-profile-identity-copy">
                        <div class="ft-profile-eyebrow">Profile photo</div>
                        <h2>{{ $user->name }}</h2>

                        <div class="ft-profile-meta-line">
                            @if($position)<span>{{ $position }}</span>@endif
                            @php($profileRoles = $user->assignedRoles()->pluck('name'))
                            @if($profileRoles->isNotEmpty())<span>{{ $profileRoles->join(' · ') }}</span>@endif
                            @if($user->department?->name)<span>{{ $user->department->name }}</span>@endif
                        </div>

                        <div class="ft-profile-photo-actions">
                            <label class="ft-profile-upload-button">
                                <input type="file" wire:model="profileImage" accept="image/jpeg,image/png,image/webp">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0 4 4m-4-4-4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4" /></svg>
                                <span>{{ $user->profile_image_path ? 'Choose new photo' : 'Choose photo' }}</span>
                            </label>

                            @if($profileImage)
                                <button class="primary ft-profile-photo-save" type="button" wire:click="saveProfileImage" wire:loading.attr="disabled" wire:target="saveProfileImage,profileImage">
                                    <span wire:loading.remove wire:target="saveProfileImage">Use this photo</span>
                                    <span wire:loading wire:target="saveProfileImage">Saving…</span>
                                </button>
                            @endif

                            @if($user->profile_image_path)
                                <button class="ft-profile-photo-remove" type="button" wire:click="removeProfileImage" wire:confirm="Remove your profile image?">Remove photo</button>
                            @endif
                        </div>

                        <div class="ft-profile-photo-help">JPG, PNG or WebP · Maximum 250 KB · Square images work best.</div>
                        @error('profileImage')<div class="validation-error ft-profile-inline-error">{{ $message }}</div>@enderror
                        <div wire:loading wire:target="profileImage" class="ft-profile-photo-help">Preparing preview…</div>
                    </div>
                </div>

                <div class="ft-profile-section ft-profile-edit-section">
                    <div class="ft-profile-section-heading">
                        <span class="ft-profile-section-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0M12 13a5 5 0 1 0 0-10 5 5 0 0 0 0 10Z" /></svg>
                        </span>
                        <div>
                            <h3>Account information</h3>
                            <p>These details identify you across Jobs, Tasks, comments and notifications.</p>
                        </div>
                    </div>

                    <div class="ft-profile-form-grid">
                        <div class="field">
                            <label for="profile-name">Full name</label>
                            <input id="profile-name" wire:model="name" autocomplete="name" placeholder="Your full name">
                            @error('name')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>

                        <div class="field">
                            <label for="profile-email">Email address</label>
                            <input id="profile-email" wire:model="email" type="email" autocomplete="email" placeholder="name@company.com">
                            <small class="ft-profile-field-help">Used for sign-in and account-related communication.</small>
                            @error('email')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>

                        <div class="field ft-profile-language-field">
                            <label for="profile-language">Preferred language</label>
                            <select id="profile-language" wire:model="locale">
                                <option value="en">English</option>
                                <option value="zh">Simplified Chinese</option>
                            </select>
                            @error('locale')<div class="validation-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="ft-profile-main-footer">
                    <div class="ft-profile-save-copy">
                        <strong>Personal changes</strong>
                        <span>Name, email and language are updated together.</span>
                    </div>
                    <button class="primary ft-profile-save-button" type="button" wire:click="saveProfile" wire:loading.attr="disabled" wire:target="saveProfile">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5 9.5 17 19 7.5" /></svg>
                        <span wire:loading.remove wire:target="saveProfile">Save profile</span>
                        <span wire:loading wire:target="saveProfile">Saving…</span>
                    </button>
                </div>
            </section>
        </div>

        <aside class="ft-profile-side-column">
            <section class="card ft-profile-side-card ft-profile-company-card">
                <div class="ft-profile-side-heading ft-profile-company-heading">
                    <span class="ft-profile-section-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 10h1m4 0h1m-6 4h1m4 0h1m-5 7v-3h4v3" /></svg>
                    </span>
                    <div>
                        <h3>Company details</h3>
                        <p>Managed by your administrator.</p>
                    </div>
                </div>

                <div class="ft-profile-company-grid ft-profile-company-grid-side">
                    <div class="ft-profile-company-item"><span>Position</span><strong>{{ $position ?: 'Not assigned' }}</strong></div>
                    <div class="ft-profile-company-item"><span>Roles</span><strong>{{ $user->assignedRoles()->pluck('name')->join(', ') ?: 'Not assigned' }}</strong></div>
                    <div class="ft-profile-company-item"><span>Department</span><strong>{{ $user->department?->name ?: 'Not assigned' }}</strong></div>
                </div>

                @if($canManageBranding)
                    <a class="ft-profile-admin-link" href="{{ route('administration', ['tab' => 'branding']) }}" wire:navigate>
                        <span>Manage system logo &amp; favicon</span>
                        <span aria-hidden="true">→</span>
                    </a>
                @endif
            </section>

            <section class="card ft-profile-side-card">
                <div class="ft-profile-side-heading">
                    <span class="ft-profile-section-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M7 10V8a5 5 0 0 1 10 0v2m-11 0h12a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z" /></svg>
                    </span>
                    <div>
                        <h3>Change password</h3>
                        <p>Use at least 10 characters and avoid reusing another password.</p>
                    </div>
                </div>

                <div class="field">
                    <label for="current-password">Current password</label>
                    <input id="current-password" wire:model="currentPassword" type="password" autocomplete="current-password" placeholder="Enter current password">
                    @error('currentPassword')<div class="validation-error">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="new-password">New password</label>
                    <input id="new-password" wire:model="newPassword" type="password" autocomplete="new-password" minlength="10" placeholder="At least 10 characters">
                    @error('newPassword')<div class="validation-error">{{ $message }}</div>@enderror
                </div>

                <div class="field">
                    <label for="new-password-confirmation">Confirm new password</label>
                    <input id="new-password-confirmation" wire:model="newPasswordConfirmation" type="password" autocomplete="new-password" minlength="10" placeholder="Repeat new password">
                    @error('newPasswordConfirmation')<div class="validation-error">{{ $message }}</div>@enderror
                </div>

                <button class="secondary ft-profile-password-button" type="button" wire:click="changePassword" wire:loading.attr="disabled" wire:target="changePassword">
                    <span wire:loading.remove wire:target="changePassword">Update password</span>
                    <span wire:loading wire:target="changePassword">Updating…</span>
                </button>
            </section>

            <section class="card ft-profile-side-card ft-profile-notifications-card">
                <div class="ft-profile-side-heading">
                    <span class="ft-profile-section-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
                    </span>
                    <div>
                        <h3>Notification coverage</h3>
                        <p>Current FlowTrack events that can notify your account.</p>
                    </div>
                </div>

                <div class="ft-profile-notification-list ft-profile-notification-summary">
                    @foreach($notificationPreferences as $i => [$title, $description])
                        <div class="ft-profile-notification-row is-summary">
                            <span class="ft-profile-notification-check is-on" aria-hidden="true"></span>
                            <span class="ft-profile-notification-copy"><strong>{{ $title }}</strong><small>{{ $description }}</small></span>
                        </div>
                    @endforeach
                </div>
                <a class="ft-profile-notification-link" href="{{ route('notifications') }}" wire:navigate>Open notifications →</a>
            </section>

            <section class="card ft-profile-signout-card">
                <div><strong>Finished for now?</strong><span>Sign out securely from this device.</span></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="danger-btn ft-profile-signout-button" type="submit">Sign out</button>
                </form>
            </section>
        </aside>
    </div>
</div>
