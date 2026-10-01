<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\ConfirmMfaEnrollmentRequest;
use App\Http\Requests\SuperAdmin\StartMfaEnrollmentRequest;
use App\Http\Requests\SuperAdmin\SuperAdminLoginRequest;
use App\Models\SuperAdmin;
use App\Services\SuperAdminAuditService;
use App\Services\SuperAdminAuthService;
use Illuminate\Http\Request;

class SuperAdminAuthController extends Controller
{
    public function __construct(
        protected SuperAdminAuthService $auth,
        protected SuperAdminAuditService $audit,
    ) {}

    /**
     * PRD Section 145 — mandatory MFA. First-time setup: verifies the
     * password, generates a secret, returns a provisioning URI for the
     * frontend to render as a QR code. Nothing is "active" yet —
     * confirmEnrollment() below proves the admin actually captured it.
     */
    public function startMfaEnrollment(StartMfaEnrollmentRequest $request)
    {
        $data = $request->validated();

        try {
            $result = $this->auth->startMfaEnrollment($data['email'], $data['password']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function confirmMfaEnrollment(ConfirmMfaEnrollmentRequest $request)
    {
        $data = $request->validated();

        try {
            $token = $this->auth->confirmMfaEnrollment($data['email'], $data['password'], $data['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $admin = SuperAdmin::where('email', strtolower(trim($data['email'])))->first();
        $this->audit->log($admin, 'mfa_enrolled', $request->ip());
        $this->audit->log($admin, 'login', $request->ip());

        return response()->json(['token' => $token]);
    }

    public function login(SuperAdminLoginRequest $request)
    {
        $data = $request->validated();

        try {
            $token = $this->auth->login($data['email'], $data['password'], $data['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $admin = SuperAdmin::where('email', strtolower(trim($data['email'])))->first();
        $this->audit->log($admin, 'login', $request->ip());

        return response()->json(['token' => $token]);
    }

    public function logout(Request $request)
    {
        $admin = $request->attributes->get('super_admin');
        $token = $request->bearerToken();

        $this->audit->log($admin, 'logout', $request->ip());
        $this->auth->revokeToken($token);

        return response()->json(['message' => 'Logged out.']);
    }
}
