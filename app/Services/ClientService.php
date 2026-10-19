<?php

namespace App\Services;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ClientService
{
    public function visibleQuery(User $user): Builder
    {
        return app(AccessControlService::class)->applyClientScope(
            Client::query()->whereNull('clients.purged_at'),
            $user
        );
    }

    /**
     * Query the shared Client directory when another permitted workflow needs
     * Client reference data (for example Create Order/Create Inquiry). This is
     * intentionally separate from visibleQuery(): opening the Clients module
     * still requires clients.view, while operational creation screens can use
     * the universal Client lookup they depend on without inheriting Job scope.
     */
    public function referenceQuery(User $user, string $context = ''): Builder
    {
        $access = app(AccessControlService::class);
        $allowed = match ($context) {
            'create-job', 'bulk-order-import' => $access->can($user, 'jobs', 'create'),
            'jobs' => $access->can($user, 'jobs', 'view'),
            'create-inquiry' => $access->can($user, 'inquiries', 'create'),
            'inquiries' => $access->can($user, 'inquiries', 'view'),
            'documents' => $access->can($user, 'documents', 'view'),
            default => $access->can($user, 'clients', 'view'),
        };

        $query = Client::query()->whereNull('clients.purged_at');

        return $allowed ? $query : $query->whereRaw('1 = 0');
    }

