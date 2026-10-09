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

        return response()->json([
            'jobs' => $jobs,
            'company' => [
                'name' => $companyRow->name ?? null,
                'website' => $this->safeWebsite($companyRow->website ?? null),
                'description' => $companyRow->careers_description ?? null,
            ],
            'departments' => $departments,
        ]);
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
