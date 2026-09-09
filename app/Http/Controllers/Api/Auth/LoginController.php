<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\OtpService;
use App\Services\RefreshTokenService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    // Access tokens are short-lived on purpose — the refresh token is what
    // actually carries the 30-day session (Section 15); the access token
    // just limits how long a stolen one stays useful before it expires and
    // a refresh is required.
    protected const ACCESS_TOKEN_MINUTES = 15;

    public function __construct(
        protected CaptchaService $captcha,
        protected RegionRoutingRepository $routing,
        protected OtpService $otp,
        protected RefreshTokenService $refreshTokens,
    ) {}

    /**
     * STEP 1 (PRD Section 15, steps 1-5): email + password + CAPTCHA ->
     * validate -> email a one-time code. Does NOT log the user in yet.
     */
    public function login(LoginRequest $request)
    {
        $email = strtolower(trim($request->input('email')));

        $throttleKey = 'login:'.$email;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again in a minute.',
            ], 429);
        }

        if (!$this->captcha->verify($request->input('captcha_token'))) {
            return response()->json(['message' => 'CAPTCHA verification failed.'], 422);
        }

        $region = $this->routing->findRegionByEmail($email);

        if (!$region) {
            RateLimiter::hit($throttleKey, 60);
            return $this->invalidCredentials();
        }

        $connection = RegionResolver::connectionFor($region);
        $user = User::on($connection)->where('email', $email)->first();

        if (!$user || $user->status !== 'active') {
            RateLimiter::hit($throttleKey, 60);
            return $this->invalidCredentials();
        }

        if (!Hash::check($request->input('password'), $user->password_hash)) {
            RateLimiter::hit($throttleKey, 60);
            Log::info('Failed login attempt', ['email' => $email]);
            return $this->invalidCredentials();
        }

        RateLimiter::clear($throttleKey);

        $otpSendKey = 'otp-send:'.$email;
        if (RateLimiter::tooManyAttempts($otpSendKey, 3)) {
            return response()->json([
                'message' => 'Too many verification codes requested. Please wait a few minutes and try again.',
            ], 429);
        }
        RateLimiter::hit($otpSendKey, 300);

        $code = $this->otp->generate($user, $connection);

        Mail::raw("Your LeanTal verification code is: {$code}\n\nThis code expires in 10 minutes.", function ($message) use ($email) {
            $message->to($email)->subject('Your LeanTal verification code');
        });

        return response()->json([
            'message' => 'A verification code has been sent to your email.',
            'email' => $email,
        ]);
    }

    /**
     * STEP 2 (PRD Section 15, steps 6-8): verify the emailed code, then
     * actually log the user in — issues a short-lived access token plus a
     * rotating refresh token that carries the real 30-day session.
     */
    public function verifyOtp(VerifyOtpRequest $request)
    {
        $email = strtolower(trim($request->input('email')));

        $throttleKey = 'otp:'.$email;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many attempts. Please request a new code.',
            ], 429);
        }

        $region = $this->routing->findRegionByEmail($email);
        if (!$region) {
            RateLimiter::hit($throttleKey, 60);
            return $this->invalidOtp();
        }

        $connection = RegionResolver::connectionFor($region);
        $user = User::on($connection)->where('email', $email)->first();

        if (!$user || $user->status !== 'active') {
            RateLimiter::hit($throttleKey, 60);
            return $this->invalidOtp();
        }

        if (!$this->otp->verify($user, $connection, $request->input('otp'))) {
            RateLimiter::hit($throttleKey, 60);
            return $this->invalidOtp();
        }

        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();
        Log::info('Successful login', ['email' => $email]);

        return $this->issueTokens($user, $region, $connection, $request);
    }

    /**
     * Exchanges a valid, unused refresh token for a new access token AND a
     * new refresh token (rotation) — the old refresh token stops working
     * the moment this succeeds. Called by the frontend automatically
     * whenever the 15-minute access token expires, invisibly to the user.
     */
    public function refresh(RefreshTokenRequest $request)
    {
        $result = $this->refreshTokens->rotate(
            $request->input('refresh_token'),
            $request->userAgent(),
            $request->ip(),
        );

        if (!$result) {
            return response()->json([
                'message' => 'Your session has expired. Please log in again.',
            ], 401);
        }

        $accessTokenResult = $result['user']->createToken(
            'access',
            ['*'],
            now()->addMinutes(self::ACCESS_TOKEN_MINUTES)
        );
        $accessTokenResult->accessToken->forceFill(['region' => $result['region']])->save();
        $accessToken = $accessTokenResult->plainTextToken;

        return response()->json([
            'access_token' => $accessToken,
            'refresh_token' => $result['refresh_token'],
            'expires_in' => self::ACCESS_TOKEN_MINUTES * 60,
        ]);
    }

    /**
     * Logs out the CURRENT device only. Revokes the access token used in
     * this request, and — if the client sends it — the paired refresh
     * token, so this one device's session is fully ended.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        if ($request->filled('refresh_token')) {
            $this->refreshTokens->revoke($request->input('refresh_token'));
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * PRD Section 15 — "Logout from all devices." Revokes every access
     * token AND every refresh token (session row) belonging to this user.
     */
    public function logoutAll(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $user->tokens()->delete();
        $this->refreshTokens->revokeAllForUser($connection, $user->id);

        return response()->json(['message' => 'Logged out from all devices.']);
    }

    protected function issueTokens(User $user, string $region, string $connection, Request $request)
    {
        $accessTokenResult = $user->createToken(
            'access',
            ['*'],
            now()->addMinutes(self::ACCESS_TOKEN_MINUTES)
        );
        $accessTokenResult->accessToken->forceFill(['region' => $region])->save();
        $accessToken = $accessTokenResult->plainTextToken;

        $refreshToken = $this->refreshTokens->generate($user, $region, $connection, $request->userAgent(), $request->ip());

        return response()->json([
            'message' => 'Logged in successfully.',
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => self::ACCESS_TOKEN_MINUTES * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company_id' => $user->company_id,
            ],
        ]);
    }

    protected function invalidCredentials()
    {
        return response()->json(['message' => 'Invalid email or password.'], 401);
    }

    protected function invalidOtp()
    {
        return response()->json(['message' => 'Invalid or expired verification code.'], 401);
    }
}
