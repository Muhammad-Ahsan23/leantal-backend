<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Services\RegionResolver;
use App\Services\RefreshTokenService;
use App\Services\RegionRoutingRepository;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class PasswordResetController extends Controller
{
    public function __construct(
        protected RegionRoutingRepository $routing,
        protected PasswordResetService $resetService,
        protected RefreshTokenService $refreshTokens,
    ) {}

    /**
     * Requests a reset link. ALWAYS returns the same generic success
     * message, whether or not the email exists — this is deliberate
     * (prevents attackers from using this endpoint to discover which
     * emails have accounts).
     */
    public function forgotPassword(ForgotPasswordRequest $request)
    {
        $email = strtolower(trim($request->input('email')));

        $throttleKey = 'forgot-password:'.$email;
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return $this->genericResponse(); // even rate-limited, don't reveal anything
        }
        RateLimiter::hit($throttleKey, 300); // 5-minute window — reset requests shouldn't be frequent

        $region = $this->routing->findRegionByEmail($email);

        if ($region) {
            $connection = RegionResolver::connectionFor($region);
            $user = User::on($connection)->where('email', $email)->where('status', 'active')->first();

            if ($user) {
                // PRD Section 15: the link must open the real app (FRONTEND_URL). If the link cannot be built or
                // the email cannot be sent, the failure is logged and the response stays the same generic one
                // (no account-existence leak).
                try {
                    $token = $this->resetService->generate($user, $connection);
                    $resetUrl = FrontendUrl::to('/reset-password', ['email' => $email, 'token' => $token]);

                    Mail::raw("Click the link below to reset your LeanTal password:\n\n{$resetUrl}\n\nThis link expires in 60 minutes. If you didn't request this, you can safely ignore this email.", function ($message) use ($email) {
                        $message->to($email)->subject('Reset your LeanTal password');
                    });
                } catch (\Throwable $e) {
                    Log::error('Password reset email could not be sent', ['email' => $email, 'error' => $e->getMessage()]);

                    return $this->genericResponse();
                }

                Log::info('Password reset requested', ['email' => $email]); // Section 15: "Log security events"
            }
        }

        return $this->genericResponse();
    }

    /**
     * Consumes the token and sets a new password. Also revokes ALL of the
     * user's existing sessions (PRD Section 15: "Immediate session
     * invalidation after password/security changes").
     */
    public function reset(ResetPasswordRequest $request)
    {
        $email = strtolower(trim($request->input('email')));

        $region = $this->routing->findRegionByEmail($email);
        if (!$region) {
            return $this->invalidToken();
        }

        $connection = RegionResolver::connectionFor($region);
        $user = User::on($connection)->where('email', $email)->where('status', 'active')->first();

        if (!$user) {
            return $this->invalidToken();
        }

        if (!$this->resetService->verifyAndConsume($user, $connection, $request->input('token'))) {
            return $this->invalidToken();
        }

        $user->forceFill([
            'password_hash' => Hash::make($request->input('password')),
        ])->save();

        // Logout from all devices — Section 15 requires this immediately
        // after a password change.
        $user->tokens()->delete();
        $this->refreshTokens->revokeAllForUser($connection, $user->id); // the 30-day sessions too

        Log::info('Password reset completed', ['email' => $email]);

        return response()->json([
            'message' => 'Your password has been reset. Please log in again.',
        ]);
    }

    protected function genericResponse()
    {
        return response()->json([
            'message' => 'If an account exists with that email, a password reset link has been sent.',
        ]);
    }

    protected function invalidToken()
    {
        return response()->json(['message' => 'This reset link is invalid or has expired.'], 400);
    }
}
