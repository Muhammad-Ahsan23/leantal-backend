<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id', 'title', 'department', 'description', 'location',
        'location_type', 'employment_type', 'compensation_enabled',
        'compensation_type', 'compensation_value', 'about_company',
        'benefits', 'team_info', 'additional_sections', 'status',
        'assigned_user_id', 'created_by', 'published_at', 'closed_at',
    ];

    protected $casts = [
        'compensation_enabled' => 'boolean',
        'additional_sections' => 'array',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * PRD Section 142 (least privilege) — Owner/HM see every job in the
     * company; Recruiters see ONLY jobs assigned to them. This is the
     * list-level counterpart to JobPolicy::view() (which guards a single
     * record) — every "list jobs" query should start from this scope.
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
