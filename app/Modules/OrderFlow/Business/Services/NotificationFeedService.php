<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Models\User;
use App\Modules\OrderFlow\Data\Models\WorkspaceMembership;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class NotificationFeedService
{
    public function __construct(private readonly FlowAuthorizationService $authorization) {}

    public function isAuthority(User $user): bool
    {
        if ($this->authorization->isAdministrator($user)) {
            return true;
        }

        $roleCode = strtoupper((string) $user->currentWorkspaceMembership()?->role?->code);
        $authorityCodes = collect(config('flowtrack.notification_authority_role_codes', []))
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->all();

        return in_array($roleCode, $authorityCodes, true);
    }

    public function paginate(User $user, int $perPage = 30): LengthAwarePaginator
    {
        return $user->notifications()->latest()->paginate($perPage);
    }

    /** @return Collection<int, DatabaseNotification> */
    public function latest(User $user, int $limit = 50): Collection
    {
        return $user->notifications()->latest()->limit($limit)->get();
    }

    public function unreadCount(User $user): int
    {
        return $user->unreadNotifications()->count();
    }

    public function latestSignature(User $user): ?string
    {
        $latest = $user->notifications()->latest()->first(['id', 'created_at', 'updated_at']);
        if (! $latest) {
            return null;
        }

        return implode(':', [
            $latest->id,
            $latest->created_at?->format('U.u'),
            $latest->updated_at?->format('U.u'),
        ]);
    }

    /**
     * Authority accounts receive their own copy of every notification event in
     * the current workspace. This preserves independent read/unread state and
     * also backfills historical notifications that existed before an authority
     * account was created or promoted.
     */
    public function synchronizeAuthorityFeed(User $authority): void
    {
        if (! $this->isAuthority($authority) || $authority->status !== 'active') {
            return;
        }

        $workspaceId = $authority->currentWorkspaceId();
        if ($workspaceId <= 0) {
            return;
        }

        $cacheKey = "flowtrack:notification-authority-sync:{$workspaceId}:{$authority->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $workspaceUserIds = WorkspaceMembership::query()
            ->where('workspace_id', $workspaceId)
            ->pluck('user_id')
            ->push($authority->id)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($workspaceUserIds->isEmpty()) {
            return;
        }

        $limit = max(100, (int) config('flowtrack.notification_authority_sync_limit', 2000));
        $sources = DatabaseNotification::query()
            ->where('notifiable_type', $authority->getMorphClass())
            ->whereIn('notifiable_id', $workspaceUserIds)
            ->latest()
            ->limit($limit)
            ->get();

        if ($sources->isEmpty()) {
            return;
        }

        $userNames = User::query()
            ->whereIn('id', $sources->pluck('notifiable_id')->unique())
            ->pluck('name', 'id');

        $grouped = $sources
            ->filter(function (DatabaseNotification $notification) use ($workspaceId): bool {
                $payloadWorkspace = (int) data_get($notification->data, 'workspace_id', 0);

                return $payloadWorkspace === 0 || $payloadWorkspace === $workspaceId;
            })
            ->groupBy(fn (DatabaseNotification $notification): string => $this->eventKey($notification));

        $ownedKeys = $sources
            ->where('notifiable_id', $authority->id)
            ->mapWithKeys(fn (DatabaseNotification $notification): array => [$this->eventKey($notification) => true]);

        foreach ($grouped as $eventKey => $eventNotifications) {
            if ($ownedKeys->has($eventKey)) {
                continue;
            }

            /** @var DatabaseNotification|null $source */
            $source = $eventNotifications->sortByDesc('created_at')->first();
            if (! $source) {
                continue;
            }

            $data = $source->data;
            $data['event_id'] = $data['event_id'] ?? 'legacy-'.$eventKey;
            $data['workspace_id'] = $data['workspace_id'] ?? $workspaceId;
            $data['authority_copy'] = true;
            $data['audience'] = collect($data['audience'] ?? [])
                ->merge($eventNotifications->map(function (DatabaseNotification $notification) use ($userNames): array {
                    return [
                        'id' => (int) $notification->notifiable_id,
                        'name' => (string) ($userNames[$notification->notifiable_id] ?? 'User'),
                    ];
                }))
                ->filter(fn ($recipient): bool => is_array($recipient) && filled($recipient['id'] ?? null))
                ->unique(fn (array $recipient): int => (int) $recipient['id'])
                ->values()
                ->all();

            $authority->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $source->type,
                'data' => $data,
                'read_at' => null,
                'created_at' => $source->created_at,
                'updated_at' => $source->updated_at,
            ]);

            $ownedKeys->put($eventKey, true);
        }

        Cache::put(
            $cacheKey,
            true,
            now()->addSeconds(max(15, (int) config('flowtrack.notification_authority_sync_interval_seconds', 60))),
        );
    }

    private function eventKey(DatabaseNotification $notification): string
    {
        $data = $notification->data;
        $eventId = trim((string) ($data['event_id'] ?? ''));
        if ($eventId !== '') {
            return $eventId;
        }

        $occurredAt = (string) ($data['occurred_at'] ?? $notification->created_at?->format('Y-m-d H:i:s.u'));
        $identity = [
            'event' => $data['event'] ?? null,
            'title' => $data['title'] ?? null,
            'text' => $data['text'] ?? null,
            'job_id' => $data['job_id'] ?? null,
            'job' => $data['job'] ?? null,
            'task_id' => $data['task_id'] ?? null,
            'task' => $data['task'] ?? null,
            'document_id' => $data['document_id'] ?? null,
            'phase' => $data['phase'] ?? null,
            'occurred_at' => $occurredAt,
        ];

        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $notification->id);
    }
}
