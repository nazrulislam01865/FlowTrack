<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Modules\OrderFlow\Business\Services\NotificationFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function status(Request $request, NotificationFeedService $feed): JsonResponse
    {
        return response()->json([
            'unread_count' => $feed->unreadCount($request->user()),
            'latest_signature' => $feed->latestSignature($request->user()),
            'authority_view' => $feed->isAuthority($request->user()),
        ]);
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
        $feed->synchronizeAuthorityFeed($request->user());
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
