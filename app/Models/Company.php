<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Company extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'website',
        'location',
        'country_code',
        'region',
        'slug',
        'careers_description',
        'plan',
        'subscription_status',
        'trial_start',
        'trial_end',
        'past_due_since',
        // BUG FIX: these were missing, so Eloquent's mass-assignment
        // protection silently DROPPED them on every ->update([...]):
        //  - deletion trio: CompanyController::delete() never persisted anything
        //  - Creem ids: CreemWebhookController saved plan/status but never the
        //    customer/subscription ids (portal + plan-change need them)
        // All callers pass controlled/validated arrays, never raw request input.
        'deletion_requested_at',
        'data_retention_choice',
        'deleted_at',
        'creem_customer_id',
        'creem_subscription_id',
        'seat_limit_override',
        'job_limit_override',
        'candidate_limit_override',
        'billing_interval',
    ];

    protected $casts = [
        'trial_start' => 'datetime',
        'trial_end' => 'datetime',
        'past_due_since' => 'datetime',
        'deletion_requested_at' => 'datetime',
        'deleted_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    // Never expose internal billing/deletion fields in API responses by default
    protected $hidden = [
        'creem_customer_id',
        'creem_subscription_id',
        'deletion_requested_at',
        'data_retention_choice',
        'suspended_reason',
    ];

    /**
     * A Super Admin seat/job/candidate override is a deal made for ONE plan. When the plan changes (Creem webhook, checkout,
     * manual change), the override is dropped, so a customer who upgrades never keeps a limit below what they now pay
     * for. Done here, in the model, so every place that changes the plan follows the same rule. A save that sets the
     * override itself keeps what it sets.
     */
    protected static function booted(): void
    {
        static::saving(function (Company $company) {
            if (!$company->exists || !$company->isDirty('plan')) {
                return;
            }

            $dropped = [];
            foreach (['seat_limit_override', 'job_limit_override', 'candidate_limit_override'] as $column) {
                if ($company->$column !== null && !$company->isDirty($column)) {
                    $dropped[$column] = $company->$column;
                    $company->$column = null;
                }
            }

            if ($dropped) {
                Log::info('Company limit overrides reset because the plan changed', [
                    'company_id' => $company->id,
                    'from_plan' => $company->getOriginal('plan'),
                    'to_plan' => $company->plan,
                    'dropped' => $dropped,
                ]);
            }
        });
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * PRD Section 9 — the plan list and prices, in ONE place (pricing page, Super Admin billing and MRR all read it).
     * monthly = price per month billed monthly, yearly = price per month billed yearly.
     * Every SOLD plan has a hard limit on users, active jobs and candidates (no plan is unlimited: that was the abuse
     * risk). A Super Admin can still raise any of them for one company (the *_limit_override columns).
     * 'free' is a leftover internal plan, not sold ('public' => false keeps it off the pricing page and the sign-up page).
     * New companies start their free trial ON the plan they pick at sign-up, so they get that plan's full limits.
     * Plan KEYS are unchanged on purpose: they are in the Creem product ids, the webhook metadata and the database.
     */
    public const PLAN_CATALOG = [
        ['key' => 'free', 'name' => 'Free Trial', 'public' => false, 'monthly' => 0, 'yearly' => 0, 'users' => 1, 'jobs' => 1, 'candidates' => 100],
        ['key' => 'starter', 'name' => '3 Users', 'public' => true, 'monthly' => 19, 'yearly' => 15, 'users' => 3, 'jobs' => 10, 'candidates' => 1500],
        ['key' => 'team', 'name' => '10 Users', 'public' => true, 'monthly' => 39, 'yearly' => 31, 'users' => 10, 'jobs' => 25, 'candidates' => 7500],
        ['key' => 'scale', 'name' => '25 Users', 'public' => true, 'monthly' => 69, 'yearly' => 55, 'users' => 25, 'jobs' => 50, 'candidates' => 25000],
    ];

    public const PLANS = ['free', 'starter', 'team', 'scale'];

    /** The plans a visitor can pick at sign-up (and see on the pricing page): every catalog plan that is sold. */
    public static function signupPlans(): array
    {
        return array_values(array_filter(self::PLAN_CATALOG, fn (array $plan) => ($plan['public'] ?? true) !== false));
    }

    /** The keys of signupPlans(): the only values the sign-up form may send. */
    public static function signupPlanKeys(): array
    {
        return array_column(self::signupPlans(), 'key');
    }

    /**
     * PRD Section 90 — Plan Seat Enforcement.
     * Returns null for unlimited. A Super Admin override wins over the plan (-1 = unlimited).
     */
    public function seatLimit(): ?int
    {
        return $this->seat_limit_override === null ? $this->planSeatLimit() : ($this->seat_limit_override < 0 ? null : $this->seat_limit_override);
    }

    /**
     * PRD Section 91 — Job Limits. "Archived and closed jobs do not count
     * against active limits" — implies draft/published/paused DO count,
     * which is what JobService checks against this.
     */
    public function jobLimit(): ?int
    {
        return $this->job_limit_override === null ? $this->planJobLimit() : ($this->job_limit_override < 0 ? null : $this->job_limit_override);
    }

    /**
     * Candidates the company may hold (archived ones count, deleted ones do not).
     * Returns null for unlimited, which only a Super Admin override (-1) can give.
     */
    public function candidateLimit(): ?int
    {
        return $this->candidate_limit_override === null ? $this->planCandidateLimit() : ($this->candidate_limit_override < 0 ? null : $this->candidate_limit_override);
    }

    /** What the plan itself allows, ignoring any override. An unknown plan gets the trial's (smallest) limits. */
    public function planSeatLimit(): ?int
    {
        return self::catalogEntry($this->plan)['users'];
    }

    public function planJobLimit(): ?int
    {
        return self::catalogEntry($this->plan)['jobs'];
    }

    public function planCandidateLimit(): ?int
    {
        return self::catalogEntry($this->plan)['candidates'];
    }

    protected static function catalogEntry(?string $plan): array
    {
        foreach (self::PLAN_CATALOG as $entry) {
            if ($entry['key'] === $plan) {
                return $entry;
            }
        }

        return self::PLAN_CATALOG[0];
    }
}
