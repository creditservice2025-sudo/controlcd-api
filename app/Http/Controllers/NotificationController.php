<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Notifications\GeneralNotification;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\Notification\SendNotificationRequest;

class NotificationController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Cache notifications for 60 seconds
        $cacheKey = "notifications_user_{$user->id}";

        return \Cache::remember($cacheKey, 60, function () use ($user) {
            // Optimize query - only fetch necessary fields
            $notifications = $user->notifications()
                ->select(['id', 'type', 'data', 'read_at', 'created_at'])
                ->limit(50) // Limit to 50 most recent
                ->get();

            $unreadCount = $user->unreadNotifications()->count();

            return response()->json([
                'notifications' => [
                    'data' => $notifications,
                    'total' => $notifications->count()
                ],
                'unread_count' => $unreadCount
            ]);
        });
    }

    public function markAsRead($id)
    {
        $user = Auth::user();
        $notification = $user->notifications()->where('id', $id)->first();

        if ($notification) {
            $notification->markAsRead();

            // Invalidate cache
            \Cache::forget("notifications_user_{$user->id}");
        }

        return response()->json(['success' => true]);
    }

    public function markAllAsRead()
    {
        $user = Auth::user();
        $user->unreadNotifications->markAsRead();

        // Invalidate cache
        \Cache::forget("notifications_user_{$user->id}");

        return response()->json(['success' => true]);
    }

    public function sendNotification(SendNotificationRequest $request)
    {
        $user = User::find($request->user_id);
        $user->notify(new GeneralNotification(
            $request->title,
            $request->message,
            $request->action_url
        ));
        return response()->json(['success' => true]);
    }
}
