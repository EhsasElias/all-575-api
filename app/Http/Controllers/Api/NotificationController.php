<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->paginate(20);

        return response()->json(
            $notifications
        );
    }

    public function unread(Request $request)
    {
        $notifications = $request->user()
            ->unreadNotifications()
            ->latest()
            ->get();

        return response()->json([
            'data' => $notifications,

            'count' =>
                $notifications->count(),
        ]);
    }

    public function markAsRead(
        Request $request,
        string $notification
    ) {
        $item = $request->user()
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        $item->markAsRead();

        return response()->json([
            'message' =>
                'Notification marked as read.',
        ]);
    }

    public function markAllAsRead(
        Request $request
    ) {
        $request->user()
            ->unreadNotifications
            ->markAsRead();

        return response()->json([
            'message' =>
                'All notifications marked as read.',
        ]);
    }

    public function destroy(
        Request $request,
        string $notification
    ) {
        $item = $request->user()
            ->notifications()
            ->where('id', $notification)
            ->firstOrFail();

        $item->delete();

        return response()->json([
            'message' =>
                'Notification deleted successfully.',
        ]);
    }
}
