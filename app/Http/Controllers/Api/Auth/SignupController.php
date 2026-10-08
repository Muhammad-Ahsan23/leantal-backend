<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignupRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\CaptchaService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use App\Services\SlugGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SignupController extends Controller
{
    public function __construct(
        protected CaptchaService $captcha,
        protected RegionRoutingRepository $routing,
    ) {}

    public function store(SignupRequest $request)
    {
        $data = $request->validated();

        // 0a. Throttle per IP — signup creates a company, a user and sends mail, so it is the most
        //     expensive public endpoint to spam. 5 attempts per hour per IP.
        $throttleKey = 'signup:'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Too many sign-up attempts. Please try again later.',
            ], 429);
        }
        RateLimiter::hit($throttleKey, 3600);

        // 0b. One email = one account (PRD Section 15: login finds the company from the email alone).
        //     Checked BEFORE anything is created, so a taken email can never leave a half-made company.
        $ownerEmail = strtolower(trim($data['company_email']));
        if ($this->routing->findRegionByEmail($ownerEmail) !== null) {
            return response()->json([
                'message' => 'An account with this email already exists. Please log in instead.',
                'errors' => ['company_email' => ['An account with this email already exists.']],
            ], 422);
        }

        // 1. CAPTCHA (Section 14) — verified before anything else touches the DB
        if (!$this->captcha->verify($data['captcha_token'])) {
            return response()->json([
                'message' => 'CAPTCHA verification failed. Please try again.',
            ], 422);
        }

        // 2. Region + connection resolution (Section 73, client-confirmed rule)
        $region = RegionResolver::resolve($data['country_code']);
        $connection = RegionResolver::connectionFor($region);

        // 3. Global-unique slug (checked against routing_db, not the regional DB —
        //    slugs must be unique across ALL regions since jobs.leantal.com/{slug}
        //    is one shared namespace)
        $slug = SlugGenerator::forCompany($data['company_name']);

        // 4. Create Company + Owner inside a transaction ON THE RESOLVED
        //    REGIONAL CONNECTION — this is what makes data residency work:
        //    the company row, and every row ever related to it, lives in
        //    exactly one region's database.
        [$company, $owner] = DB::connection($connection)->transaction(function () use ($data, $region, $slug, $connection, $ownerEmail) {

            $company = Company::on($connection)->create([
                'name' => $data['company_name'],
                'website' => $data['company_website'],
                'location' => $data['location'],
                'country_code' => strtoupper($data['country_code']),
                'region' => $region,
                'slug' => $slug,
                'plan' => 'free',
                'subscription_status' => 'trialing',
                'trial_start' => now(),
                'trial_end' => now()->addHours(72), // Section 9 — 72-hour free trial
            ]);

            $owner = User::on($connection)->create([
                'company_id' => $company->id,
                'name' => $data['owner_name'],
                'email' => $ownerEmail,
                'password_hash' => Hash::make($data['password']), // Argon2id — see config/hashing.php
                'role' => 'owner',
                'status' => 'active',
                'mfa_enabled' => false, // completed during first login's OTP setup, not at signup
            ]);

            return [$company, $owner];
        });

        // 5. Routing DB entries — MUST happen after the regional transaction
        //    commits, so routing never points to a company that doesn't
        //    actually exist yet.
        try {
            $this->routing->recordCompany($company->id, $company->slug, $region);

            // Atomic claim: if someone signed up with this email a moment ago (race), we lose cleanly.
            if (!$this->routing->claimUserEmail($owner->email, $company->id, $region)) {
                throw new \RuntimeException('email_taken');
            }
        } catch (\Throwable $e) {
            // The regional rows are already committed — remove them, and the company routing row,
            // so a failed signup never leaves an orphan company behind.
            try {
                DB::connection($connection)->transaction(function () use ($company, $connection) {
                    User::on($connection)->where('company_id', $company->id)->delete();
                    Company::on($connection)->where('id', $company->id)->delete();
                });
                DB::connection('routing_db')->table('company_region_lookup')->where('company_id', $company->id)->delete();
            } catch (\Throwable $cleanup) {
                Log::error('Signup cleanup failed — orphan company needs manual removal', [
                    'company_id' => $company->id,
                    'error' => $cleanup->getMessage(),
                ]);
            }

            if ($e->getMessage() === 'email_taken') {
                return response()->json([
                    'message' => 'An account with this email already exists. Please log in instead.',
                    'errors' => ['company_email' => ['An account with this email already exists.']],
                ], 422);
            }

            Log::error('Signup failed while recording routing', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'We could not complete your sign-up. Please try again.'], 500);
        }

        // TODO: dispatch a queued job here to send the Owner a welcome /
        // verification email once the mail/queue infrastructure is wired up.

        return response()->json([
            'message' => 'Company created. Your 72-hour trial has started.',
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->slug,
                'region' => $company->region,
                'trial_end' => $company->trial_end,
            ],
            'owner' => [
                'id' => $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ],
        ], 201);
    }
}
