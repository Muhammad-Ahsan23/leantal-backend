<?php

namespace App\Http\Controllers\Api\Public;

use App\Exceptions\DuplicateApplicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SubmitApplicationRequest;
use App\Models\ApplicationQuestion;
use App\Models\Job;
use App\Services\ApplyOtpService;
use App\Services\CaptchaService;
use App\Services\PublicApplicationService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use App\Support\CacheVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PublicCareersController extends Controller
{
    protected const PUBLIC_JOB_FIELDS = [
        'id', 'title', 'department', 'description', 'location', 'location_type',
        'employment_type', 'compensation_enabled', 'compensation_type',
        'compensation_value', 'about_role', 'key_responsibilities', 'requirements', 'about_company', 'benefits', 'team_info',
        'additional_sections', 'published_at',
    ];

    public function __construct(
        protected RegionRoutingRepository $routing,
        protected PublicApplicationService $applications,
        protected \App\Services\JobStructuredDataService $structuredData,
        protected CaptchaService $captcha,
        protected ApplyOtpService $applyOtp,
    ) {}

    // How long a public page is kept, and why it is safe: every change that shows on these pages (a job created, edited,
    // published, paused, closed or deleted, or its application questions changed) bumps the company's "jobs" cache version
    // (CacheVersion), which makes the old copy unreachable at once. Company name / website / description and department
    // edits are not tied to that version, so those show up within this many seconds at the latest.
    protected const PAGE_CACHE_SECONDS = 60;

    public function index(Request $request, string $companySlug)
    {
        $company = $this->resolveCompany($companySlug);
        if (!$company) {
            return response()->json(['message' => 'Careers page not found.'], 404);
        }

        $connection = RegionResolver::connectionFor($company['region']);

        // Only the plain page (no search / filter) is cached: that is what nearly every visitor opens first. A request with
        // free text in it is answered live, so nobody can fill the cache with endless made-up searches.
        $filtered = false;
        foreach (['department', 'location_type', 'employment_type', 'location', 'search'] as $filter) {
            $value = $request->query($filter);
            if ($value !== null && (!is_scalar($value) || trim((string) $value) !== '')) {
                $filtered = true;
                break;
            }
        }

        if ($filtered) {
            return response()->json($this->buildIndexPayload($request, $company, $connection));
        }

        $json = $this->cachedPayload("index:{$company['company_id']}", $company['company_id'], fn () => $this->buildIndexPayload($request, $company, $connection));

        return $this->jsonString($json);
    }

    protected function buildIndexPayload(Request $request, array $company, string $connection): array
    {
        $query = Job::on($connection)
            ->where('company_id', $company['company_id'])
            ->where('status', 'published');

        if ($department = $request->query('department')) {
            $query->where('department', $department);
        }
        if ($locationType = $request->query('location_type')) {
            $query->where('location_type', $locationType);
        }
        if ($employmentType = $request->query('employment_type')) {
            $query->where('employment_type', $employmentType);
        }
        if ($location = trim((string) $request->query('location', ''))) {
            // PRD Section 46 — Location is free text ("Bengaluru, India", "London, UK").
            $query->where('location', 'ilike', '%'.$this->escapeLike($location).'%');
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('title', 'ilike', $like)->orWhere('department', 'ilike', $like);
            });
        }

        $jobs = $query->orderByDesc('published_at')->get(self::PUBLIC_JOB_FIELDS);

        // PRD Section 45 — the careers page header: company name, a simple description, and the company
        // name links to the stored website. Only these three public fields are exposed.
        $companyRow = DB::connection($connection)->table('companies')
            ->where('id', $company['company_id'])
            ->first(['name', 'website', 'careers_description']);

        // PRD Section 46 — the Department filter lists the departments the company created, not just
        // the ones that happen to have a published job right now.
        $departments = DB::connection($connection)->table('departments')
            ->where('company_id', $company['company_id'])
            ->orderBy('name')
            ->pluck('name')
            ->values();

        return [
            'jobs' => $jobs,
            'company' => [
                'name' => $companyRow->name ?? null,
                'website' => $this->safeWebsite($companyRow->website ?? null),
                'description' => $companyRow->careers_description ?? null,
            ],
            'departments' => $departments,
        ];
    }

    /** Only http(s) links are ever handed to the public page (never javascript: or similar). */
    protected function safeWebsite(?string $website): ?string
    {
        $website = trim((string) $website);
        if ($website === '') {
            return null;
        }
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        return filter_var($website, FILTER_VALIDATE_URL) ? $website : null;
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function showJob(string $companySlug, string $jobId)
    {
        $company = $this->resolveCompany($companySlug);
        if (!$company) {
            return response()->json(['message' => 'Careers page not found.'], 404);
        }

        // Job ids are UUIDs: anything else (a mistyped link, "undefined") cannot exist, and must not reach the database.
        if (!Str::isUuid($jobId)) {
            return response()->json(['message' => 'Job not found or no longer accepting applications.'], 404);
        }

        $connection = RegionResolver::connectionFor($company['region']);

        // A job that is not (or no longer) published gives null here: that answer is never cached.
        $json = $this->cachedPayload("job:{$company['company_id']}:{$jobId}", $company['company_id'], fn () => $this->buildJobPayload($companySlug, $company, $jobId, $connection));

        if ($json === null) {
            return response()->json(['message' => 'Job not found or no longer accepting applications.'], 404);
        }

        return $this->jsonString($json);
    }

    protected function buildJobPayload(string $companySlug, array $company, string $jobId, string $connection): ?array
    {
        $job = Job::on($connection)
            ->where('company_id', $company['company_id'])
            ->where('status', 'published')
            // application_fields tells the apply form which fields to show / require for this job.
            ->find($jobId, array_merge(self::PUBLIC_JOB_FIELDS, ['application_fields']));

        if (!$job) {
            return null;
        }

        $questions = ApplicationQuestion::on($connection)
            ->where('job_id', $jobId)
            ->orderBy('order')
            ->get(['id', 'question', 'type', 'required', 'options']);

        $companyModel = \App\Models\Company::on($connection)->find($company['company_id']);
        $canonicalUrl = $this->structuredData->canonicalUrl($companySlug, $job->id);

        return [
            'job' => $job,
            'questions' => $questions,
            'structured_data' => $this->structuredData->build($job, $companySlug, $companyModel->name ?? '', $companyModel->country_code ?? null, $this->safeWebsite($companyModel->website ?? null)),
            'canonical_url' => $canonicalUrl,
        ];
    }

    /**
     * The finished JSON text of a public page, from the cache when it is there. The builder runs only on a miss, and a null
     * answer (job not found) is not stored. The text itself is stored, so what visitors get is byte for byte what the
     * builder produced. If the cache is unavailable the page is simply built live: caching must never take it down.
     */
    protected function cachedPayload(string $name, string $companyId, \Closure $build): ?string
    {
        $store = null;
        $key = null;
        $json = null;

        try {
            $store = Cache::store(config('cache.public_careers_store')); // null = the app's normal cache store
            $key = "public_careers:{$name}:v".CacheVersion::get("company:{$companyId}:jobs");
            $cached = $store->get($key);
            $json = is_string($cached) ? $cached : null;
        } catch (\Throwable $e) {
            report($e);
            $store = null;
        }

        if ($json !== null) {
            return $json;
        }

        $payload = $build();
        if ($payload === null) {
            return null;
        }

        $json = json_encode($payload);
        if ($json === false) {
            throw new \JsonException('Public page could not be encoded: '.json_last_error_msg());
        }

        if ($store !== null) {
            try {
                $store->put($key, $json, self::PAGE_CACHE_SECONDS);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $json;
    }

    protected function jsonString(string $json)
    {
        return response($json, 200, ['Content-Type' => 'application/json']);
    }

    /**
     * Step 1 of applying: CAPTCHA + email -> emails a 6-digit code to the candidate.
     * At most 3 codes per email per job every 10 minutes (the same email can still apply to other jobs).
     */
    public function sendCode(Request $request, string $companySlug, string $jobId)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'captcha_token' => ['required', 'string'],
        ]);

        $company = $this->resolveCompany($companySlug);
        if (!$company) {
            return response()->json(['message' => 'Careers page not found.'], 404);
        }

        $connection = RegionResolver::connectionFor($company['region']);

        if (DB::connection($connection)->table('companies')->where('id', $company['company_id'])->value('suspended_at')) {
            return response()->json(['message' => 'This job is no longer accepting applications.'], 404);
        }

        $job = Job::on($connection)->where('company_id', $company['company_id'])->where('status', 'published')->find($jobId, ['id', 'title']);
        if (!$job) {
            return response()->json(['message' => 'This job is no longer accepting applications.'], 404);
        }

        if (!$this->captcha->verify($data['captcha_token'])) {
            return response()->json(['message' => 'CAPTCHA verification failed. Please try again.'], 422);
        }

        $email = strtolower(trim($data['email']));
        $sendKey = 'apply-otp-send:'.sha1($email.'|'.$jobId);
        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            return response()->json(['message' => 'Too many codes requested. Please try again in a few minutes.'], 429);
        }
        RateLimiter::hit($sendKey, ApplyOtpService::EXPIRY_MINUTES * 60);

        $code = $this->applyOtp->issue($email, $jobId);
        $minutes = ApplyOtpService::EXPIRY_MINUTES;
        Mail::raw("Your LeanTal verification code is: {$code}\n\nUse it to confirm your application for {$job->title}. This code expires in {$minutes} minutes.\n\nIf you did not apply for this job, you can ignore this email.", function ($message) use ($email) {
            $message->to($email)->subject('Your LeanTal verification code');
        });

        return response()->json(['message' => 'We emailed you a 6-digit code.', 'expires_in_minutes' => $minutes]);
    }

    /**
     * PRD Section 93 (application flow) + Section 29 (knockout stays
     * silent) + Section 119 (suspended companies — "halts... job
     * application intake"). Rate-limited per email+job to blunt trivial
     * spam/abuse.
     */
    public function apply(SubmitApplicationRequest $request, string $companySlug, string $jobId)
    {
        $company = $this->resolveCompany($companySlug);
        if (!$company) {
            return response()->json(['message' => 'Careers page not found.'], 404);
        }

        $connection = RegionResolver::connectionFor($company['region']);

        // PRD Section 119 — checked before fetching the job: a suspended
        // company should never accept a new application, regardless of
        // the job's own status.
        $suspendedAt = DB::connection($connection)->table('companies')->where('id', $company['company_id'])->value('suspended_at');
        if ($suspendedAt) {
            return response()->json(['message' => 'This job is no longer accepting applications.'], 404);
        }

        $job = Job::on($connection)
            ->where('company_id', $company['company_id'])
            ->where('status', 'published')
            ->find($jobId);

        if (!$job) {
            return response()->json(['message' => 'This job is no longer accepting applications.'], 404);
        }

        $data = $request->validated();
        $email = strtolower(trim($data['email']));

        $throttleKey = 'apply:'.$email.':'.$jobId;
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            return response()->json(['message' => 'Too many attempts. Please try again later.'], 429);
        }
        RateLimiter::hit($throttleKey, 300);

        // Fields this job switched off are dropped; fields it made Required are checked here.
        $resume = $this->applications->resumeAllowed($job) ? $request->file('resume') : null;
        $data = $this->applications->applyFieldSettings($job, $data);
        $profileErrors = $this->applications->validateProfileFields($job, $data, $resume);

        $answerErrors = $this->applications->validateAnswers($job, $data['answers'] ?? [], $connection, $request->file('answer_files') ?? []);
        $answerErrors = array_merge($profileErrors, $answerErrors);
        if (!empty($answerErrors)) {
            return response()->json(['message' => 'Some required questions were not answered.', 'errors' => $answerErrors], 422);
        }

        // The emailed code is checked last, so a form mistake above never uses up one of the 3 guesses.
        $otpResult = $this->applyOtp->verify($email, $jobId, (string) $request->input('otp', ''));
        if ($otpResult !== 'ok') {
            $message = match ($otpResult) {
                'wrong' => 'That code is not correct. Please check it and try again.',
                'locked' => 'Too many incorrect codes. Please request a new code.',
                default => 'Your code has expired or was not requested. Please request a new code.',
            };

            return response()->json(['message' => $message, 'otp_error' => $otpResult], 422);
        }

        try {
            $this->applications->submit($job, $data, $connection, $resume, $request->file('answer_files') ?? []);
        } catch (DuplicateApplicationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "Thank you for applying! We've received your application and will be in touch if there's a match.",
        ], 201);
    }

    protected function resolveCompany(string $slug): ?array
    {
        $company = $this->routing->findCompanyBySlug($slug);
        if (!$company) {
            return null;
        }

        // PRD Section 71 — a deleted company's careers page must stop serving.
        $deletedAt = DB::connection(RegionResolver::connectionFor($company['region']))
            ->table('companies')->where('id', $company['company_id'])->value('deleted_at');

        return $deletedAt ? null : $company;
    }
}
