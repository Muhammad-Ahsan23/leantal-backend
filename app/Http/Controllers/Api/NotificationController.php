<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\BroadcastNotificationRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * PRD Section 155 — powers the notification bell dropdown.
     * Strictly personal to the current user — no company-wide view,
     * matching every other "own inbox" pattern in the PRD.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $notifications = Notification::on($connection)
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['notifications' => $notifications]);
    }

    /**
     * For the bell's unread-count badge — cheaper than fetching the
     * full list just to count it client-side.
     */
    public function unreadCount(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $count = Notification::on($connection)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    public function markRead(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $notification = Notification::on($connection)->where('user_id', $user->id)->find($id);
        if (!$notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->update(['read_at' => now()]);

        return response()->json(['notification' => $notification->fresh()]);
    }

    public function markAllRead(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        Notification::on($connection)
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * PRD Section 20 — "The Owner should also be able to send
     * important product notifications where appropriate."
     */
    public function broadcast(BroadcastNotificationRequest $request)
    {
        $user = $request->user();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can broadcast notifications.'], 403);
        }

        $connection = $user->getConnectionName();
        $data = $request->validated();

        $count = $this->notifications->broadcast($user->company_id, $data['message'], $data['user_ids'] ?? [], $connection);

        return response()->json(['message' => "Sent to {$count} user(s)."]);
    }
}
