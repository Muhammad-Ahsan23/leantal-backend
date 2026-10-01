<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
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
     * BUG FIX (audit finding #1): the User model's real column/attribute
     * is 'password_hash' (see User::getAuthPassword() and its own
     * $fillable array) — NOT 'password'. The previous version of this
     * method read/wrote 'password', which doesn't exist on the model:
     * Hash::check() was comparing against null (always false — "Current
     * password is incorrect" fired on every attempt, even with the
     * correct password), and the update() write was silently dropped by
     * mass-assignment protection ('password' isn't in $fillable either).
     *
     * Also: unlike some Laravel setups, 'password_hash' has NO 'hashed'
     * cast on this model (confirmed — only mfa_enabled/last_login_at/
     * removed_at are cast), so Hash::make() must be called explicitly
     * here — a plain assignment would have stored the new password in
     * PLAIN TEXT.
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        if (!Hash::check($data['current_password'], $user->password_hash)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $user->update(['password_hash' => Hash::make($data['new_password'])]);

        // PRD Section 15 — "Password-change should invalidate other
        // sessions." Revoke every token except the one making this
        // request, so the user isn't immediately logged out of their
        // own current session.
        $currentTokenId = $user->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        return response()->json(['message' => 'Password changed successfully.']);
    }
}
