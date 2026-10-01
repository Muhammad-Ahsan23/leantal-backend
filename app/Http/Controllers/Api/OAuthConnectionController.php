<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GmailWatchState;
use App\Models\MicrosoftSubscriptionState;
use App\Models\OAuthToken;
use App\Services\GmailWatchService;
use App\Services\GoogleCalendarOAuthService;
use App\Services\MicrosoftOAuthService;
use App\Services\MicrosoftSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OAuthConnectionController extends Controller
{
    public function __construct(
        protected GoogleCalendarOAuthService $google,
        protected GmailWatchService $gmailWatch,
        protected MicrosoftOAuthService $microsoft,
        protected MicrosoftSubscriptionService $microsoftSubscription,
    ) {}

    /**
     * PRD Section 113 — integrations are PERSONAL (per-user), not
     * company-level — each staff member connects their own Google/
     * Outlook/Zoom account. This lists what the CURRENT user has
     * connected — never another user's tokens.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $tokens = OAuthToken::on($connection)
            ->where('user_id', $user->id)
            ->whereNull('disconnected_at')
            ->get(['provider', 'connected_at', 'expires_at']);

        return response()->json(['connections' => $tokens]);
    }

    /**
     * BUG FIX (audit finding #2) — PRD Section 131: "Disconnecting
     * Gmail/Outlook immediately terminates sync jobs, deletes stored
     * access/refresh tokens, retains historical email logs." The
     * previous version only set disconnected_at, leaving the encrypted
     * access_token/refresh_token sitting in the database indefinitely
     * and the Gmail watch / Microsoft subscription running until their
     * own natural expiry rather than stopping immediately.
     *
     * Three things now happen, in order:
     * 1. Revoke the token AT the provider (Google/Microsoft) — not
     *    just forgetting our own copy, actually invalidating the grant.
     * 2. Explicitly stop the sync job (Gmail watch / Microsoft Graph
     *    subscription) rather than waiting for it to expire on its own.
     * 3. Null out the stored token values and remove the local sync-
     *    state row. The OAuthToken ROW itself is kept (with
     *    disconnected_at set) — this preserves connection HISTORY
     *    (when it was connected/disconnected) without keeping any
     *    live credential; Emails already sent/received stay untouched
     *    in the 'emails' table, satisfying "retains historical email
     *    logs" (a completely separate table, never touched here).
     *
     * Each external call is best-effort (try/catch, logged) — if
     * Google/Microsoft's API is briefly unavailable, the user's own
     * disconnect action should still succeed locally rather than being
     * blocked by a third-party outage.
     */
    public function disconnect(Request $request, string $provider)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $token = OAuthToken::on($connection)
            ->where('user_id', $user->id)
            ->where('provider', $provider)
            ->whereNull('disconnected_at')
            ->first();

        if (!$token) {
            return response()->json(['message' => 'No active connection found for that provider.'], 404);
        }

        if ($provider === 'google') {
            $this->disconnectGoogle($token, $connection);
        } elseif ($provider === 'microsoft') {
            $this->disconnectMicrosoft($token, $connection);
        }

        // BUG FIX: $token->update(['access_token' => null, ...]) was
        // silently NOT nulling access_token/refresh_token — Eloquent's
        // 'encrypted' cast has a known quirk where its dirty-tracking
        // doesn't always register a change to null correctly, so those
        // two columns were being left out of the actual SQL UPDATE
        // entirely (confirmed: disconnected_at DID save, the encrypted
        // columns did not). A raw query-builder update bypasses the
        // cast/dirty-tracking layer entirely, writing NULL directly —
        // no ambiguity possible.
        \Illuminate\Support\Facades\DB::connection($connection)->table('oauth_tokens')
            ->where('id', $token->id)
            ->update([
                'access_token' => null,
                'refresh_token' => null,
                'scope' => null,
                'disconnected_at' => now(),
            ]);

        return response()->json(['message' => 'Disconnected.']);
    }

    protected function disconnectGoogle(OAuthToken $token, string $connection): void
    {
        try {
            $this->gmailWatch->stopWatch($token);
        } catch (\RuntimeException $e) {
            Log::warning('Gmail watch stop failed on disconnect', ['user_id' => $token->user_id, 'message' => $e->getMessage()]);
        }

        try {
            $this->google->revokeToken($token->refresh_token ?? $token->access_token);
        } catch (\RuntimeException $e) {
            Log::warning('Google token revocation failed on disconnect', ['user_id' => $token->user_id, 'message' => $e->getMessage()]);
        }

        GmailWatchState::on($connection)->where('user_id', $token->user_id)->delete();
    }

    protected function disconnectMicrosoft(OAuthToken $token, string $connection): void
    {
        $state = MicrosoftSubscriptionState::on($connection)->where('user_id', $token->user_id)->first();

        if ($state) {
            try {
                $this->microsoftSubscription->deleteSubscription($token, $state->subscription_id);
            } catch (\RuntimeException $e) {
                Log::warning('Microsoft subscription deletion failed on disconnect', ['user_id' => $token->user_id, 'message' => $e->getMessage()]);
            }
        }

        // Microsoft's revocation model differs from Google's — there's
        // no single "revoke this token" endpoint for individual apps;
        // the closest equivalent (revoking ALL of a user's app sessions
        // tenant-wide) is an admin-level operation, not something this
        // app can or should do on a personal disconnect. Nulling our
        // stored copy (below, in the caller) is what PRD Section 131
        // actually asks for — "deletes stored access/refresh tokens"
        // is about OUR storage, which this satisfies.
        MicrosoftSubscriptionState::on($connection)->where('user_id', $token->user_id)->delete();
    }
}
