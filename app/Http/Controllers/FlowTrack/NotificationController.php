<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Services\NotificationFeedService;
use App\Modules\OrderFlow\Application\Support\FlowTrackBootstrapCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function status(
        Request $request,
        NotificationFeedService $feed,
        FlowTrackBootstrapCache $bootstrapCache,
    ): JsonResponse {
        $user = $request->user();

        return response()->json([
            'unread_count' => $feed->unreadCount($user),
            'latest_signature' => $feed->latestSignature($user),
            'authority_view' => $feed->isAuthority($user),
            // Cheap workspace-wide change token. Every successful FlowTrack
            // mutation bumps this version, allowing open browsers to refresh
            // Jobs/Tasks/Documents/assignments without a page reload.
            'workspace_version' => $bootstrapCache->currentVersion($user->currentWorkspaceId()),
        ])->header('Cache-Control', 'private, no-store');
    }


    public function feed(Request $request, NotificationFeedService $feed): JsonResponse
    {
        $user = $request->user();
        $authorityView = $feed->isAuthority($user);
        $limit = max(10, min(100, (int) $request->integer('limit', 50)));

        $notifications = $feed->latest($user, $limit)->map(function ($notification) use ($authorityView): array {
            $data = $notification->data;

            return [
                'id' => $notification->id,
                'title' => $data['title'] ?? 'Notification',
                'text' => $data['text'] ?? '',
                'time' => $notification->created_at?->diffForHumans() ?? '',
                'created_at' => $notification->created_at?->toIso8601String(),
                'read' => $notification->read_at !== null,
                'type' => $data['type'] ?? 'info',
                'severity' => $data['severity'] ?? 'info',
                'event' => $data['event'] ?? null,
                'job' => $data['job'] ?? null,
                'job_id' => $data['job_id'] ?? null,
                'task' => $data['task'] ?? null,
                'task_id' => $data['task_id'] ?? null,
                'phase' => $data['phase'] ?? null,
                'actor' => $data['actor'] ?? null,
                'url' => $data['url'] ?? null,
                'audience' => $authorityView ? array_values($data['audience'] ?? []) : [],
            ];
        })->values();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $feed->unreadCount($user),
            'latest_signature' => $feed->latestSignature($user),
            'authority_view' => $authorityView,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, DatabaseNotification $notification, NotificationFeedService $feed): JsonResponse|RedirectResponse
    {
        abort_unless(
            $notification->notifiable_type === $request->user()->getMorphClass()
                && (int) $notification->notifiable_id === (int) $request->user()->id,
            403,
        );

        $notification->markAsRead();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Notification marked as read.',
                'unread_count' => $feed->unreadCount($request->user()),
                'url' => data_get($notification->data, 'url'),
            ]);
        }

        return back();
    }

    public function readAll(Request $request, NotificationFeedService $feed): JsonResponse|RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'All notifications marked as read.',
                'unread_count' => 0,
            ]);
        }

        return back()->with('success', 'All notifications marked as read.');
    }
}
