<?php

namespace App\Providers;

use App\Models\Candidate;
use App\Models\Company;
use App\Models\Job;
use App\Models\PersonalAccessToken;
use App\Models\Task;
use App\Models\User;
use App\Policies\CandidatePolicy;
use App\Policies\CompanyPolicy;
use App\Policies\JobPolicy;
use App\Policies\TaskPolicy;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::policy(Job::class, JobPolicy::class);
        Gate::policy(Candidate::class, CandidatePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Company::class, CompanyPolicy::class);
    }
}
