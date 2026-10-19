<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

class AccessControlService
{
    private array $accessCache = [];
    private array $accessRowsCache = [];
    private array $activeRolesCache = [];
    public const ACTIONS = ['view','create','edit_own','edit_all','delete','assign','link','export','manage'];

    public const RECORD_SCOPES = ['none','own_records','assigned_jobs','department','all_records'];

    /** Modules that are actually implemented and enforced by FlowTrack today. */
    public const MODULES = [
        'dashboard' => ['name' => 'Dashboard', 'group' => 'General'],
        'reports' => ['name' => 'Report', 'group' => 'General'],
        'notifications' => ['name' => 'Notifications', 'group' => 'General'],
        'clients' => ['name' => 'Clients', 'group' => 'Commercial'],
        'inquiries' => ['name' => 'Inquiries', 'group' => 'Commercial'],
        'jobs' => ['name' => 'Orders', 'group' => 'Commercial'],
        'catalog_products' => ['name' => 'Products', 'group' => 'Catalogue'],
        'product_categories' => ['name' => 'Product Categories', 'group' => 'Catalogue'],
        'suppliers' => ['name' => 'Suppliers', 'group' => 'Catalogue'],
        'supplier_pricing' => ['name' => 'Supplier Prices (confidential)', 'group' => 'Catalogue'],
        'finance' => ['name' => 'Finance', 'group' => 'Commercial'],
        'tasks' => ['name' => 'Tasks & Checklists', 'group' => 'Operations'],
        'documents' => ['name' => 'Documents', 'group' => 'Records'],
        'document_archive' => ['name' => 'Document Archive', 'group' => 'Records'],
        'workflow' => ['name' => 'Workflow Setup', 'group' => 'Administration'],
        'taskpacks' => ['name' => 'Task Pack Setup', 'group' => 'Administration'],
        'masterdata' => ['name' => 'Master Data', 'group' => 'Administration'],
    ];

    /**
     * Every matrix action is selectable for every FlowTrack module.
     *
     * The matrix is the role's authoritative capability store: administrators
     * can grant any action without the UI silently disabling a cell. Existing
     * screens/services continue to enforce the actions they actually perform,
     * while additional granted capabilities remain available to any current or
     * future operation that checks that module/action pair.
     */
    public const SUPPORTED_ACTIONS = [
        'dashboard' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'reports' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'notifications' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'clients' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'inquiries' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'jobs' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'catalog_products' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'product_categories' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'suppliers' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'supplier_pricing' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'finance' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'tasks' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'documents' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'document_archive' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'workflow' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'taskpacks' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
        'masterdata' => ['view','create','edit_own','edit_all','delete','assign','link','export','manage'],
    ];

    /** These modules do not have per-record ownership scope. */
    public const UNIVERSAL_RECORD_MODULES = ['dashboard', 'reports', 'notifications', 'clients', 'workflow', 'taskpacks', 'masterdata', 'catalog_products', 'product_categories', 'suppliers', 'supplier_pricing'];

    /** Shared Product/Finance capabilities inherit record visibility from the parent Inquiry/Order. */
    public const PARENT_RECORD_MODULES = ['finance'];

    public const SCOPED_MODULES = ['inquiries', 'jobs', 'tasks', 'documents', 'document_archive'];

    public static function supportedActions(string $module): array
    {
        return self::SUPPORTED_ACTIONS[$module] ?? [];
    }

    public static function supportsAction(string $module, string $action): bool
    {
        if ($action === 'edit') {
            return in_array('edit_own', self::supportedActions($module), true)
                || in_array('edit_all', self::supportedActions($module), true);
        }

        return in_array($action, self::supportedActions($module), true);
    }

    public static function supportsScope(string $module): bool
    {
        // Every matrix row exposes the same record-scope choices. Operational
        // record modules enforce the selected scope directly; shared/setup and
        // parent-scoped modules retain their existing safe visibility semantics
        // while still storing the administrator's selected scope consistently.
        return isset(self::MODULES[$module]);
    }

