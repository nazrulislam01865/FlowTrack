<?php

namespace App\Modules\OrderFlow\Application\Presenters;

use App\Models\User;
use App\Modules\OrderFlow\Business\Services\RoleMatrixCatalog;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CurrentUserPresenter
{
    public function __construct(private readonly RoleMatrixCatalog $catalog)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(User $user): array
    {
        $membership = $user->currentWorkspaceMembership();
        $preferences = array_merge($this->defaultNotificationPreferences(), $user->notification_preferences ?? []);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'initials' => $user->initials ?: $this->initials($user->name),
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'locale' => $user->locale ?: 'en',
            'defaultLandingPage' => $user->default_landing_page ?: 'dashboard',
            'defaultTaskView' => $user->default_task_view ?: 'list',
            'avatarUrl' => $user->avatar_path ? Storage::disk('public')->url($user->avatar_path) : null,
            'notificationPreferences' => $preferences,
            'role' => $membership?->role?->name,
            'department' => $membership?->department?->name,
            'jobTitle' => $membership?->job_title,
            'isAdmin' => $user->isFlowAdministrator(),
            'isSuperAdmin' => $user->isFlowSuperAdmin(),
            'sensitiveFields' => $user->isFlowAdministrator()
                ? $this->catalog->sensitiveFieldCodes()
                : ($membership?->role?->sensitive_fields ?? []),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function defaultNotificationPreferences(): array
    {
        return [
            'task_assignments' => true,
            'due_date_reminders' => true,
            'mentions_comments' => true,
            'approval_requests' => true,
            'shipment_alerts' => true,
            'payment_reminders' => false,
        ];
    }

    private function initials(string $name): string
    {
        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }
}
