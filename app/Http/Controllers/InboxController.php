<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app inbox: the user's delivered cues (database notifications raised when
 * their actions fired). Read endpoints are scoped to the authenticated user's own
 * notifications, so one user can never view or mutate another's.
 */
class InboxController extends Controller
{
    public function index(Request $request): Response
    {
        $notifications = $request->user()->notifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->data['type'] ?? 'action_due',
                'action_id' => $notification->data['action_id'] ?? null,
                'intention_id' => $notification->data['intention_id'] ?? null,
                'title' => $notification->data['title'] ?? null,
                'fired_at' => $notification->data['fired_at'] ?? null,
                'change_reason' => $notification->data['change_reason'] ?? null,
                'approach' => $notification->data['approach'] ?? null,
                'read_at' => $notification->read_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('inbox', [
            'notifications' => $notifications,
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