    private const LEGACY = [
        'dashboard.view' => ['dashboard','view'],
        'jobs.view' => ['jobs','view'],
        'inquiries.view' => ['inquiries','view'],
        'inquiries.create' => ['inquiries','create'],
        'inquiries.update' => ['inquiries','edit'],
        'jobs.create' => ['jobs','create'],
        'jobs.update' => ['jobs','edit'],
        'tasks.view' => ['tasks','view'],
        'tasks.update' => ['tasks','edit'],
        'clients.view' => ['clients','view'],
        'documents.view' => ['documents','view'],
        'reports.view' => ['reports','view'],
        'notifications.view' => ['notifications','view'],
        'workflow.view' => ['workflow','view'],
        'workflow.create' => ['workflow','create'],
        'workflow.update' => ['workflow','edit'],
        'workflow.delete' => ['workflow','delete'],
        'workflow.manage' => ['workflow','manage'],
        'taskpacks.view' => ['taskpacks','view'],
        'taskpacks.create' => ['taskpacks','create'],
        'taskpacks.update' => ['taskpacks','edit'],
        'taskpacks.delete' => ['taskpacks','delete'],
        'taskpacks.manage' => ['taskpacks','manage'],
        'task-pack.manage' => ['taskpacks','manage'],
        'master.view' => ['masterdata','view'],
        'master.create' => ['masterdata','create'],
        'master.update' => ['masterdata','edit'],
        'master.delete' => ['masterdata','delete'],
        'master.manage' => ['masterdata','manage'],
        'catalog-products.view' => ['catalog_products','view'],
        'catalog-products.create' => ['catalog_products','create'],
        'catalog-products.update' => ['catalog_products','edit'],
        'catalog-products.delete' => ['catalog_products','delete'],
        'product-categories.view' => ['product_categories','view'],
        'product-categories.create' => ['product_categories','create'],
        'product-categories.update' => ['product_categories','edit'],
        'product-categories.delete' => ['product_categories','delete'],
        'suppliers.view' => ['suppliers','view'],
        'suppliers.create' => ['suppliers','create'],
        'suppliers.update' => ['suppliers','edit'],
        'suppliers.delete' => ['suppliers','delete'],
        'users.manage' => ['users','manage'],
        'administration.manage' => ['users','manage'],
        'jobs.view-assigned' => ['jobs','view'],
        'jobs.view-all' => ['jobs','view'],
        'jobs.manage-all' => ['jobs','manage'],
        'tasks.view-assigned' => ['tasks','view'],
        'tasks.view-all' => ['tasks','view'],
        'clients.manage' => ['clients','manage'],
        'documents.manage' => ['documents','manage'],
        'financials.view' => ['invoice','view'],
        'workflows.manage' => ['workflow','manage'],
        'master-data.manage' => ['masterdata','manage'],
    ];

    public function isAdministrator(User $user): bool
    {
        if ($user->is_super_admin) return true;

        return $this->activeRoles($user)
            ->contains(fn (Role $role) => in_array($role->slug, ['super-admin','admin','administrator'], true));
    }

    public function can(User $user, string $module, string $action = 'view'): bool
    {
        if (!$user->is_active) return false;
        if ($this->isAdministrator($user)) return true;
        if (!isset(self::MODULES[$module]) || !self::supportsAction($module, $action)) return false;

        $access = $this->access($user, $module);
        if (!$access) return false;
        if (!self::isUniversalRecordModule($module) && !self::isParentRecordModule($module) && $access->record_scope === 'none') return false;
        $actions = $access->actions ?: [];

        if ($action === 'edit') {
            return in_array('edit_all', $actions, true) || in_array('edit_own', $actions, true) || in_array('manage', $actions, true);
        }

        return in_array($action, $actions, true) || in_array('manage', $actions, true);
    }

