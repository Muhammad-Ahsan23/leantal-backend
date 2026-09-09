<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id', 'name', 'email', 'normalized_email', 'phone',
        'location', 'current_title', 'current_company', 'linkedin_url',
        'resume_disk', 'resume_path', 'resume_original_name',
        'assigned_user_id', 'status',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * PRD Section 142 — Recruiters are restricted to their assigned
     * candidates only; Owner/HM see every candidate in the company.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, Roles::MANAGEMENT, true)) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('company_id', $user->company_id)
            ->where('assigned_user_id', $user->id);
    }
}
