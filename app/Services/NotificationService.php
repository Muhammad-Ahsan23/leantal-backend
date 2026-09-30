<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * PRD Section 20 — "Only important notifications: Candidate assigned
 * to you, Job assigned to you, Task assigned to you, Interview
 * scheduled/changed, Important account/security event, Important
 * system notification." PRD Section 87 confirms these are IN-APP only
 * (not email) — "Candidate assignments, job assignments, task
 * assignments, interview updates."
 *
 * Every notification checks the recipient's own preference first
 * (NotificationPreferenceService) — mandatory types (security events)
 * always fire regardless of preference; everything else respects the
 * toggle the user set in Settings (PRD Section 115).
 */
class NotificationService
{
    protected const MANDATORY_TYPES = ['password_changed', 'new_login_detected', 'account_suspended'];

    public function notify(User $recipient, string $type, string $message, string $connection, ?string $link = null): ?Notification
    {
        if (!in_array($type, self::MANDATORY_TYPES, true)) {
            $preference = NotificationPreference::on($connection)
                ->where('user_id', $recipient->id)
                ->where('notification_type', $type)
                ->where('channel', 'in_app')
                ->first();

            // Default is enabled (matches NotificationPreferenceService's
            // own default-true behavior) — only skip if EXPLICITLY disabled.
            if ($preference && !$preference->enabled) {
                return null;
            }
        }

        return Notification::on($connection)->create([
            'company_id' => $recipient->company_id,
            'user_id' => $recipient->id,
            'type' => $type,
            'message' => $message,
            'link' => $link,
        ]);
    }

    /**
     * PRD Section 20 — "The Owner should also be able to send
     * important product notifications where appropriate." Broadcasts
     * to either every user in the company, or a specific list.
     */
    public function broadcast(string $companyId, string $message, array $userIds, string $connection): int
    {
        $recipients = empty($userIds)
            ? User::on($connection)->where('company_id', $companyId)->get()
            : User::on($connection)->where('company_id', $companyId)->whereIn('id', $userIds)->get();

        $count = 0;
        foreach ($recipients as $recipient) {
            if ($this->notify($recipient, 'system', $message, $connection)) {
                $count++;
            }
        }

        return $count;
    }
}
