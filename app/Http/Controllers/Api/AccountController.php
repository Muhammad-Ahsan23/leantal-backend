<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * PRD Section 114 — "Users manage personal name, password, MFA,
     * notification preferences, connected mailboxes, and calendar
     * accounts." This covers name + password; MFA has no toggle (it's
     * mandatory for everyone, Section 14 — nothing to manage here);
     * mailboxes/calendars = OAuthConnectionController (already built);
     * notification preferences = NotificationPreferenceController below.
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json(['user' => $user->only(['id', 'name', 'email', 'role', 'company_id'])]);
    }

    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(['user' => $user->fresh()->only(['id', 'name', 'email', 'role'])]);
    }

    /**
     * ASSUMPTION: assumes the User model's 'password' attribute has
     * Laravel's 'hashed' cast (the modern default scaffold, matching
     * our Argon2id config/hashing.php setting) — so a plain assignment
     * hashes automatically. If login breaks after a password change,
     * this cast assumption was wrong; switch to Hash::make() explicitly
     * instead of relying on the cast.
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password' => $data['new_password']]);

        // PRD Section 15 — "Password-change should invalidate other
        // sessions." Revoke every token except the one making this
        // request, so the user isn't immediately logged out of their
        // own current session.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        // PRD Section 20 — "Important account/security event" —
        // mandatory notification type, cannot be disabled via preferences.
        $this->notifications->notify($user, 'password_changed', 'Your password was changed.', $user->getConnectionName());

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
