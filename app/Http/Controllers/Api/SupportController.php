<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    /**
     * Identity for the Tawk.to live-chat widget, so a logged-in user never has to type their name and email
     * again. When Tawk "Secure mode" is on, Tawk only trusts name/email that come with an HMAC-SHA256 of the
     * email made with the property's API key. That key is a secret, so the hash is made here on the server
     * and the key never reaches the browser.
     *
     * `hash` is null when TAWKTO_API_KEY is not configured (fine while Secure mode is off in Tawk).
     */
    public function tawkIdentity(Request $request)
    {
        $user = $request->user();
        $apiKey = config('services.tawk.api_key');

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'hash' => $apiKey ? hash_hmac('sha256', $user->email, $apiKey) : null,
        ]);
    }
}
