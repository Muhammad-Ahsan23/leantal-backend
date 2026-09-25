<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OAuthToken;
use Illuminate\Http\Request;

class OAuthConnectionController extends Controller
{
    /**
     * PRD Section 113 — integrations are PERSONAL (per-user), not
     * company-level — each staff member connects their own Google/
     * Outlook/Zoom account. This lists what the CURRENT user has
     * connected — never another user's tokens.
     *
     * NOTE: only list/disconnect exist right now. The actual "Connect"
     * button (OAuth authorize-redirect + callback) isn't built yet —
     * it needs a registered OAuth app (client ID/secret) per provider
     * in Google Cloud Console / Microsoft Azure / Zoom Marketplace,
     * deliberately deferred for the same reason as the Google Indexing
     * API setup (real external OAuth app registration, not something
     * this backend can do on its own). This infrastructure (the table,
     * model, list/disconnect) is ready so wiring in the actual
     * authorize/callback flow later is a small addition, not a redesign.
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

        $token->update(['disconnected_at' => now()]);

        return response()->json(['message' => 'Disconnected.']);
    }
}
