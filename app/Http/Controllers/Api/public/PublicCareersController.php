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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class PublicCareersController extends Controller
{
    protected const PUBLIC_JOB_FIELDS = [
        'id', 'title', 'department', 'description', 'location', 'location_type',
        'employment_type', 'compensation_enabled', 'compensation_type',
        'compensation_value', 'about_company', 'benefits', 'team_info',
        'additional_sections', 'published_at',
    ];

    public function __construct(
        protected RegionRoutingRepository $routing,
        protected PublicApplicationService $applications,
        protected \App\Services\JobStructuredDataService $structuredData,
    ) {}

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

        $questions = ApplicationQuestion::on($connection)
            ->where('job_id', $jobId)
            ->orderBy('order')
            ->get(['id', 'question', 'type', 'required', 'options']);

        $companyModel = \App\Models\Company::on($connection)->find($company['company_id']);
        $canonicalUrl = $this->structuredData->canonicalUrl($companySlug, $job->id);

        return response()->json([
            'job' => $job,
            'questions' => $questions,
            'structured_data' => $this->structuredData->build($job, $companySlug, $companyModel->name ?? ''),
            'canonical_url' => $canonicalUrl,
        ]);
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

        $answerErrors = $this->applications->validateAnswers($job, $data['answers'] ?? [], $connection);
        if (!empty($answerErrors)) {
            return response()->json(['message' => 'Some required questions were not answered.', 'errors' => $answerErrors], 422);
        }

        try {
            $this->applications->submit($job, $data, $connection, $request->file('resume'));
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
