<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('flowtrack/Notifications/Index', [
            'notifications' => $request->user()->notifications()->latest()->paginate(30),
        ]);
    }

    public function read(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless($notification->notifiable_id === $request->user()->id, 403);
        $notification->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('success', 'All notifications marked as read.');
    }
}
