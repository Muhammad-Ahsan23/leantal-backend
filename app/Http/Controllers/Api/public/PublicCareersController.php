<?php

namespace App\Http\Controllers\Api\Public;

use App\Exceptions\DuplicateApplicationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\SubmitApplicationRequest;
use App\Models\ApplicationQuestion;
use App\Models\Job;
use App\Services\PublicApplicationService;
use App\Services\RegionResolver;
use App\Services\RegionRoutingRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class PublicCareersController extends Controller
{
    // Public-safe fields only — never leak assigned_user_id, created_by,
    // or company_id to an unauthenticated visitor.
    protected const PUBLIC_JOB_FIELDS = [
        'id', 'title', 'department', 'description', 'location', 'location_type',
        'employment_type', 'compensation_enabled', 'compensation_type',
        'compensation_value', 'about_company', 'benefits', 'team_info',
        'additional_sections', 'published_at',
    ];

    public function __construct(
        protected RegionRoutingRepository $routing,
        protected PublicApplicationService $applications,
    ) {}

    /**
     * PRD Section 44 + 92 — only PUBLISHED jobs are listed. Draft is
     * never visible; Closed/Archived stop being listed once they leave
     * Published (ASSUMPTION: Paused jobs are also hidden here — PRD
     * Section 92 allows either "visible or hidden" for Paused, this
     * picks the more conservative reading; flag for client confirmation
     * if they want Paused jobs to stay visible-but-non-applyable instead).
     */
    public function index(Request $request, string $companySlug)
    {
        $company = $this->resolveCompany($companySlug);
        if (!$company) {
            return response()->json(['message' => 'Careers page not found.'], 404);
        }

        $connection = RegionResolver::connectionFor($company['region']);

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
        if ($search = $request->query('search')) {
            $query->where('title', 'ilike', "%{$search}%");
        }

        $jobs = $query->orderByDesc('published_at')->get(self::PUBLIC_JOB_FIELDS);

        return response()->json(['jobs' => $jobs]);
    }

    public function showJob(string $companySlug, string $jobId)
    {
        $company = $this->resolveCompany($companySlug);
        if (!$company) {
            return response()->json(['message' => 'Careers page not found.'], 404);
        }

        $connection = RegionResolver::connectionFor($company['region']);

        $job = Job::on($connection)
            ->where('company_id', $company['company_id'])
            ->where('status', 'published')
            ->find($jobId, self::PUBLIC_JOB_FIELDS);

        if (!$job) {
            return response()->json(['message' => 'Job not found or no longer accepting applications.'], 404);
        }

        // Candidates need to see the questions to answer them, but never
        // the internal knockout configuration (that's staff-only intel).
        $questions = ApplicationQuestion::on($connection)
            ->where('job_id', $jobId)
            ->orderBy('order')
            ->get(['id', 'question', 'type', 'required', 'options']);

        return response()->json(['job' => $job, 'questions' => $questions]);
    }

    /**
     * PRD Section 93 (application flow) + Section 29 (knockout stays
     * silent). Rate-limited per email+job to blunt trivial spam/abuse —
     * not a PRD-stated requirement, just baseline hygiene for a public,
     * unauthenticated write endpoint.
     */
    public function apply(SubmitApplicationRequest $request, string $companySlug, string $jobId)
    {
        $company = $this->resolveCompany($companySlug);
        if (!$company) {
            return response()->json(['message' => 'Careers page not found.'], 404);
        }

        $connection = RegionResolver::connectionFor($company['region']);

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

        $answerErrors = $this->applications->validateAnswers($job, $data['answers'] ?? [], $connection);
        if (!empty($answerErrors)) {
            return response()->json(['message' => 'Some required questions were not answered.', 'errors' => $answerErrors], 422);
        }

        try {
            $this->applications->submit($job, $data, $connection, $request->file('resume'));
        } catch (DuplicateApplicationException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Deliberately the SAME response whether the candidate was
        // knockout-rejected or not — PRD Section 29: they never find out.
        return response()->json([
            'message' => "Thank you for applying! We've received your application and will be in touch if there's a match.",
        ], 201);
    }

    protected function resolveCompany(string $slug): ?array
    {
        return $this->routing->findCompanyBySlug($slug);
    }
}
