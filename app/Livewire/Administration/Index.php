<?php

namespace App\Livewire\Administration;

use App\Livewire\Concerns\HandlesInlineEdits;
use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;

use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\AdminService;
use App\Services\BrandingService;
use App\Services\SetupContext;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use HandlesInlineEdits;
    use WithFileUploads;
    use WithPagination;
    public string $tab = 'dashboard';
    public ?int $selectedRoleId = null;

    // CHANGE 2026-08-24: Users & Assignments search state.
    public string $userSearch = '';

    public $logoUpload = null;
    public $faviconUpload = null;
    public string $brandingName = '';
    public array $systemFooter = [];
    public string $footerCopyright = '';
    public array $footerLinks = [];

    public bool $showRoleModal = false;
    public ?int $editingRoleId = null;

    // Friendly confirmation state for disabling module email delivery.
    public ?string $pendingEmailServiceModule = null;
    public string $roleName = '';
    public string $roleCode = '';
    public string $roleDescription = '';
    public string $roleDefaultScope = 'assigned_jobs';
    public bool $roleActive = true;

    public function mount(): void
    {
        $requestedTab = (string) request()->query('tab', '');
        if (in_array($requestedTab, ['dashboard','roles','matrix','users','audit','security','settings','branding'], true)) {
            $this->tab = $requestedTab;
        }


        if ($this->tab === 'branding') $this->loadSupplierFooter();
        if ($this->tab !== 'branding') {
            $this->selectedRoleId = Role::where('slug', 'operations-manager')->value('id') ?: Role::where('slug', '!=', 'super-admin')->value('id') ?: Role::value('id');
        }
    }

    public function setTab(string $tab): void
    {
        $allowed = ['dashboard','roles','matrix','users','audit','security','settings','branding'];
        $this->tab = in_array($tab, $allowed, true) ? $tab : 'dashboard';
        if ($this->tab === 'branding') $this->loadSupplierFooter();
    }

    // CHANGE 2026-08-24: keep user search pagination correct while typing.
    public function updatedUserSearch(): void
    {
        $this->resetPage('usersPage');
    }

    public function clearUserSearch(): void
    {
        $this->userSearch = '';
        $this->resetPage('usersPage');
    }

    public function selectRole(int $roleId): void
    {
        $role = Role::query()
            ->where('workspace_id', app(SetupContext::class)->workspaceId())
            ->findOrFail($roleId);

        $this->selectedRoleId = $role->id;
        $this->tab = 'matrix';
    }

    public function selectMatrixRole(int $roleId): void
    {
        $role = Role::query()
            ->where('workspace_id', app(SetupContext::class)->workspaceId())
            ->findOrFail($roleId);

        $this->selectedRoleId = $role->id;
    }

    public function openUser(?int $id = null): void
    {
        if ($id) {
            $this->redirectRoute('users.edit', ['user' => $id, 'from' => 'administration'], navigate: true);
            return;
        }

        $this->redirectRoute('users.create', navigate: true);
    }

    public function deleteUser(int $userId): void
    {
        app(AdminService::class)->deleteUser(User::findOrFail($userId), auth()->user());
        session()->flash('success', 'User deleted.');
    }

    public function openRole(?int $id = null): void
    {
        $this->resetValidation();
        $this->editingRoleId = $id;
        if ($id) {
            $role = Role::findOrFail($id);
            $this->roleName = $role->name;
            $this->roleCode = (string) $role->code;
            $this->roleDescription = (string) $role->description;
            $this->roleDefaultScope = (string) ($role->default_scope === 'selected_clients' ? 'assigned_jobs' : $role->default_scope);
            $this->roleActive = (bool) $role->is_active;
        } else {
            $this->reset(['roleName','roleCode','roleDescription']);
            $this->roleDefaultScope = 'assigned_jobs';
            $this->roleActive = true;
        }
        $this->showRoleModal = true;
    }

    public function closeRole(): void
    {
        $this->showRoleModal = false;
        $this->resetValidation();
    }

    public function saveRole(): void
    {
        $data = $this->validate([
            'roleName' => ['required','string','max:255'],
            'roleCode' => ['nullable','string','max:80'],
            'roleDescription' => ['nullable','string','max:2000'],
            'roleDefaultScope' => ['required','in:none,own_records,assigned_jobs,department,all_records'],
            'roleActive' => ['boolean'],
        ]);
        $role = app(AdminService::class)->saveRole([
            'name' => $data['roleName'], 'code' => $data['roleCode'], 'description' => $data['roleDescription'],
            'default_scope' => $data['roleDefaultScope'], 'is_active' => $data['roleActive'],
        ], $this->editingRoleId, auth()->user());
        $this->selectedRoleId = $role->id;
        $this->showRoleModal = false;
        $this->resetValidation();
        if (!$this->editingRoleId) $this->tab = 'matrix';
        session()->flash('success', $role->name.($this->editingRoleId ? ' updated.' : ' created. Review its permissions below.'));
        $this->editingRoleId = null;
    }

    public function deleteRole(int $roleId): void
    {
        $workspaceId = app(SetupContext::class)->workspaceId();
        $role = Role::query()->where('workspace_id', $workspaceId)->findOrFail($roleId);
        $roleName = $role->name;

        $affectedUsers = app(AdminService::class)->deleteRole($role, auth()->user());

        if ((int) $this->selectedRoleId === $roleId) {
            $this->selectedRoleId = Role::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('is_system')
                ->orderBy('name')
                ->value('id');
        }

        if ((int) $this->editingRoleId === $roleId) {
            $this->closeRole();
            $this->editingRoleId = null;
        }

        session()->flash(
            'success',
            $roleName.' was permanently deleted. '.($affectedUsers === 1
                ? '1 user role assignment was removed.'
                : $affectedUsers.' user role assignments were removed.'),
        );
    }

    public function toggleMatrixAction(int $roleId, string $module, string $action): void
    {
        app(AdminService::class)->toggleMatrixAction(Role::findOrFail($roleId), $module, $action, auth()->user());
    }

    #[Renderless]
    public function setMatrixAction(int $roleId, string $module, string $action, bool $enabled): array
    {
        $result = $this->persistInlineEdit('permission', function () use ($roleId, $module, $action, $enabled) {
            app(AdminService::class)->setMatrixAction(Role::findOrFail($roleId), $module, $action, $enabled, auth()->user());
        });

        return $this->withCanonicalMatrixState($result, $roleId, $module);
    }

    #[Renderless]
    public function setModuleScope(int $roleId, string $module, string $scope): array
    {
        $result = $this->persistInlineEdit('record scope', function () use ($roleId, $module, $scope) {
            app(AdminService::class)->setScope(Role::findOrFail($roleId), $module, $scope, auth()->user());
        });

        return $this->withCanonicalMatrixState($result, $roleId, $module);
    }

    private function withCanonicalMatrixState(array $result, int $roleId, string $module): array
    {
        if (($result['ok'] ?? false) !== true) return $result;

        $row = RoleModuleAccess::query()
            ->where('role_id', $roleId)
            ->where('module_code', $module)
            ->first(['actions', 'record_scope']);

        return array_merge($result, [
            'roleId' => $roleId,
            'module' => $module,
            'actions' => array_values($row?->actions ?: []),
            'recordScope' => (string) ($row?->record_scope ?: 'none'),
        ]);
    }

    #[Renderless]
    public function assignRole(int $userId, int $roleId): array
    {
        return $this->persistInlineEdit('user role', function () use ($userId, $roleId) {
            app(AdminService::class)->assignRole(User::findOrFail($userId), Role::findOrFail($roleId), auth()->user());
        });
    }

    public function toggleUserActive(int $userId): void
    {
        app(AdminService::class)->toggleUserActive(User::findOrFail($userId), auth()->user());
    }

    public function toggleSecurity(string $code): void
    {
        app(AdminService::class)->toggleSecurity($code, auth()->user());
    }

    public function toggleEmailService(string $module): void
    {
        $enabled = app(AdminService::class)->toggleEmailService($module, auth()->user());
        session()->flash('success', ucfirst($module).' email service '.($enabled ? 'enabled' : 'disabled').'.');
    }

    public function requestDisableEmailService(string $module): void
    {
        $setting = collect(app(AdminService::class)->emailServiceSettings())
            ->firstWhere('module', $module);

        abort_unless($setting, 422, 'Unknown email service.');

        // If another administrator already disabled it, simply refresh the UI
        // instead of opening a stale confirmation dialog.
        if (! (bool) ($setting['enabled'] ?? false)) {
            $this->pendingEmailServiceModule = null;
            return;
        }

        $this->pendingEmailServiceModule = $module;
    }

    public function cancelDisableEmailService(): void
    {
        $this->pendingEmailServiceModule = null;
    }

    public function confirmDisableEmailService(): void
    {
        $module = $this->pendingEmailServiceModule;
        abort_unless(filled($module), 422, 'No email service is waiting for confirmation.');

        $enabled = app(AdminService::class)->setEmailService($module, false, auth()->user());
        $this->pendingEmailServiceModule = null;

        session()->flash('success', ucfirst($module).' email service '.($enabled ? 'enabled' : 'disabled').'.');
    }

    public function setEmailService(string $module, bool $enabled): void
    {
        $enabled = app(AdminService::class)->setEmailService($module, $enabled, auth()->user());
        session()->flash('success', ucfirst($module).' email service '.($enabled ? 'enabled' : 'disabled').'.');
    }

    public function toggleRule(int $id): void { app(AdminService::class)->toggleRule($id); }

    private function loadSupplierFooter(): void
    {
        $branding = app(BrandingService::class)->current();
        $this->brandingName = (string) $branding['name'];
        $this->systemFooter = $branding['system_footer'];
        $footer = $branding['supplier_portal_footer'];
        $this->footerCopyright = $footer['copyright'];
        $this->footerLinks = $footer['links'];
    }

    public function saveBrandingName(): void
    {
        $this->validate(['brandingName' => ['required', 'string', 'max:255']]);
        app(BrandingService::class)->saveName($this->brandingName, auth()->user());
        session()->flash('success', 'System name updated.');
        $this->redirectRoute('administration', ['tab' => 'branding']);
    }

    public function saveSystemFooter(): void
    {
        $this->validate([
            'systemFooter' => ['required', 'array:enabled,copyright,owner,website_url,suffix'],
            'systemFooter.enabled' => ['required', 'boolean'],
            'systemFooter.copyright' => ['required', 'string', 'max:80'],
            'systemFooter.owner' => ['required', 'string', 'max:120'],
            'systemFooter.website_url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'systemFooter.suffix' => ['nullable', 'string', 'max:120'],
        ]);
        app(BrandingService::class)->saveSystemFooter($this->systemFooter, auth()->user());
        session()->flash('success', 'Application footer updated.');
        $this->redirectRoute('administration', ['tab' => 'branding']);
    }

    public function saveSupplierFooter(): void
    {
        abort_unless(auth()->user()?->is_active && app(AccessControlService::class)->isAdministrator(auth()->user()), 403);
        $this->validate([
            'footerCopyright' => ['nullable', 'string', 'max:255'],
            'footerLinks' => ['required', 'array:privacy,terms,help'],
            'footerLinks.*.enabled' => ['required', 'boolean'],
            'footerLinks.*.label' => ['required', 'string', 'max:80'],
            'footerLinks.*.url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ]);
        app(BrandingService::class)->saveSupplierFooter([
            'copyright' => $this->footerCopyright,
            'links' => $this->footerLinks,
        ], auth()->user());
        session()->flash('success', 'Supplier portal footer saved. It will synchronize via the existing branding bridge.');
    }

    public function saveLogo()
    {
        $this->validate([
            'logoUpload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        app(BrandingService::class)->saveLogo($this->logoUpload, auth()->user());
        $this->reset('logoUpload');
        session()->flash('success', 'System logo updated.');

        return $this->redirectRoute('administration', ['tab' => 'branding']);
    }

    public function saveFavicon()
    {
        $this->validate([
            'faviconUpload' => ['required', 'file', 'mimes:ico,png,jpg,jpeg,webp', 'max:1024'],
        ]);

        app(BrandingService::class)->saveFavicon($this->faviconUpload, auth()->user());
        $this->reset('faviconUpload');
        session()->flash('success', 'Favicon updated.');

        return $this->redirectRoute('administration', ['tab' => 'branding']);
    }

    public function removeLogo()
    {
        app(BrandingService::class)->removeLogo(auth()->user());
        $this->reset('logoUpload');
        session()->flash('success', 'System logo removed.');

        return $this->redirectRoute('administration', ['tab' => 'branding']);
    }

    public function removeFavicon()
    {
        app(BrandingService::class)->removeFavicon(auth()->user());
        $this->reset('faviconUpload');
        session()->flash('success', 'Favicon removed.');

        return $this->redirectRoute('administration', ['tab' => 'branding']);
    }

    public function render()
    {
        $service = app(AdminService::class);

        return view('livewire.administration.index', match ($this->tab) {
            'roles' => $this->rolesPageData($service),
            'matrix' => $this->matrixPageData($service),
            'users' => $this->usersPageData($service),
            'audit' => ['auditLog' => $service->auditLog()],
            'security' => ['securitySettings' => $service->securitySettings()],
            'settings' => ['emailServiceSettings' => $service->emailServiceSettings()],
            'branding' => ['branding' => app(BrandingService::class)->current()],
            default => $this->dashboardPageData($service),
        });
    }

    private function dashboardPageData(AdminService $service): array
    {
        return [
            'summary' => $service->summary(),
            'roles' => $service->roles(),
        ];
    }

    private function rolesPageData(AdminService $service): array
    {
        return ['roles' => $service->roles()];
    }

    private function matrixPageData(AdminService $service): array
    {
        // Keep role switching cheap: load the lightweight role selector once,
        // then fetch permission rows only for the selected role. The previous
        // implementation loaded every role's users + full matrix on each change.
        $roles = $service->roleOptions();
        $selectedRole = $roles->firstWhere('id', $this->selectedRoleId) ?: $roles->first();

        if ($selectedRole) {
            $selectedRole->load(['moduleAccess' => fn ($q) => $q
                ->select(['id', 'role_id', 'module_code', 'record_scope', 'actions'])]);

            if ((int) $this->selectedRoleId !== (int) $selectedRole->id) {
                $this->selectedRoleId = $selectedRole->id;
            }
        }

        return [
            'roles' => $roles,
            'selectedRole' => $selectedRole,
            'modules' => AccessControlService::MODULES,
            'actions' => AccessControlService::ACTIONS,
        ];
    }

    private function usersPageData(AdminService $service): array
    {
        return [
            // CHANGE 2026-08-24: search stays server-side and workspace scoped.
            'users' => $service->paginateUsers(10, 'usersPage', $this->userSearch),
        ];
    }
}
