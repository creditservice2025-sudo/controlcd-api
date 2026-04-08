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
        $sender = Auth::user();
        $targetUser = User::find($request->user_id);

        if (!$targetUser) {
            return response()->json(['success' => false, 'message' => 'Usuario no encontrado'], 404);
        }

        // Aislamiento: Role 2 solo puede enviar a su propia empresa
        if ($sender->role_id == 2) {
            $senderCompany = $sender->company;
            if (!$senderCompany) {
                return response()->json(['success' => false, 'message' => 'No tienes una empresa asociada'], 403);
            }

            // Verificar si el usuario destino pertenece a la misma empresa directamente
            $isSameCompany = $targetUser->company_id == $senderCompany->id;
            
            // O si el usuario destino es un vendedor de esa empresa
            $isSellerInCompany = \App\Models\Seller::where('user_id', $targetUser->id)
                ->where('company_id', $senderCompany->id)
                ->exists();

            if (!$isSameCompany && !$isSellerInCompany && $targetUser->id != $sender->id) {
                return response()->json(['success' => false, 'message' => 'No tienes permisos para notificar a este usuario'], 403);
            }
        }

        $targetUser->notify(new GeneralNotification(
            $request->title,
            $request->message,
            $request->action_url
        ));
        
        return response()->json(['success' => true]);
    }
}
