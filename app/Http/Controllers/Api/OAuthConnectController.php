<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OAuthToken;
use App\Services\GmailWatchService;
use App\Services\GoogleCalendarOAuthService;
use App\Services\MicrosoftOAuthService;
use App\Services\MicrosoftSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OAuthConnectController extends Controller
{
    public function __construct(
        protected GoogleCalendarOAuthService $google,
        protected GmailWatchService $gmailWatch,
        protected MicrosoftOAuthService $microsoft,
        protected MicrosoftSubscriptionService $microsoftSubscription,
    ) {}

    /**
     * PRD Section 113 — starts the connect flow for the CURRENT user.
     * Our API is Bearer-token authenticated, but the callback below is
     * a plain browser redirect (no Authorization header reaches it) —
     * 'state' is how the callback learns WHICH user/connection this
     * belongs to. Stored server-side (cache, 10-min TTL, random+
     * unguessable) rather than putting user_id directly in the URL, so
     * a tampered state value can't be pointed at a different account.
     */
    public function connectGoogle(Request $request)
    {
        $user = $request->user();
        $state = Str::random(40);

        Cache::put("oauth_state:{$state}", [
            'user_id' => $user->id,
            'connection' => $user->getConnectionName(),
        ], now()->addMinutes(10));

        return response()->json(['auth_url' => $this->google->buildAuthUrl($state)]);
    }

    /**
     * Public route — Google redirects the browser here directly, no
     * Bearer token available. Identity comes entirely from 'state'.
     */
    public function googleCallback(Request $request)
    {
        $frontendUrl = config('services.frontend_url');
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        if ($error) {
            Log::info('Google OAuth: user denied or cancelled', ['error' => $error]);
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=denied");
        }

        if (!$code || !$state) {
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=malformed");
        }

        $stateData = Cache::pull("oauth_state:{$state}"); // pull = get + forget, single-use

        if (!$stateData) {
            Log::warning('Google OAuth callback: unknown or expired state', ['state' => $state]);
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=expired");
        }

        try {
            $tokens = $this->google->exchangeCodeForTokens($code);
        } catch (\RuntimeException $e) {
            Log::error('Google OAuth token exchange failed', ['message' => $e->getMessage()]);
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=exchange_failed");
        }

        $connection = $stateData['connection'];

        $providerEmail = null;
        try {
            $providerEmail = $this->google->getUserEmail($tokens['access_token']);
        } catch (\RuntimeException $e) {
            Log::warning('Failed to fetch connected Google account email', ['message' => $e->getMessage()]);
        }

        $token = OAuthToken::on($connection)->updateOrCreate(
            ['user_id' => $stateData['user_id'], 'provider' => 'google'],
            [
                'provider_email' => $providerEmail,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'scope' => $tokens['scope'] ?? null,
                'connected_at' => now(),
                'expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
                'disconnected_at' => null,
            ]
        );

        try {
            $this->gmailWatch->registerWatch($token, $connection);
        } catch (\RuntimeException $e) {
            Log::warning('Gmail watch registration failed on connect', ['message' => $e->getMessage()]);
        }

        return redirect("{$frontendUrl}/settings/integrations?connected=true&provider=google");
    }

    /**
     * PRD Section 54 — Outlook/Microsoft 365 connect, same state-token
     * pattern as connectGoogle().
     */
    public function connectMicrosoft(Request $request)
    {
        $user = $request->user();
        $state = Str::random(40);

        Cache::put("oauth_state:{$state}", [
            'user_id' => $user->id,
            'connection' => $user->getConnectionName(),
        ], now()->addMinutes(10));

        return response()->json(['auth_url' => $this->microsoft->buildAuthUrl($state)]);
    }

    public function microsoftCallback(Request $request)
    {
        $frontendUrl = config('services.frontend_url');
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        if ($error) {
            Log::info('Microsoft OAuth: user denied or cancelled', ['error' => $error]);
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=denied");
        }

        if (!$code || !$state) {
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=malformed");
        }

        $stateData = Cache::pull("oauth_state:{$state}");

        if (!$stateData) {
            Log::warning('Microsoft OAuth callback: unknown or expired state', ['state' => $state]);
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=expired");
        }

        try {
            $tokens = $this->microsoft->exchangeCodeForTokens($code);
        } catch (\RuntimeException $e) {
            Log::error('Microsoft OAuth token exchange failed', ['message' => $e->getMessage()]);
            return redirect("{$frontendUrl}/settings/integrations?connected=false&reason=exchange_failed");
        }

        $connection = $stateData['connection'];

        $providerEmail = null;
        try {
            $providerEmail = $this->microsoft->getUserEmail($tokens['access_token']);
        } catch (\RuntimeException $e) {
            Log::warning('Failed to fetch connected Microsoft account email', ['message' => $e->getMessage()]);
        }

        $token = OAuthToken::on($connection)->updateOrCreate(
            ['user_id' => $stateData['user_id'], 'provider' => 'microsoft'],
            [
                'provider_email' => $providerEmail,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'scope' => $tokens['scope'] ?? null,
                'connected_at' => now(),
                'expires_at' => now()->addSeconds($tokens['expires_in'] ?? 3600),
                'disconnected_at' => null,
            ]
        );

        try {
            $this->microsoftSubscription->registerSubscription($token, $connection);
        } catch (\RuntimeException $e) {
            Log::warning('Microsoft subscription registration failed on connect', ['message' => $e->getMessage()]);
        }

        return redirect("{$frontendUrl}/settings/integrations?connected=true&provider=microsoft");
    }
}
