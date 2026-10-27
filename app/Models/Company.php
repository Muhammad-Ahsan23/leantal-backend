<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * PRD Section 9 — the plan list and prices, in ONE place (pricing page, Super Admin billing and MRR all read it).
     * monthly = price per month billed monthly, yearly = price per month billed yearly. null limits = unlimited.
     */
    public const PLAN_CATALOG = [
        ['key' => 'free', 'name' => 'Free', 'monthly' => 0, 'yearly' => 0, 'jobs' => 1, 'users' => 1],
        ['key' => 'starter', 'name' => 'Starter', 'monthly' => 19, 'yearly' => 15, 'jobs' => null, 'users' => 3],
        ['key' => 'team', 'name' => 'Team', 'monthly' => 49, 'yearly' => 39, 'jobs' => null, 'users' => 15],
        ['key' => 'scale', 'name' => 'Scale', 'monthly' => 99, 'yearly' => 79, 'jobs' => null, 'users' => null],
    ];

    public const PLANS = ['free', 'starter', 'team', 'scale'];

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

    /** What the plan itself allows, ignoring any override. */
    public function planSeatLimit(): ?int
    {
        return match ($this->plan) {
            'free' => 1,
            'starter' => 3,
            'team' => 15,
            'scale' => null,
            default => 1,
        };
    }

    public function planJobLimit(): ?int
    {
        return $this->plan === 'free' ? 1 : null;
    }
}
