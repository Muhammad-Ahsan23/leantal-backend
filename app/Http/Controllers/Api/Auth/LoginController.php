<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\OtpService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class LoginController extends Controller
{
    public function __construct(
        protected CaptchaService $captcha,
        protected RegionRoutingRepository $routing,
        protected OtpService $otp,
    ) {}

    /**
     * STEP 1 (PRD Section 15, steps 1-5): email + password + CAPTCHA ->
     * validate -> email a one-time code. Does NOT log the user in yet.
     */
    public function login(LoginRequest $request)
    {
        $email = strtolower(trim($request->input('email')));

        // "Rate-limit login attempts" (Section 15) — keyed by email, not just
        // IP, so one attacker can't brute-force a single account from many IPs
        // without also tripping this.
        $throttleKey = 'login:'.$email;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many login attempts. Please try again in a minute.',
            ], 429);
        }

        if (!$this->captcha->verify($request->input('captcha_token'))) {
            return response()->json(['message' => 'CAPTCHA verification failed.'], 422);
        }

        // Login has no company selector — region must be resolved from the
        // email alone via routing_db (see RegionRoutingRepository).
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
            Log::info('Failed login attempt', ['email' => $email]); // Section 15: "Log security events"
            return $this->invalidCredentials();
        }

        RateLimiter::clear($throttleKey);

        $code = $this->otp->generate($user, $connection);

        // Sent via SMTP (Mailtrap, for now — real transport TBD once
        // Amazon SES per project notes is wired up). Mail::raw() keeps
        // this simple for now; swap for a Mailable + template later if
        // the email needs richer formatting (branding, HTML layout, etc).
        Mail::raw("Your LeanTal verification code is: {$code}\n\nThis code expires in 10 minutes.", function ($message) use ($email) {
            $message->to($email)->subject('Your LeanTal verification code');
        });

        return response()->json([
            'message' => 'A verification code has been sent to your email.',
            'email' => $email, // client needs to resubmit this in step 2
        ]);
    }

    /**
     * STEP 2 (PRD Section 15, steps 6-8): verify the emailed code, then
     * actually log the user in.
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

        // Session/token: using Sanctum's own token issuance + revocation
        // (native "logout from all devices" via $user->tokens()->delete())
        // rather than our bespoke `sessions` table — see MIGRATION notes.
        // Section 15's 30-day lifetime is set in config/sanctum.php.
        $token = $user->createToken('login')->plainTextToken;

        return response()->json([
            'message' => 'Logged in successfully.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'company_id' => $user->company_id,
            ],
        ]);
    }

    /**
     * Logs out the CURRENT device only — revokes just the token used in
     * this request, leaving other logged-in devices/sessions untouched.
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * PRD Section 15 — "Logout from all devices." Revokes every token
     * belonging to this user, ending every active session everywhere.
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out from all devices.']);
    }

    protected function invalidCredentials()
    {
        // Deliberately generic — never reveal whether the email exists,
        // whether the password was wrong, or whether the account is inactive.
        return response()->json(['message' => 'Invalid email or password.'], 401);
    }

    protected function invalidOtp()
    {
        return response()->json(['message' => 'Invalid or expired verification code.'], 401);
    }
}