    public function canPermission(User $user, string $permission): bool
    {
        if (!$user->is_active) return false;
        if ($this->isAdministrator($user)) return true;
        if (isset(self::LEGACY[$permission])) {
            [$module, $action] = self::LEGACY[$permission];
            return $this->can($user, $module, $action);
        }

        if (str_contains($permission, '.')) {
            [$module, $action] = explode('.', $permission, 2);
            $module = match ($module) {
                'master-data' => 'masterdata',
                'workflows' => 'workflow',
                default => $module,
            };
            $action = match ($action) {
                'update' => 'edit',
                default => $action,
            };
            if (isset(self::MODULES[$module])) return $this->can($user, $module, $action);
        }

        $roleIds = $this->activeRoles($user)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($roleIds === []) return false;

        return Role::query()
            ->whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($query) => $query->where('slug', $permission))
            ->exists();
    }

    /**
     * Return all effective record scopes contributed by active assigned roles
     * that grant access to the module. Multiple role scopes are combined as a
     * union by the query-scoping methods below.
     *
     * @return list<string>
     */
    public function scopes(User $user, string $module): array
    {
        if (!$user->is_active) return ['none'];
        if ($this->isAdministrator($user)) return ['all_records'];

        if (self::isUniversalRecordModule($module) || self::isParentRecordModule($module)) {
            return $this->can($user, $module, 'view') ? ['all_records'] : ['none'];
        }

        $scopes = $this->accessRows($user, $module)
            // Record visibility must come only from roles that actually grant
            // View/Manage for this module. A second role that grants only Create,
            // Assign, Export, etc. must never widen another role's visible scope.
            ->filter(fn (RoleModuleAccess $row) => $this->rowGrantsView($row))
            ->pluck('record_scope')
            ->map(fn ($scope) => (string) ($scope ?: 'none'))
            ->map(fn ($scope) => $scope === 'selected_clients' ? 'assigned_jobs' : $scope)
            ->filter(fn ($scope) => in_array($scope, ['none','own_records','assigned_jobs','department','all_records'], true))
            ->unique()
            ->values()
            ->all();

        return $scopes !== [] ? $scopes : ['none'];
    }

    /**
     * Backwards-compatible single-scope summary for UI/legacy callers. Core
     * visibility queries use scopes() so mixed role scopes remain a true union.
     */
    public function scope(User $user, string $module): string
    {
        $scopes = $this->scopes($user, $module);
        foreach (['all_records','department','assigned_jobs','own_records','none'] as $scope) {
            if (in_array($scope, $scopes, true)) return $scope;
        }
        return 'none';
    }

    public static function isUniversalRecordModule(string $module): bool
    {
        return in_array($module, self::UNIVERSAL_RECORD_MODULES, true);
    }

    public static function isParentRecordModule(string $module): bool
    {
        return in_array($module, self::PARENT_RECORD_MODULES, true);
    }

    /**
     * Apply Edit Own / Edit All semantics for parent-scoped modules such as Finance.
     * Product catalogue permissions are managed exclusively by catalog_products.
     */
    public function canEditParentRecordModule(User $user, string $module, object $parent): bool
    {
        if (! self::isParentRecordModule($module)) return false;
        if ($this->isAdministrator($user)) return true;
        if (! $this->can($user, $module, 'view') || ! $this->can($user, $module, 'edit')) return false;
        if ($this->canEditAll($user, $module)) return true;

        if ($parent instanceof Inquiry) {
            return $this->isInquiryCreator($user, $parent)
                || (int) ($parent->owner_id ?? 0) === (int) $user->id;
        }

        if ($parent instanceof FlowJob) {
            return $this->isJobCreator($user, $parent)
                || (int) ($parent->owner_id ?? 0) === (int) $user->id
                || (int) ($parent->coordinator_id ?? 0) === (int) $user->id;
        }

        return false;
    }

    public function canEditOwn(User $user, string $module): bool
    {
        if ($this->isAdministrator($user)) return true;
        $actions = $this->access($user, $module)?->actions ?: [];
        return in_array('edit_own', $actions, true) || in_array('edit_all', $actions, true) || in_array('manage', $actions, true);
    }

    public function canEditAll(User $user, string $module): bool
    {
        if ($this->isAdministrator($user)) return true;
        $actions = $this->access($user, $module)?->actions ?: [];
        return in_array('edit_all', $actions, true) || in_array('manage', $actions, true);
    }

    public function applyJobScope(Builder|Relation $query, User $user, string $module = 'jobs'): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, $module, 'view')) return $query->whereRaw('1 = 0');
        return $this->constrainJobs($query, $user, $this->scopes($user, $module));
    }

    public function applyTaskScope(Builder|Relation $query, User $user): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, 'tasks', 'view')) {
            return $query->whereHas('job', fn ($job) => $job->where('created_by', $user->id));
        }
        return $this->constrainTasks($query, $user, $this->scopes($user, 'tasks'));
    }

    public function applyInquiryScope(Builder|Relation $query, User $user): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, 'inquiries', 'view')) return $query->whereRaw('1 = 0');

        // Inquiries are shared operational records inside the current workspace.
        // Once a user's role grants Inquiry View access, the user must see the same
        // Inquiry list as every other user with that access. Record ownership and
        // task assignment must not hide unrelated Inquiries. Create/edit/delete/
        // assign/export capabilities remain independently enforced by their own
        // Inquiry action permissions, so View access does not grant write access.
        return $query;
    }

    public function applyClientScope(Builder|Relation $query, User $user): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, 'clients', 'view')) return $query->whereRaw('1 = 0');

        // Clients are workspace-wide reference records. Restricting the client
        // directory through Job ownership makes newly-created Clients vanish
        // until an Order exists, and prevents legitimate Order creators from
        // selecting shared Clients. Operational Jobs/Tasks remain scoped by
        // their own modules; only the Client directory itself is universal.
        return $query;
    }

    public function applyDocumentScope(Builder|Relation $query, User $user, string $module = 'documents'): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!in_array($module, ['documents', 'document_archive'], true) || !$this->can($user, $module, 'view')) {
            return $query->whereRaw('1 = 0');
        }

        $scopes = $this->scopes($user, $module);
        if (in_array('all_records', $scopes, true)) return $query;
        if ($this->normalizeScopes($scopes) === ['none']) return $query->whereRaw('1 = 0');

        if ($module === 'documents') {
            // Preserve the existing in-record Documents visibility semantics.
            return $query->where(function ($q) use ($user, $scopes) {
                $q->whereHas('task', fn ($tasks) => $this->constrainTasks($tasks, $user, $scopes))
                    ->orWhereHas('job', fn ($jobs) => $this->constrainJobs($jobs, $user, $scopes));
            });
        }

        // The standalone archive also contains client-only/unlinked files, so
        // its own-record and department scopes must account for the uploader.
        return $query->where(function ($q) use ($user, $scopes) {
            $q->where('uploaded_by', $user->id)
                ->orWhereHas('task', fn ($tasks) => $this->constrainTasks($tasks, $user, $scopes))
                ->orWhereHas('job', fn ($jobs) => $this->constrainJobs($jobs, $user, $scopes));

            if (in_array('department', $this->normalizeScopes($scopes), true) && $user->department_id) {
                $q->orWhereHas('uploader', fn ($uploader) => $uploader->where('department_id', $user->department_id));
            }
        });
    }

    /** Scope Order records used only by the standalone Document Archive. */
    public function applyDocumentArchiveJobScope(Builder|Relation $query, User $user): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, 'document_archive', 'view')) return $query->whereRaw('1 = 0');
        $scopes = $this->scopes($user, 'document_archive');
        if ($this->normalizeScopes($scopes) === ['none']) return $query->whereRaw('1 = 0');
        return $this->constrainJobs($query, $user, $scopes);
    }

    /** Scope Order tasks used only by the standalone Document Archive. */
    public function applyDocumentArchiveTaskScope(Builder|Relation $query, User $user): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, 'document_archive', 'view')) return $query->whereRaw('1 = 0');
        $scopes = $this->scopes($user, 'document_archive');
        if ($this->normalizeScopes($scopes) === ['none']) return $query->whereRaw('1 = 0');
        return $this->constrainTasks($query, $user, $scopes);
    }

    /**
     * Scope Inquiry documents independently from the Inquiry module so access
     * to the Document Archive can be delegated without exposing Inquiry pages.
     */
    public function applyInquiryDocumentArchiveScope(Builder|Relation $query, User $user): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, 'document_archive', 'view')) return $query->whereRaw('1 = 0');

        $query->whereHas('inquiry', fn (Builder $inquiry) => $inquiry
            ->where('workspace_id', app(SetupContext::class)->workspaceId()));

        $scopes = $this->normalizeScopes($this->scopes($user, 'document_archive'));
        if (in_array('all_records', $scopes, true)) return $query;
        if ($scopes === ['none']) return $query->whereRaw('1 = 0');

        return $query->where(function (Builder $scopeQuery) use ($user, $scopes): void {
            $scopeQuery->where('inquiry_documents.uploaded_by', $user->id);

            if (array_intersect($scopes, ['own_records', 'assigned_jobs'])) {
                $scopeQuery->orWhereHas('inquiry', function (Builder $inquiry) use ($user): void {
                    $inquiry->where('created_by', $user->id)
                        ->orWhere('owner_id', $user->id);
                });
            }

            if (in_array('assigned_jobs', $scopes, true)) {
                $scopeQuery->orWhereHas('task', fn (Builder $task) => $task->where('assignee_id', $user->id));
            }

            if (in_array('department', $scopes, true) && $user->department_id) {
                $scopeQuery
                    ->orWhereHas('uploader', fn (Builder $uploader) => $uploader->where('department_id', $user->department_id))
                    ->orWhereHas('inquiry.owner', fn (Builder $owner) => $owner->where('department_id', $user->department_id))
                    ->orWhereHas('task.assignee', fn (Builder $assignee) => $assignee->where('department_id', $user->department_id));
            }
        });
    }

    public function applyInquiryTaskScope(Builder|Relation $query, User $user): Builder
    {
        $query = $this->eloquentBuilder($query);
        if (!$this->can($user, 'inquiries', 'view')) return $query->whereRaw('1 = 0');

        $query->whereIn(
            'inquiry_tasks.inquiry_id',
            app(InquiryService::class)->visibleQuery($user)->select('inquiries.id')
        );

        // The creator of an Inquiry always keeps access to its complete taskflow.
        if (!$this->can($user, 'tasks', 'view')) {
            return $query->whereHas('inquiry', fn ($inquiry) => $inquiry->where('created_by', $user->id));
        }

        $scopes = $this->normalizeScopes($this->scopes($user, 'tasks'));
        if (in_array('all_records', $scopes, true)) return $query;

        return $query->where(function ($scopeQuery) use ($user, $scopes): void {
            $scopeQuery->whereHas('inquiry', fn ($inquiry) => $inquiry->where('created_by', $user->id));

            if (array_intersect($scopes, ['own_records', 'assigned_jobs'])) {
                $scopeQuery->orWhere('inquiry_tasks.assignee_id', $user->id);
            }

            if (in_array('department', $scopes, true) && $user->department_id) {
                $scopeQuery->orWhereHas('assignee', fn ($assignee) => $assignee->where('department_id', $user->department_id));
            }
        });
    }

    public function isJobCreator(User $user, object $job): bool
    {
        if (empty($job->id)) return false;
        if (method_exists($job, 'getAttributes') && array_key_exists('created_by', $job->getAttributes())) {
            return (int) ($job->created_by ?? 0) === (int) $user->id;
        }
        return (int) \App\Models\FlowJob::query()->whereKey($job->id)->value('created_by') === (int) $user->id;
    }

    public function isInquiryCreator(User $user, object $inquiry): bool
    {
        if (empty($inquiry->id)) return false;
        if (method_exists($inquiry, 'getAttributes') && array_key_exists('created_by', $inquiry->getAttributes())) {
            return (int) ($inquiry->created_by ?? 0) === (int) $user->id;
        }
        return (int) \App\Models\Inquiry::query()->whereKey($inquiry->id)->value('created_by') === (int) $user->id;
    }

    public function isTaskParentCreator(User $user, object $task): bool
    {
        if (empty($task->flow_job_id)) return false;

        // Order-detail task actions already have the parent Order in memory.
        // Reuse it when available instead of issuing another flow_jobs lookup;
        // the fallback query preserves the exact behavior for every other caller.
        if (method_exists($task, 'relationLoaded') && $task->relationLoaded('job')) {
            $job = $task->getRelation('job');
            if ($job && method_exists($job, 'getAttributes') && array_key_exists('created_by', $job->getAttributes())) {
                return (int) ($job->created_by ?? 0) === (int) $user->id;
            }
        }

        return (int) \App\Models\FlowJob::query()->whereKey($task->flow_job_id)->value('created_by') === (int) $user->id;
    }

    public function isInquiryTaskParentCreator(User $user, InquiryTask $task): bool
    {
        if (empty($task->inquiry_id)) return false;
        return (int) \App\Models\Inquiry::query()->whereKey($task->inquiry_id)->value('created_by') === (int) $user->id;
    }

    public function canEditInquiryTask(User $user, InquiryTask $task): bool
    {
        if ($this->isAdministrator($user) || $this->isInquiryTaskParentCreator($user, $task)) return true;
        if (!$this->can($user, 'tasks', 'edit')) return false;
        if (!$this->applyInquiryTaskScope(InquiryTask::query()->whereKey($task->id), $user)->exists()) return false;
        if ($this->canEditAll($user, 'tasks')) return true;

        return (int) ($task->assignee_id ?? 0) === (int) $user->id;
    }

    public function canAssignInquiryTask(User $user, InquiryTask $task): bool
    {
        if ($this->isAdministrator($user) || $this->isInquiryTaskParentCreator($user, $task)) return true;
        if (!$this->can($user, 'tasks', 'assign')) return false;

        return $this->applyInquiryTaskScope(InquiryTask::query()->whereKey($task->id), $user)->exists();
    }

    public function canCreateInquiryTask(User $user, object $inquiry): bool
    {
        if ($this->isAdministrator($user) || $this->isInquiryCreator($user, $inquiry)) return true;
        if (!$this->can($user, 'tasks', 'create') || empty($inquiry->id)) return false;

        return app(InquiryService::class)->visibleQuery($user)->whereKey($inquiry->id)->exists();
    }

    public function canDeleteInquiryTask(User $user, InquiryTask $task): bool
    {
        if ($this->isAdministrator($user)) return true;
        if (!$this->can($user, 'tasks', 'delete')) return false;

        return $this->applyInquiryTaskScope(InquiryTask::query()->whereKey($task->id), $user)->exists();
    }

    public function canEditJob(User $user, object $job): bool
    {
        if ($this->isAdministrator($user) || $this->isJobCreator($user, $job)) return true;
        if (!$this->can($user, 'jobs', 'edit')) return false;

        $scopes = $this->scopes($user, 'jobs');
        if (!$this->jobWithinScope($job, $user, $scopes)) return false;
        if ($this->canEditAll($user, 'jobs')) return true;

        return (int) ($job->owner_id ?? 0) === (int) $user->id
            || (int) ($job->coordinator_id ?? 0) === (int) $user->id;
    }

    /**
     * Authorization for a Job that was already loaded through visibleQuery().
     * This avoids repeating an EXISTS scope query for every rendered row/card.
     */
    public function canEditVisibleJob(User $user, object $job): bool
    {
        if ($this->isAdministrator($user) || $this->isJobCreator($user, $job)) return true;
        if (!$this->can($user, 'jobs', 'edit')) return false;
        if ($this->canEditAll($user, 'jobs')) return true;

        return (int) ($job->owner_id ?? 0) === (int) $user->id
            || (int) ($job->coordinator_id ?? 0) === (int) $user->id;
    }

    public function canCreateJobTask(User $user, object $job): bool
    {
        if ($this->isAdministrator($user) || $this->isJobCreator($user, $job)) return true;
        if (!$this->can($user, 'tasks', 'create') || empty($job->id)) return false;

        return app(JobService::class)->visibleQuery($user)->whereKey($job->id)->exists();
    }

    public function canEditTask(User $user, object $task): bool
    {
        if ($this->isAdministrator($user) || $this->isTaskParentCreator($user, $task)) return true;
        if (!$this->can($user, 'tasks', 'edit')) return false;

        $scopes = $this->scopes($user, 'tasks');
        if (!$this->taskWithinScope($task, $user, $scopes)) return false;
        if ($this->canEditAll($user, 'tasks')) return true;

        // A visible, active workflow task with no assignee is a claimable task.
        // The first real action is still audited by TaskService::claimForAction(),
        // which assigns the actor before the requested task change is applied.
        $assigneeId = (int) ($task->assignee_id ?? 0);
        return $assigneeId === 0 || $assigneeId === (int) $user->id;
    }

    /** Authorization for a Task already loaded through visibleQuery(). */
    public function canEditVisibleTask(User $user, object $task, ?object $parentJob = null): bool
    {
        $parentCreator = $parentJob
            ? $this->isJobCreator($user, $parentJob)
            : $this->isTaskParentCreator($user, $task);
        if ($this->isAdministrator($user) || $parentCreator) return true;
        if (!$this->can($user, 'tasks', 'edit')) return false;
        if ($this->canEditAll($user, 'tasks')) return true;

        $assigneeId = (int) ($task->assignee_id ?? 0);
        return $assigneeId === 0 || $assigneeId === (int) $user->id;
    }

    public function canAssignJob(User $user, object $job): bool
    {
        if ($this->isAdministrator($user) || $this->isJobCreator($user, $job)) return true;
        if (!$this->can($user, 'jobs', 'assign')) return false;
        return $this->jobWithinScope($job, $user, $this->scopes($user, 'jobs'));
    }

    /** Assignment authorization for a Job already loaded through visibleQuery(). */
    public function canAssignVisibleJob(User $user): bool
    {
        return $this->isAdministrator($user) || $this->can($user, 'jobs', 'assign');
    }

    /**
     * Job workflow/status transitions are intentionally stricter than normal
     * inline Job editing. For non-administrators, the role must allow Job
     * editing and the user must be the Job's assigned owner. Older Jobs that
     * do not have an owner fall back to the coordinator so they remain usable.
     */
    public function canChangeJobStatus(User $user, object $job): bool
    {
        if ($this->isAdministrator($user) || $this->isJobCreator($user, $job)) return true;
        if (!$this->can($user, 'jobs', 'edit')) return false;
        if (!$this->jobWithinScope($job, $user, $this->scopes($user, 'jobs'))) return false;

        return $this->canEditJob($user, $job);
    }

    /** Status authorization for a Job already loaded through visibleQuery(). */
    public function canChangeVisibleJobStatus(User $user, object $job): bool
    {
        if ($this->isAdministrator($user) || $this->isJobCreator($user, $job)) return true;
        if (!$this->can($user, 'jobs', 'edit')) return false;
        if ($this->canEditAll($user, 'jobs')) return true;

        return (int) ($job->owner_id ?? 0) === (int) $user->id
            || (int) ($job->coordinator_id ?? 0) === (int) $user->id;
    }

    public function canAssignTask(User $user, object $task): bool
    {
        if ($this->isAdministrator($user) || $this->isTaskParentCreator($user, $task)) return true;
        if (!$this->can($user, 'tasks', 'assign')) return false;

        return $this->taskWithinScope($task, $user, $this->scopes($user, 'tasks'));
    }


    /** Assignment authorization for a Task already loaded through visibleQuery(). */
    public function canAssignVisibleTask(User $user, object $task, ?object $parentJob = null): bool
    {
        $parentCreator = $parentJob
            ? $this->isJobCreator($user, $parentJob)
            : $this->isTaskParentCreator($user, $task);
        if ($this->isAdministrator($user) || $parentCreator) return true;

        return $this->can($user, 'tasks', 'assign');
    }

    /**
     * Eager-load callbacks receive the relation instance (for example HasMany),
     * while normal list queries pass an Eloquent Builder.  Access scoping must
     * support both without changing the relation's underlying query.
     */
    private function eloquentBuilder(Builder|Relation $query): Builder
    {
        return $query instanceof Relation ? $query->getQuery() : $query;
    }

    /** @param string|list<string> $scopes */
    private function constrainJobs(Builder $query, User $user, string|array $scopes): Builder
    {
        $scopes = $this->normalizeScopes($scopes);
        if (in_array('all_records', $scopes, true)) return $query;

        return $query->where(function (Builder $scopeQuery) use ($user, $scopes): void {
            // Preserve FlowTrack's creator visibility for every non-admin scope.
            $scopeQuery->where('created_by', $user->id);

            if (array_intersect($scopes, ['own_records', 'assigned_jobs'])) {
                $scopeQuery->orWhere('owner_id', $user->id)
                    ->orWhere('coordinator_id', $user->id);
            }

            if (in_array('assigned_jobs', $scopes, true)) {
                $scopeQuery
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id))
                    ->orWhereHas('tasks', fn ($tasks) => $tasks->where('assignee_id', $user->id));
            }

            if (in_array('department', $scopes, true) && $user->department_id) {
                $scopeQuery
                    ->orWhereHas('owner', fn ($assigned) => $assigned->where('department_id', $user->department_id))
                    ->orWhereHas('coordinator', fn ($assigned) => $assigned->where('department_id', $user->department_id))
                    ->orWhereHas('members.user', fn ($assigned) => $assigned->where('department_id', $user->department_id))
                    ->orWhereHas('tasks.assignee', fn ($assigned) => $assigned->where('department_id', $user->department_id));
            }
        });
    }

    /** @param string|list<string> $scopes */
    private function constrainTasks(Builder $query, User $user, string|array $scopes): Builder
    {
        $scopes = $this->normalizeScopes($scopes);
        if (in_array('all_records', $scopes, true)) return $query;

        return $query->where(function (Builder $scopeQuery) use ($user, $scopes): void {
            $scopeQuery->whereHas('job', fn ($job) => $job->where('created_by', $user->id));

            if (array_intersect($scopes, ['own_records', 'assigned_jobs'])) {
                $scopeQuery->orWhere('tasks.assignee_id', $user->id);
            }

            if (in_array('department', $scopes, true) && $user->department_id) {
                $scopeQuery->orWhereHas('assignee', fn ($assigned) => $assigned->where('department_id', $user->department_id));
            }

            // Unassigned generated tasks need a safe path into the normal
            // assigned-jobs workflow. Only expose an open task from the Order's
            // CURRENT stage, and only when the user is already authorised for
            // the parent Order or the Task Pack explicitly targets their
            // department. Once they act, TaskService::claimForAction() assigns
            // the task and creates the FlowJobMember row, so normal assigned
            // visibility takes over from that point onward.
            $scopeQuery->orWhere(function (Builder $claimable) use ($user): void {
                $claimable
                    ->whereNull('tasks.assignee_id')
                    ->whereNull('tasks.completed_at')
                    ->whereHas('job', fn (Builder $job) => $job
                        ->whereNull('flow_jobs.completed_at')
                        ->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)
                        ->whereColumn('flow_jobs.workflow_phase_id', 'tasks.workflow_phase_id'))
                    ->where(function (Builder $authorised) use ($user): void {
                        // Ordinary users may claim only work explicitly routed to
                        // their department. Order owner/coordinator are the narrow
                        // operational override; administrators/edit-all users are
                        // already handled above. Do not use generic Job membership
                        // here, otherwise a Shipment member could claim a later
                        // unassigned Billing task after the workflow advances.
                        if ($user->department_id) {
                            $authorised->whereHas('template', fn (Builder $template) => $template
                                ->where('default_department_id', $user->department_id));
                        } else {
                            $authorised->whereRaw('1 = 0');
                        }

                        $authorised->orWhereHas('job', fn (Builder $job) => $job
                            ->where('owner_id', $user->id)
                            ->orWhere('coordinator_id', $user->id));
                    });
            });
        });
    }

    /** @param string|list<string> $scopes */
    private function jobWithinScope(object $job, User $user, string|array $scopes): bool
    {
        if (empty($job->id)) return false;
        return $this->constrainJobs(\App\Models\FlowJob::query()->whereKey($job->id), $user, $scopes)->exists();
    }

    /** @param string|list<string> $scopes */
    private function taskWithinScope(object $task, User $user, string|array $scopes): bool
    {
        if (empty($task->id)) return false;
        return $this->constrainTasks(\App\Models\Task::query()->whereKey($task->id), $user, $scopes)->exists();
    }

    /** @param string|list<string> $scopes @return list<string> */
    private function normalizeScopes(string|array $scopes): array
    {
        $scopes = array_values(array_unique(array_map(
            fn ($scope) => (string) ($scope === 'selected_clients' ? 'assigned_jobs' : $scope),
            (array) $scopes,
        )));
        $scopes = array_values(array_filter($scopes, fn ($scope) => in_array($scope, ['none','own_records','assigned_jobs','department','all_records'], true)));
        return $scopes !== [] ? $scopes : ['none'];
    }

    private function isJobMember(object $job, User $user): bool
    {
        return !empty($job->id) && \App\Models\FlowJobMember::query()
            ->where('flow_job_id', $job->id)->where('user_id', $user->id)->exists();
    }

    public function forgetRole(int $roleId): void
    {
        // Access can be composed from several roles, so a role update may affect
        // many user cache keys. Clearing the small request-local caches is safer
        // than trying to invalidate only one primary-role prefix.
        $this->accessCache = [];
        $this->accessRowsCache = [];
        $this->activeRolesCache = [];
    }

    public function access(User $user, string $module): ?RoleModuleAccess
    {
        $roles = $this->activeRoles($user);
        if ($roles->isEmpty()) return null;

        $roleIds = $roles->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $key = $user->id.':'.implode(',', $roleIds).':'.$module;

        if (!array_key_exists($key, $this->accessCache)) {
            $rows = $this->accessRows($user, $module);
            $actions = $rows
                ->flatMap(fn (RoleModuleAccess $row) => $row->actions ?: [])
                ->filter(fn ($action) => in_array($action, self::supportedActions($module), true))
                ->unique()
                ->values()
                ->all();

            if ($actions === []) {
                $this->accessCache[$key] = null;
            } else {
                $effective = new RoleModuleAccess();
                $effective->module_code = $module;
                $effective->actions = $actions;
                $effective->record_scope = $this->scopeFromRows($rows, $module);
                $this->accessCache[$key] = $effective;
            }
        }

        return $this->accessCache[$key];
    }

    private function activeRoles(User $user): Collection
    {
        $key = (int) $user->id;
        if (!array_key_exists($key, $this->activeRolesCache)) {
            $roles = $user->assignedRoles(true);
            $this->activeRolesCache[$key] = $roles->values();
        }
        return $this->activeRolesCache[$key];
    }

    private function accessRows(User $user, string $module): Collection
    {
        $roleIds = $this->activeRoles($user)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($roleIds === []) return collect();

        $key = implode(',', $roleIds).':'.$module;
        if (!array_key_exists($key, $this->accessRowsCache)) {
            $this->accessRowsCache[$key] = RoleModuleAccess::query()
                ->whereIn('role_id', $roleIds)
                ->where('module_code', $module)
                ->get();
        }
        return $this->accessRowsCache[$key];
    }


    private function rowGrantsView(RoleModuleAccess $row): bool
    {
        $actions = $row->actions ?: [];
        return in_array('view', $actions, true) || in_array('manage', $actions, true);
    }

    private function scopeFromRows(Collection $rows, string $module): string
    {
        if (self::isUniversalRecordModule($module) || self::isParentRecordModule($module)) {
            return $rows->contains(fn (RoleModuleAccess $row) => count($row->actions ?: []) > 0) ? 'all_records' : 'none';
        }

        $scopes = $rows
            ->filter(fn (RoleModuleAccess $row) => count($row->actions ?: []) > 0)
            ->pluck('record_scope')
            ->map(fn ($scope) => (string) ($scope ?: 'none'))
            ->all();

        foreach (['all_records','department','assigned_jobs','own_records','none'] as $scope) {
            if (in_array($scope, $scopes, true)) return $scope;
        }
        return 'none';
    }

}
