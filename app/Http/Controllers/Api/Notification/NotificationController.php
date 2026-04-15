<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Models\Notification\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log as FacadesLog;

class NotificationController extends Controller
{
    /**
     * Get user notifications
     */
    public function index(Request $request)
    {

        $user = auth()->user();

        $notifications = Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        // FacadesLog::info($notifications);
        return response()->json([
            'success' => true,
            'data' => $notifications,
            'meta' => [
                'unread_count' => Notification::where('notifiable_type', get_class($user))
                    ->where('notifiable_id', $user->id)
                    ->whereNull('read_at')
                    ->count(),
                'total' => Notification::where('notifiable_type', get_class($user))
                    ->where('notifiable_id', $user->id)
                    ->count(),
            ],
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead($id)
    {
        $user = auth()->user();

        $notification = Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead()
    {
        $user = auth()->user();

        Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
        ]);
    }

    /**
     * Delete notification
     */
    public function destroy($id)
    {
        $user = auth()->user();

        $notification = Notification::where('notifiable_type', get_class($user))
            ->where('notifiable_id', $user->id)
            ->findOrFail($id);

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
        ]);
    }

    /**
     * Get notification preferences
     */
    public function preferences()
    {
        $user = auth()->user();

        $preferences = $user->preferences['notifications'] ?? [
            'email' => [
                'order_updates' => true,
                'promotions' => false,
                'newsletter' => false,
            ],
            'sms' => [
                'order_updates' => true,
                'alerts' => true,
            ],
            'push' => [
                'order_updates' => true,
                'promotions' => false,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $preferences,
        ]);
    }

    /**
     * Update notification preferences
     */
    public function updatePreferences(Request $request)
    {
        $user = auth()->user();

        $preferences = $user->preferences ?? [];
        $preferences['notifications'] = $request->validate([
            'email' => 'sometimes|array',
            'email.order_updates' => 'sometimes|boolean',
            'email.promotions' => 'sometimes|boolean',
            'email.newsletter' => 'sometimes|boolean',
            'sms' => 'sometimes|array',
            'sms.order_updates' => 'sometimes|boolean',
            'sms.alerts' => 'sometimes|boolean',
            'push' => 'sometimes|array',
            'push.order_updates' => 'sometimes|boolean',
            'push.promotions' => 'sometimes|boolean',
        ]);

        $user->preferences = $preferences;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Preferences updated successfully',
            'data' => $preferences['notifications'],
        ]);
    }
}

