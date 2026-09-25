<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\NotificationPreferences\UpdateNotificationPreferenceRequest;
use App\Services\NotificationPreferenceService;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    public function __construct(protected NotificationPreferenceService $preferences) {}

    /**
     * PRD Section 115 — "Users can toggle non-critical email/in-app
     * alerts... Account security alerts remain mandatory." Personal to
     * each user (like Section 114's account settings) — no company-wide
     * or admin visibility into another user's preferences.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        return response()->json(['preferences' => $this->preferences->getPreferences($user->id, $connection)]);
    }

    public function update(UpdateNotificationPreferenceRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $data = $request->validated();

        try {
            $this->preferences->setPreference($user->id, $data['notification_type'], $data['channel'], $data['enabled'], $connection);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['preferences' => $this->preferences->getPreferences($user->id, $connection)]);
    }
}
