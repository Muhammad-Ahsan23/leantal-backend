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
    ];

    protected $casts = [
        'trial_start' => 'datetime',
        'trial_end' => 'datetime',
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
     * PRD Section 90 — Plan Seat Enforcement.
     * Returns null for unlimited (Scale plan).
     */
    public function seatLimit(): ?int
    {
        return match ($this->plan) {
            'free' => 1,
            'starter' => 3,
            'team' => 15,
            'scale' => null,
            default => 1,
        };
    }
}
