<?php

namespace App\Livewire\UserEditor;

use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\Role;
use App\Models\User;
use App\Services\AdminService;
use App\Services\FilterOptionService;
use App\Services\SetupContext;
use App\Services\UserEditorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use WithFileUploads;

    public ?int $userId = null;
    public string $name = '';
    public string $position = '';
    public string $email = '';
    public string $wechatId = '';
    public string $phone = '';
    public array $roleIds = [];
    public ?int $departmentId = null;
    public string $businessUnit = 'both';
    public string $accountStatus = 'active';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';
    public bool $signOutSessions = true;
    public $profileImage = null;
    public string $saveMessage = '';

    // Profile route starts in read-only mode. Administration opens directly in edit mode.
    public bool $profileMode = false;
    public bool $createMode = false;
    public bool $isEditing = true;
    public bool $canManageAccess = false;
    public bool $targetIsSuperAdmin = false;
    public bool $returnToAdministration = false;

    // Render-refactor data: load these only when entering the page/edit mode, not on every render.
    public array $roleOptions = [];
    public array $departmentOptions = [];
    public string $profileImageUrl = '';
    public string $lastActiveLabel = 'No recent session';
    public int $openTasks = 0;
    public string $createdLabel = '—';
    public string $userReference = '';
    public string $cancelUrl = '';

    public function mount(?int $userId = null, bool $profileMode = false, bool $createMode = false): void
    {
        $this->userId = $userId;
        $this->profileMode = $profileMode;
        $this->createMode = $createMode;
        $this->isEditing = $createMode || ! $profileMode;

        $actor = auth()->user();
        $service = app(UserEditorService::class);
        $this->canManageAccess = $service->canManageAccess($actor);

        if ($this->createMode) {
            abort_unless($this->canManageAccess, 403);
            $this->returnToAdministration = true;
            $this->cancelUrl = route('administration', ['tab' => 'users']);
            $this->targetIsSuperAdmin = false;
            $this->businessUnit = 'both';
            $this->accountStatus = 'active';
            $this->lastActiveLabel = 'Not yet';
            $this->openTasks = 0;
            $this->createdLabel = 'On save';
            $this->userReference = 'NEW';
            $this->profileImageUrl = '';
            $this->loadAccessOptions();
            return;
        }

        abort_unless($this->userId !== null, 404);
        $target = $this->targetUser();
        abort_unless($service->canEdit($actor, $target), 403);

        $this->targetIsSuperAdmin = $target->isSuperAdmin();
        $this->returnToAdministration = ! $profileMode && (
            request()->query('from') === 'administration'
            || (auth()->id() !== $target->id && $this->canManageAccess)
        );
        $this->cancelUrl = $this->returnToAdministration
            ? route('administration', ['tab' => 'users'])
            : route('profile');

        $this->loadTargetIntoForm($target);
        $this->loadStaticFacts($target);
        $this->loadAccessOptions($target);
    }

    public function enableEditing(): void
    {
        abort_unless($this->profileMode && auth()->id() === $this->userId, 403);

        $target = $this->targetUser();
        $this->loadTargetIntoForm($target);
        $this->loadAccessOptions($target);
        $this->isEditing = true;
        $this->saveMessage = '';
        $this->resetValidation();
        $this->dispatch('user-editor-editing-enabled');
    }

    public function cancelEditing(): void
    {
        abort_unless($this->profileMode && auth()->id() === $this->userId, 403);

        $target = $this->targetUser();
        $this->reset(['newPassword', 'newPasswordConfirmation', 'profileImage']);
        $this->loadTargetIntoForm($target);
        $this->loadStaticFacts($target);
        $this->isEditing = false;
        $this->saveMessage = '';
        $this->resetValidation();
        $this->dispatch('user-editor-editing-cancelled');
    }

    public function updatedProfileImage(): void
    {
        abort_unless($this->isEditing, 403);

        $this->validateOnly('profileImage', [
            'profileImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:250'],
        ], [
            'profileImage.max' => 'The profile image must not be larger than 250 KB.',
        ]);
    }

    public function generatePassword(): void
    {
        abort_unless($this->isEditing, 403);

        $password = Str::password(16);
        $this->newPassword = $password;
        $this->newPasswordConfirmation = $password;
        $this->dispatch('user-editor-generated-password');
    }

    public function saveChanges(): void
    {
        abort_unless($this->isEditing, 403);

        $actor = auth()->user();
        $service = app(UserEditorService::class);
        $canManageAccess = $service->canManageAccess($actor);
        $target = $this->createMode ? null : $this->targetUser();

        if ($this->createMode) {
            abort_unless($canManageAccess, 403);
        } else {
            abort_unless($target && $service->canEdit($actor, $target), 403);
        }

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', $this->createMode ? Rule::unique('users', 'email') : Rule::unique('users', 'email')->ignore($target->id)],
            'wechatId' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:60'],
            'newPassword' => $this->createMode ? ['required', 'string', 'min:12'] : ['nullable', 'string', 'min:12'],
            'newPasswordConfirmation' => $this->createMode ? ['required', 'same:newPassword'] : ['required_with:newPassword', 'same:newPassword'],
            'signOutSessions' => ['boolean'],
            'profileImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:250'],
        ];

        if ($canManageAccess) {
            $rules += [
                'roleIds' => ['required', 'array', 'min:1'],
                'roleIds.*' => ['distinct', Rule::exists('roles', 'id')->where('workspace_id', app(SetupContext::class)->workspaceId())],
                'departmentId' => ['nullable', 'exists:departments,id'],
                'businessUnit' => ['required', Rule::in(['iid', 'nep', 'both'])],
                'accountStatus' => ['required', Rule::in(['active', 'inactive', 'suspended'])],
            ];
        }

        $this->validate($rules, [
            'newPasswordConfirmation.same' => 'The password confirmation does not match.',
            'newPassword.min' => 'Use at least 12 characters for the password.',
            'profileImage.max' => 'The profile image must not be larger than 250 KB.',
        ]);

        $payload = [
            'name' => $this->name,
            'position' => $this->position,
            'email' => $this->email,
            'wechat_id' => $this->wechatId,
            'phone' => $this->phone,
            'password' => $this->newPassword,
            'sign_out_sessions' => $this->signOutSessions,
        ];

        if ($canManageAccess) {
            $payload += [
                'role_ids' => array_values($this->roleIds),
                'department_id' => $this->departmentId,
                'business_unit' => $this->businessUnit,
                'account_status' => $this->accountStatus,
            ];
        }

        if ($this->createMode) {
            $target = $service->create($payload, $actor);
        } else {
            $service->update($target, $payload, $actor);
        }

        if ($this->profileImage) {
            $service->updateProfileImage($target, $this->profileImage, $actor);
        }

        $this->reset(['newPassword', 'newPasswordConfirmation', 'profileImage']);

        if ($this->returnToAdministration || $this->createMode) {
            session()->flash('success', $this->createMode ? 'User created successfully.' : 'User updated successfully.');
            $this->redirectRoute('administration', ['tab' => 'users'], navigate: true);
            return;
        }

        $this->saveMessage = 'Changes saved just now';

        $target = $this->targetUser();
        $this->loadTargetIntoForm($target);
        $this->loadStaticFacts($target);

        if ($this->profileMode) {
            $this->isEditing = false;
        }

        $this->dispatch('user-editor-saved');
    }

    public function render()
    {
        // Intentionally query-free: static editor data is loaded on mount/edit/save only.
        return view('livewire.user-editor.index');
    }

    private function loadTargetIntoForm(?User $target = null): void
    {
        $target ??= $this->targetUser();
        $actor = auth()->user();
        $service = app(UserEditorService::class);
        abort_unless($service->canEdit($actor, $target), 403);

        $this->name = $target->name;
        $this->position = app(AdminService::class)->positionFor($target) ?? '';
        $this->email = $target->email;
        $this->wechatId = (string) ($target->wechat_id ?? '');
        $this->phone = (string) ($target->phone ?? '');
        $this->roleIds = $target->assignedRoleIds();
        $this->departmentId = $target->department_id;
        $this->businessUnit = $service->businessUnit($target);
        $this->accountStatus = $service->accountStatus($target);

        // Password fields are never hydrated from an existing user and must not survive
        // Livewire navigation/re-entry into the editor.
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';

        $this->targetIsSuperAdmin = $target->isSuperAdmin();
        $this->profileImageUrl = $this->profileUrl($target);
    }

    private function loadAccessOptions(?User $target = null): void
    {
        if (! $this->canManageAccess) {
            abort_unless($target, 403);
            $this->roleOptions = $target->assignedRoles()
                ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
                ->values()->all();
            $this->departmentOptions = $target->department
                ? [['id' => $target->department->id, 'name' => $target->department->name]]
                : [];
            return;
        }

        $workspaceId = app(SetupContext::class)->workspaceId();

        $this->roleOptions = Role::query()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($target) {
                $query->where('is_active', true);
                $assignedRoleIds = $target?->assignedRoleIds() ?? [];
                if ($assignedRoleIds !== []) {
                    $query->orWhereIn('id', $assignedRoleIds);
                }
            })
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])
            ->all();

        $this->departmentOptions = app(FilterOptionService::class)
            ->options(
                auth()->user(),
                'departments',
                'user-editor',
                '',
                $target?->department_id,
                FilterOptionService::COMPACT_PER_PAGE,
            )
            ->values()
            ->all();
    }

    public function setDepartmentSelection(string $property, string $value): void
    {
        abort_unless($property === 'departmentId', 422);
        abort_unless($this->canManageAccess, 403);
        $this->departmentId = $value !== '' ? (int) $value : null;
    }

    private function loadStaticFacts(User $target): void
    {
        $lastActiveAt = null;
        if (Schema::hasTable('sessions')) {
            $timestamp = DB::table('sessions')->where('user_id', $target->id)->max('last_activity');
            if ($timestamp) {
                $lastActiveAt = Carbon::createFromTimestamp((int) $timestamp);
            }
        }

        $this->lastActiveLabel = $lastActiveAt ? $lastActiveAt->diffForHumans() : 'No recent session';
        $this->openTasks = $target->assignedTasks()->whereNull('completed_at')->count();
        $this->createdLabel = \App\Support\UserLocalTime::format($target->created_at, 'M j, Y');
        $this->userReference = 'USR-'.str_pad((string) $target->id, 4, '0', STR_PAD_LEFT);
        $this->profileImageUrl = $this->profileUrl($target);
    }

    private function profileUrl(User $target): string
    {
        return (string) ($target->profileImageUrl() ?? '');
    }

    private function targetUser(): User
    {
        abort_unless($this->userId !== null, 404);

        return User::query()->with(['role:id,name,slug,is_active', 'roles:id,name,slug,is_active', 'department:id,name'])->findOrFail($this->userId);
    }
}
