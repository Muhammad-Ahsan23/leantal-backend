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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    protected const ACCESS_TOKEN_MINUTES = 15;
    protected const RESEND_COOLDOWN_SECONDS = 60;
    protected const RESEND_MAX_PER_WINDOW = 1;     // ONE resend per login; the count starts again at the next password step
    protected const RESEND_WINDOW_MINUTES = 30;    // how long after the password step a resend is still allowed

    // Wrong verification codes: the per-email counter fills after this many, and stays full until a new code is issued.
    protected const OTP_VERIFY_MAX = 3;
    protected const OTP_VERIFY_DECAY = 600;

    // Failed password attempts are counted three ways, and a login is refused when ANY of them is full:
    //  - this email from this IP     (stops guessing one account from one machine)
    //  - this email from anywhere    (stops guessing one account from many machines; higher, so a stranger cannot lock you out quickly)
    //  - this IP across all emails   (stops one machine trying many accounts - credential stuffing; generous, an office shares one IP)
    protected const LOGIN_PAIR_MAX = 3;            // the 3rd wrong password locks this email + IP for LOGIN_LOCK_SECONDS
    protected const LOGIN_PAIR_DECAY = 900;
    protected const LOGIN_LOCK_SECONDS = 900;      // 15 minutes, counted from the 3rd wrong password
    protected const LOGIN_EMAIL_MAX = 20;          // per 15 minutes
    protected const LOGIN_EMAIL_DECAY = 900;
    protected const LOGIN_IP_MAX = 30;             // per 10 minutes
    protected const LOGIN_IP_DECAY = 600;

    public function __construct(
        protected CaptchaService $captcha,
        protected RegionRoutingRepository $routing,
        protected OtpService $otp,
        protected RefreshTokenService $refreshTokens,
    ) {}

    protected function lockKey(string $pairKey): string
    {
        return 'lock:'.$pairKey;
    }

    /** "15 minutes" / "1 minute", rounded UP so the user is never told to retry too early. */
    protected function waitText(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes.' '.($minutes === 1 ? 'minute' : 'minutes');
    }

    /** Seconds until a login may be tried again, or 0 when none of the limits is full. */
    protected function loginLockedFor(string $pairKey, string $emailKey, string $ipKey): int
    {
        $wait = 0;

        // The 3-wrong-passwords lock for this email + IP.
        $lockedUntil = (int) Cache::get($this->lockKey($pairKey), 0);
        if ($lockedUntil > time()) {
            $wait = $lockedUntil - time();
        }

        foreach ([[$pairKey, self::LOGIN_PAIR_MAX], [$emailKey, self::LOGIN_EMAIL_MAX], [$ipKey, self::LOGIN_IP_MAX]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = max($wait, RateLimiter::availableIn($key));
            }
        }

        return $wait;
    }

    protected function hitLoginFailure(string $pairKey, string $emailKey, string $ipKey): void
    {
        RateLimiter::hit($pairKey, self::LOGIN_PAIR_DECAY);
        RateLimiter::hit($emailKey, self::LOGIN_EMAIL_DECAY);
        RateLimiter::hit($ipKey, self::LOGIN_IP_DECAY);

        // The 3rd wrong password starts a full 15-minute lock (not "what is left of a window that began at the 1st").
        if (RateLimiter::attempts($pairKey) >= self::LOGIN_PAIR_MAX) {
            Cache::put($this->lockKey($pairKey), time() + self::LOGIN_LOCK_SECONDS, self::LOGIN_LOCK_SECONDS);
            RateLimiter::clear($pairKey);
        }
    }

    /**
     * STEP 1 (PRD Section 15, steps 1-5): email + password + CAPTCHA ->
     * validate -> email a one-time code. Does NOT log the user in yet.
     */
    public function login(LoginRequest $request)
    {
        $email = strtolower(trim($request->input('email')));

        $ip = (string) $request->ip();
        $pairKey = 'login:'.$email.'|'.$ip;
        $emailKey = 'login-email:'.$email;
        $ipKey = 'login-ip:'.$ip;

        $wait = $this->loginLockedFor($pairKey, $emailKey, $ipKey);
        if ($wait > 0) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again in '.$this->waitText($wait).'.',
                'retry_after' => $wait,
            ], 429)->header('Retry-After', (string) $wait);
        }

        if (!$this->captcha->verify($request->input('captcha_token'))) {
            return response()->json(['message' => 'CAPTCHA verification failed.'], 422);
        }

        $region = $this->routing->findRegionByEmail($email);

        if (!$region) {
            $this->hitLoginFailure($pairKey, $emailKey, $ipKey);
            return $this->invalidCredentials();
        }

        $connection = RegionResolver::connectionFor($region);
        $user = User::on($connection)->where('email', $email)->first();

        if (!$user || $user->status !== 'active') {
            $this->hitLoginFailure($pairKey, $emailKey, $ipKey);
            return $this->invalidCredentials();
        }

        if (!Hash::check($request->input('password'), $user->password_hash)) {
            $this->hitLoginFailure($pairKey, $emailKey, $ipKey);
            Log::info('Failed login attempt', ['email' => $email]);
            return $this->invalidCredentials();
        }

        // PRD Section 119 — "Suspension halts user login... while
        // keeping all historical data intact." Checked AFTER password
        // verification (not before) so a wrong-password attempt against
        // a suspended company still gets the generic "invalid
        // credentials" response — not leaking suspension status to
        // someone who hasn't proven they know valid credentials yet.
        $suspendedAt = DB::connection($connection)->table('companies')->where('id', $user->company_id)->value('suspended_at');
        if ($suspendedAt) {
            $this->hitLoginFailure($pairKey, $emailKey, $ipKey);
            return response()->json(['message' => 'This account has been suspended. Please contact support.'], 403);
        }

        // PRD Section 71 — a deleted company must not be able to log in again.
        if ($this->companyIsDeleted($connection, $user->company_id)) {
            $this->hitLoginFailure($pairKey, $emailKey, $ipKey);
            return response()->json(['message' => 'This company account has been deleted.'], 403);
        }

        // A correct password resets this person's own counters. The IP counter is NOT reset: otherwise an attacker could log
        // into an account of their own between guesses and start again from zero.
        RateLimiter::clear($pairKey);
        RateLimiter::clear($emailKey);

        // A fresh password step starts a fresh verification: one new "Resend code" and a clean wrong-code count.
        RateLimiter::clear('otp-resend:'.$email);
        RateLimiter::clear('otp-resend-window:'.$email);
        RateLimiter::clear('otp:'.$email);

        $otpSendKey = 'otp-send:'.$email;
        if (RateLimiter::tooManyAttempts($otpSendKey, 3)) {
            return response()->json([
                'message' => 'Too many verification codes requested. Please wait a few minutes and try again.',
            ], 429);
        }
        RateLimiter::hit($otpSendKey, 300);

        $this->sendOtpMail($email, $this->otp->generate($user, $connection));

        return response()->json([
            'message' => 'A verification code has been sent to your email.',
            'email' => $email,
            'cooldown_seconds' => self::RESEND_COOLDOWN_SECONDS,
            'expires_in_seconds' => OtpService::EXPIRY_MINUTES * 60,
        ]);
    }

    /**
     * "Resend code" on the verification screen. The caller has no password here, so this only works while a
     * login is actually pending: the user's latest code must be unused and issued within the last
     * RESEND_WINDOW_MINUTES. Otherwise they have to start again from the password step.
     * The reply is identical whether or not the email exists (no account enumeration).
     * Limits: one resend per RESEND_COOLDOWN_SECONDS, and at most RESEND_MAX_PER_WINDOW per 10 minutes.
     */
    public function resendOtp(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        $email = strtolower(trim($request->input('email')));

        $cooldownKey = 'otp-resend:'.$email;
        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            $wait = RateLimiter::availableIn($cooldownKey);
            return response()->json([
                'message' => "Please wait {$wait} seconds before requesting another code.",
                'retry_after' => $wait,
            ], 429);
        }

        $windowKey = 'otp-resend-window:'.$email;
        if (RateLimiter::tooManyAttempts($windowKey, self::RESEND_MAX_PER_WINDOW)) {
            return response()->json([
                'message' => 'No more codes can be sent for this login. Please log in again.',
                'resends_left' => 0,
            ], 429);
        }

        // Count every attempt (even for unknown emails) so this cannot be used to probe or to spam.
        RateLimiter::hit($cooldownKey, self::RESEND_COOLDOWN_SECONDS);
        RateLimiter::hit($windowKey, self::RESEND_WINDOW_MINUTES * 60);

        $response = response()->json([
            'message' => 'If a verification is pending for this email, a new code has been sent.',
            'cooldown_seconds' => self::RESEND_COOLDOWN_SECONDS,
            'expires_in_seconds' => OtpService::EXPIRY_MINUTES * 60,
            'resends_left' => max(0, self::RESEND_MAX_PER_WINDOW - RateLimiter::attempts($windowKey)),
        ]);

        $region = $this->routing->findRegionByEmail($email);
        if (!$region) {
            return $response;
        }

        $connection = RegionResolver::connectionFor($region);
        $user = User::on($connection)->where('email', $email)->first();
        if (!$user || $user->status !== 'active') {
            return $response;
        }

        if (!$this->otp->hasPendingLogin($user, $connection, self::RESEND_WINDOW_MINUTES)) {
            return $response;
        }

        $suspendedAt = DB::connection($connection)->table('companies')->where('id', $user->company_id)->value('suspended_at');
        if ($suspendedAt || $this->companyIsDeleted($connection, $user->company_id)) {
            return $response;
        }

        // The new code starts with a clean wrong-code count.
        RateLimiter::clear('otp:'.$email);
        $this->sendOtpMail($email, $this->otp->generate($user, $connection));

        return $response;
    }

    protected function sendOtpMail(string $email, string $code): void
    {
        $minutes = OtpService::EXPIRY_MINUTES;
        Mail::raw("Your LeanTal verification code is: {$code}\n\nThis code expires in {$minutes} minutes.", function ($message) use ($email) {
            $message->to($email)->subject('Your LeanTal verification code');
        });
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
        if (RateLimiter::tooManyAttempts($throttleKey, self::OTP_VERIFY_MAX)) {
            return response()->json([
                'message' => 'Too many attempts. Please request a new code.',
            ], 429);
        }

        $region = $this->routing->findRegionByEmail($email);
        if (!$region) {
            RateLimiter::hit($throttleKey, self::OTP_VERIFY_DECAY);
            return $this->invalidOtp();
        }

        $connection = RegionResolver::connectionFor($region);
        $user = User::on($connection)->where('email', $email)->first();

        if (!$user || $user->status !== 'active') {
            RateLimiter::hit($throttleKey, self::OTP_VERIFY_DECAY);
            return $this->invalidOtp();
        }

        if (!$this->otp->verify($user, $connection, $request->input('otp'))) {
            RateLimiter::hit($throttleKey, self::OTP_VERIFY_DECAY);
            return $this->invalidOtp();
        }

        RateLimiter::clear($throttleKey);

        // Same guard as login(): the company may have been deleted between the
        // password step and the OTP step.
        if ($this->companyIsDeleted($connection, $user->company_id)) {
            return response()->json(['message' => 'This company account has been deleted.'], 403);
        }

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
            // Tell the old device WHY it is being signed out when a newer login of the same account replaced it.
            $replaced = $this->refreshTokens->wasReplaced($request->input('refresh_token'));

            return response()->json([
                'message' => $replaced
                    ? 'You were signed out because your account was signed in on another device.'
                    : 'Your session has expired. Please log in again.',
                'code' => $replaced ? 'session_replaced' : 'session_expired',
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

    /**
     * One device at a time (all roles): a new login ends the user's other sessions, so the old device is signed out.
     * A Super Admin impersonation token is a separate support session and is left alone.
     * The user row is locked so two logins at the same moment cannot both survive.
     */
    protected function endOtherSessions(User $user, string $connection): void
    {
        DB::connection($connection)->transaction(function () use ($user, $connection) {
            User::on($connection)->whereKey($user->id)->lockForUpdate()->first();

            $user->tokens()->where('name', '!=', 'impersonation')->delete();
            $this->refreshTokens->revokeOtherSessions($connection, $user->id);
        });
    }

    protected function issueTokens(User $user, string $region, string $connection, Request $request)
    {
        $this->endOtherSessions($user, $connection);

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

    protected function companyIsDeleted(string $connection, string $companyId): bool
    {
        return (bool) DB::connection($connection)->table('companies')->where('id', $companyId)->value('deleted_at');
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