    public function filteredQuery(User $user, array $filters = []): Builder
    {
        $access = app(AccessControlService::class);
        $quick = (string) ($filters['quick'] ?? 'all');

        $archived = (bool) ($filters['archived'] ?? false);

        if ($archived) {
            return $this->visibleQuery($user)
                ->where('clients.is_active', false)
                ->when($filters['search'] ?? null, function ($q, $search) {
                    $q->where(function ($x) use ($search) {
                        $x->whereLike('name', "%{$search}%")
                            ->orWhereLike('code', "%{$search}%")
                            ->orWhereLike('email', "%{$search}%")
                            ->orWhereLike('contact_name', "%{$search}%");
                    });
                })
                ->when($filters['created_by'] ?? null, fn ($q, $v) => $q->where('created_by', $v))
                ->when($filters['archived_date'] ?? null, function ($q, $value) {
                    $from = match ((string) $value) {
                        '7d' => now()->subDays(7)->startOfDay(),
                        '30d' => now()->subDays(30)->startOfDay(),
                        '90d' => now()->subDays(90)->startOfDay(),
                        'year' => now()->startOfYear(),
                        default => null,
                    };
                    if ($from) $q->where('archived_at', '>=', $from);
                })
                ->orderByDesc('archived_at')
                ->orderBy('name');
        }

        return $this->visibleQuery($user)
            ->where('clients.is_active', true)
            ->with('accountManager')
            ->withMin([
                'jobs as next_delivery_at' => fn ($q) => $access->applyJobScope($q->whereNull('flow_jobs.completed_at')->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)->whereNotNull('flow_jobs.delivery_date'), $user),
            ], 'delivery_date')
            ->withCount([
                'jobs as total_jobs_count' => fn ($q) => $access->applyJobScope($q, $user),
                'jobs as active_jobs_count' => fn ($q) => $access->applyJobScope($q->whereNull('flow_jobs.completed_at')->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES), $user),
                'jobs as attention_jobs_count' => fn ($q) => $access->applyJobScope($q->whereNull('flow_jobs.completed_at')->whereNotIn('flow_jobs.status', JobService::INACTIVE_STATUSES)->where(fn ($x) => $x->where('flow_jobs.attention_requested', true)->orWhere('flow_jobs.needs_attention', true)), $user),
                'tasks as open_tasks_count' => fn ($q) => $access->applyTaskScope($q->whereNull('tasks.completed_at'), $user),
                'tasks as overdue_tasks_count' => fn ($q) => $access->applyTaskScope($q->whereNull('tasks.completed_at')->where('tasks.due_date', '<', app(WorkspaceSettingsService::class)->localToday()->toDateString()), $user),
                'tasks as blocked_tasks_count' => fn ($q) => $access->applyTaskScope($q->whereNull('tasks.completed_at')->where(fn ($x) => $x->where('tasks.status', 'Blocked')->orWhere('tasks.needs_attention', true)), $user),
            ])
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where(function ($x) use ($search) {
                    $x->whereLike('name', "%{$search}%")
                        ->orWhereLike('code', "%{$search}%")
                        ->orWhereLike('country', "%{$search}%")
                        ->orWhereHas('accountManager', fn ($u) => $u->whereLike('name', "%{$search}%"))
                        ->orWhereHas('jobs', fn ($j) => $j->whereLike('job_number', "%{$search}%")->orWhereLike('title', "%{$search}%"));
                });
            })
            ->when($filters['country'] ?? null, fn ($q, $v) => $q->where('country', $v))
            ->when($filters['manager'] ?? null, fn ($q, $v) => $q->where('account_manager_id', $v))
            ->when(($filters['outstanding'] ?? null) === 'positive', fn ($q) => $q->where('outstanding_balance', '>', 0))
            ->when(($filters['outstanding'] ?? null) === 'high', fn ($q) => $q->where('outstanding_balance', '>=', 10000))
            ->when(($filters['outstanding'] ?? null) === 'zero', fn ($q) => $q->where('outstanding_balance', '<=', 0))
            ->when($quick === 'active_jobs', fn ($q) => $q->whereHas('jobs', fn ($j) => $access->applyJobScope($j->whereNull('completed_at')->whereNotIn('status', JobService::INACTIVE_STATUSES), $user)))
            ->when($quick === 'attention', fn ($q) => $q->whereHas('jobs', fn ($j) => $access->applyJobScope($j->whereNull('completed_at')->whereNotIn('status', JobService::INACTIVE_STATUSES)->where(fn ($x) => $x->where('needs_attention', true)->orWhere('attention_requested', true)), $user)))
            ->when($quick === 'outstanding', fn ($q) => $q->where('outstanding_balance', '>', 0))
            ->orderBy('name');
    }

    public function paginate(User $user, array $filters = [], int $perPage = 10)
    {
        return $this->filteredQuery($user, $filters)->paginate($perPage);
    }

    public function summary(User $user): array
    {
        $clients = $this->visibleQuery($user)->where('clients.is_active', true);
        $jobs = app(AccessControlService::class)->applyJobScope(FlowJob::query(), $user);

        $clientMetrics = (clone $clients)
            ->reorder()
            ->selectRaw('count(*) as client_count')
            ->selectRaw('coalesce(sum(outstanding_balance), 0) as outstanding_total')
            ->selectRaw('sum(case when outstanding_balance > 0 then 1 else 0 end) as outstanding_client_count')
            ->first();

        $jobMetrics = (clone $jobs)
            ->reorder()
            ->selectRaw("sum(case when completed_at is null and status not in ('Inactive','Cancelled') then 1 else 0 end) as active_job_count")
            ->selectRaw("sum(case when completed_at is null and status not in ('Inactive','Cancelled') and (needs_attention = 1 or attention_requested = 1) then 1 else 0 end) as attention_job_count")
            ->first();

        return [
            'clients' => (int) ($clientMetrics?->client_count ?? 0),
            'active_jobs' => (int) ($jobMetrics?->active_job_count ?? 0),
            'attention' => (int) ($jobMetrics?->attention_job_count ?? 0),
            'outstanding' => (float) ($clientMetrics?->outstanding_total ?? 0),
            'clients_active' => (clone $clients)->whereHas('jobs', fn ($j) => app(AccessControlService::class)->applyJobScope($j->whereNull('completed_at')->whereNotIn('status', JobService::INACTIVE_STATUSES), $user))->count(),
            'clients_attention' => (clone $clients)->whereHas('jobs', fn ($j) => app(AccessControlService::class)->applyJobScope($j->whereNull('completed_at')->whereNotIn('status', JobService::INACTIVE_STATUSES)->where(fn ($x) => $x->where('needs_attention', true)->orWhere('attention_requested', true)), $user))->count(),
            'clients_outstanding' => (int) ($clientMetrics?->outstanding_client_count ?? 0),
            'archived' => $this->visibleQuery($user)->where('clients.is_active', false)->count(),
        ];
    }

    public function archive(User $user, int $clientId): Client
    {
        abort_unless(app(AccessControlService::class)->can($user, 'clients', 'delete'), 403);
        $client = $this->visibleQuery($user)->findOrFail($clientId);
        if ($client->is_active) {
            $client->update([
                'is_active' => false,
                'archived_at' => now(),
                'archived_by' => $user->id,
            ]);
            $this->touchLifecycleVersion();
        }

        return $client->refresh();
    }

    public function restore(User $user, int $clientId): Client
    {
        abort_unless(app(AccessControlService::class)->can($user, 'clients', 'delete'), 403);
        $client = $this->visibleQuery($user)->findOrFail($clientId);
        if (!$client->is_active) {
            $client->update([
                'is_active' => true,
                'archived_at' => null,
                'archived_by' => null,
            ]);
            $this->touchLifecycleVersion();
        }

        return $client->refresh();
    }

    /**
     * Irreversibly erase an archived client's profile while keeping the
     * database row as a minimal tombstone. Linked Orders, Inquiries,
     * Documents and other historical records keep their foreign keys and are
     * therefore never cascade-deleted.
     */
    public function permanentlyDeleteArchived(User $user, int $clientId): string
    {
        abort_unless(app(AccessControlService::class)->can($user, 'clients', 'delete'), 403);
        $originalName = '';
        $logoPath = '';

        DB::transaction(function () use ($user, $clientId, &$originalName, &$logoPath): void {
            $client = $this->visibleQuery($user)->lockForUpdate()->findOrFail($clientId);
            abort_if($client->is_active, 422, 'Only archived clients can be permanently deleted.');

            $originalName = (string) $client->name;
            $logoPath = (string) ($client->logo_path ?? '');

            // Client-owned profile/configuration records are removed. Historical
            // operational records are deliberately left untouched.
            $client->shippingAddresses()->delete();
            if (Schema::hasTable('client_contacts')) $client->contacts()->delete();
            if (Schema::hasTable('workflow_template_client')) {
                DB::table('workflow_template_client')->where('client_id', $client->id)->delete();
            }

            $client->forceFill([
                'name' => 'Deleted client #'.$client->id,
                'code' => $this->purgedClientCode($client),
                'logo_path' => null,
                'legal_business_name' => null,
                'website' => null,
                'country' => null,
                'contact_name' => null,
                'contact_job_title' => null,
                'email' => null,
                'phone' => null,
                'account_manager_id' => null,
                'preferred_language' => 'English',
                'preferred_currency' => 'USD',
                'outstanding_balance' => 0,
                'notes' => null,
                'office_address' => null,
                'office_address_line1' => null,
                'office_suite' => null,
                'office_city' => null,
                'office_state' => null,
                'office_zip' => null,
                'billing_same_as_office' => true,
                'billing_address_line1' => null,
                'billing_suite' => null,
                'billing_city' => null,
                'billing_state' => null,
                'billing_zip' => null,
                'billing_country' => null,
                'ein_tax_id' => null,
                'sales_tax_status' => 'taxable',
                'payment_terms' => null,
                'po_required' => false,
                'is_draft' => false,
                'is_active' => false,
                'purged_at' => now(),
                'purged_by' => $user->id,
            ])->save();
        });

        $disk = Storage::disk('public');
        if ($logoPath !== '') $disk->delete($logoPath);
        $disk->deleteDirectory('client-logos/'.$clientId);

        $this->touchLifecycleVersion();

        return $originalName;
    }

    private function purgedClientCode(Client $client): string
    {
        $base = 'DEL-'.$client->id.'-'.strtoupper(substr(hash('sha256', (string) $client->id), 0, 6));
        $code = substr($base, 0, 20);
        $suffix = 1;

        while (Client::query()->where('code', $code)->where('id', '!=', $client->id)->exists()) {
            $tail = '-'.$suffix++;
            $code = substr($base, 0, 20 - strlen($tail)).$tail;
        }

        return $code;
    }

    public function lifecycleVersion(): int
    {
        return max(1, (int) Cache::get('flowtrack:clients:lifecycle-version', 1));
    }

    private function touchLifecycleVersion(): void
    {
        if (!Cache::has('flowtrack:clients:lifecycle-version')) {
            Cache::forever('flowtrack:clients:lifecycle-version', 1);
        }
        Cache::increment('flowtrack:clients:lifecycle-version');
    }

    public function detailShell(User $user, int $clientId, bool $loadAddresses = false, bool $loadContacts = false): array
    {
        $relations = ['accountManager'];
        if ($loadAddresses) $relations[] = 'shippingAddresses';
        if ($loadContacts) $relations[] = 'contacts';

        $client = $this->visibleQuery($user)
            ->with($relations)
            ->withCount('contacts')
            ->findOrFail($clientId);

        $orderCount = app(JobService::class)->visibleQuery($user)
            ->where('client_id', $client->id)
            ->count();

        return [
            'client' => $client,
            'jobs' => collect(),
            'orderCount' => $orderCount,
        ];
    }

    public function detail(User $user, int $clientId): array
    {
        $client = $this->visibleQuery($user)->with(['accountManager','shippingAddresses','contacts'])->findOrFail($clientId);
        $jobs = app(JobService::class)->visibleQuery($user)
            ->where('client_id', $client->id)
            ->with(['phase','owner'])
            ->latest('id')
            ->get();
        $tasks = app(TaskService::class)->visibleQuery($user)
            ->whereHas('job', fn ($q) => $q->where('client_id', $client->id))
            ->with(['assignee','job'])
            ->whereNull('completed_at')
            ->where(function ($q) {
                $q->where('needs_attention', true)
                    ->orWhere('status', 'Blocked')
                    ->orWhereDate('due_date', '<', app(WorkspaceSettingsService::class)->localToday());
            })
            ->orderByRaw('due_date is null, due_date asc')
            ->limit(5)
            ->get();

        $active = $jobs->whereNull('completed_at')->whereNotIn('status', JobService::INACTIVE_STATUSES);
        $overdue = app(TaskService::class)->visibleQuery($user)
            ->whereHas('job', fn ($q) => $q->where('client_id', $client->id))
            ->whereNull('completed_at')->where('due_date', '<', app(WorkspaceSettingsService::class)->localToday()->toDateString())->count();
        $openTasks = app(TaskService::class)->visibleQuery($user)
            ->whereHas('job', fn ($q) => $q->where('client_id', $client->id))
            ->whereNull('completed_at')->count();

        return compact('client','jobs','tasks','active','overdue','openTasks');
    }
}
