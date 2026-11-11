<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\ClientService;
use App\Services\WorkspaceRefreshService;
use Closure;
use Illuminate\Support\Facades\Cache;

final class DashboardReadModelCache
{
    private const VERSION = 'phase12-v1';

    public function __construct(
        private readonly ClientService $clients,
        private readonly WorkspaceRefreshService $workspaceRefresh,
    ) {}

    public function rememberArray(User $actor, string $section, array $identity, Closure $resolver): array
    {
        $seconds = max(10, (int) config('performance.dashboard_cache_seconds', 45));
        $key = $this->key($actor, $section, $identity);
        $repository = Cache::supportsTags()
            ? Cache::tags(['flowtrack-dashboard', 'flowtrack-dashboard-user-'.(int) $actor->id])
            : Cache::store();

        return $repository->remember($key, $seconds, function () use ($resolver): array {
            $value = $resolver();
            if (!is_array($value)) {
                throw new \LogicException('Dashboard read-model caches may only persist array payloads.');
            }
            return $value;
        });
    }

    public function forgetUser(User|int $actor): void
    {
        $userId = $actor instanceof User ? (int) $actor->id : (int) $actor;
        if (Cache::supportsTags()) {
            Cache::tags(['flowtrack-dashboard', 'flowtrack-dashboard-user-'.$userId])->flush();
        }
        $generationKey = $this->generationKey($userId);
        Cache::forever($generationKey, ((int) Cache::get($generationKey, 1)) + 1);
    }

    public function forgetMentions(User|int $actor): void
    {
        // Mention changes also affect the KPI summary. Bumping the user generation
        // keeps invalidation explicit and avoids maintaining a fragile key registry.
        $this->forgetUser($actor);
    }

    /**
     * The catalogue aggregates only Master Data product/category/supplier records.
     * Task or Order activity must not invalidate these eight catalogue counts.
     * Unknown subjects invalidate conservatively rather than risking stale data.
     */
    public function invalidateCatalogueForReason(string $reason): void
    {
        if (!config('dashboard.progressive_loading_enabled', false)) return;
        foreach (explode('|', $reason) as $part) {
            $subject = explode(':', $part, 2)[0];
            if (in_array($subject, ['MasterRecord', 'MasterValue', 'role-access', 'Permission', 'Role', 'RoleModuleAccess', 'User', 'Workspace', 'WorkspaceMembership', 'data'], true)
                || $subject === '' || !in_array($subject, [
                    'Activity', 'FlowJob', 'FlowJobItem', 'FlowJobMember', 'FlowJobPhaseHistory',
                    'Task', 'TaskLink', 'FlowTaskChecklistItem', 'FlowTaskComment',
                    'Inquiry', 'InquiryTask', 'InquiryItem', 'InquiryTaskComment', 'InquiryTaskLink',
                    'InquiryDocument', 'Invoice', 'InvoiceItem', 'Payment', 'OrderCollection',
                    'CollectionUpdate', 'Client', 'ClientContact', 'ClientShippingAddress',
                    'Document', 'Department', 'TaskPack', 'TaskPackTask', 'TaskPackItem',
                    'Workflow', 'WorkflowPhase', 'WorkflowTemplate', 'NotificationRule',
                ], true)) {
                Cache::forever($this->catalogueVersionKey(), (string) now()->timestamp.'-'.bin2hex(random_bytes(5)));
                return;
            }
        }
    }

    private function catalogueVersionKey(): string
    {
        return 'flowtrack:dashboard-catalogue-version:'. $this->workspaceRefresh->workspaceId();
    }

    private function key(User $actor, string $section, array $identity): string
    {
        ksort($identity);
        $generation = (int) Cache::get($this->generationKey((int) $actor->id), 1);
        $isCatalogue = config('dashboard.progressive_loading_enabled', false) && $section === 'catalogue-readiness';
        $clientVersion = $isCatalogue ? 'catalogue' : $this->clients->lifecycleVersion();
        $workspaceVersion = $isCatalogue
            ? 'catalogue-'.(string) Cache::get($this->catalogueVersionKey(), '1')
            : $this->workspaceRefresh->version();
        $fingerprint = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        return implode(':', [
            'flowtrack', 'dashboard-read', self::VERSION,
            'clients-'.$clientVersion,
            'data-'.$workspaceVersion,
            'generation-'.$generation,
            'user-'.$actor->id,
            $section,
            $fingerprint,
        ]);
    }

    private function generationKey(int $userId): string
    {
        return 'flowtrack:dashboard-read:generation:user:'.$userId;
    }
}
