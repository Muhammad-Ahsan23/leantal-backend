<?php

namespace App\Services;

use App\Models\NotificationPreference;

class NotificationPreferenceService
{
    /**
     * PRD Section 115 — "Account security alerts remain mandatory"
     * (cannot be toggled off). ASSUMPTION: the exact notification type
     * names aren't enumerated anywhere in the PRD — this is a
     * reasonable set covering what our system actually triggers
     * (password changes, new-device logins). Everything else defaults
     * to togglable.
     */
    protected const MANDATORY_TYPES = [
        'password_changed',
        'new_login_detected',
        'account_suspended',
    ];

    /**
     * ASSUMPTION: this list of togglable notification types is inferred
     * from features actually built so far (candidate assignment, task
     * assignment, interview scheduling) — PRD Section 115 gives one
     * example ("candidate assigned to me") but not an exhaustive list.
     * Extend this array as new notification-triggering features are added.
     */
    protected const KNOWN_TYPES = [
        'candidate_assigned', 'task_assigned', 'interview_scheduled',
        'job_assigned', 'candidate_applied',
    ];

    public function isMandatory(string $type): bool
    {
        return in_array($type, self::MANDATORY_TYPES, true);
    }

    /**
     * Returns the full preference set for a user — every KNOWN_TYPE x
     * channel combination, defaulting to enabled=true (matches the
     * migration's own column default) for anything the user hasn't
     * explicitly toggled yet, so the frontend always gets a complete,
     * renderable list rather than having to fill gaps itself.
     */
    public function getPreferences(string $userId, string $connection): array
    {
        $existing = NotificationPreference::on($connection)
            ->where('user_id', $userId)
            ->get()
            ->keyBy(fn ($p) => "{$p->notification_type}:{$p->channel}");

        $result = [];
        foreach (self::KNOWN_TYPES as $type) {
            foreach (['email', 'in_app'] as $channel) {
                $key = "{$type}:{$channel}";
                $result[] = [
                    'notification_type' => $type,
                    'channel' => $channel,
                    'enabled' => $existing->has($key) ? $existing->get($key)->enabled : true,
                    'mandatory' => $this->isMandatory($type),
                ];
            }
        }

        foreach (self::MANDATORY_TYPES as $type) {
            foreach (['email', 'in_app'] as $channel) {
                $result[] = [
                    'notification_type' => $type,
                    'channel' => $channel,
                    'enabled' => true, // always true, never toggled off
                    'mandatory' => true,
                ];
            }
        }

        return $result;
    }

    /**
     * @throws \RuntimeException if attempting to disable a mandatory type
     */
    public function setPreference(string $userId, string $type, string $channel, bool $enabled, string $connection): void
    {
        if ($this->isMandatory($type) && !$enabled) {
            throw new \RuntimeException('Account security alerts cannot be disabled.');
        }

        NotificationPreference::on($connection)->updateOrCreate(
            ['user_id' => $userId, 'notification_type' => $type, 'channel' => $channel],
            ['enabled' => $enabled]
        );
    }
}
