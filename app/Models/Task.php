<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'company_id', 'type', 'title', 'assigned_user_id', 'created_by',
        'candidate_id', 'job_id', 'due_date', 'status', 'notes', 'auto_generated',
    ];

    protected $casts = [
        'due_date' => 'date',
        'auto_generated' => 'boolean',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * PRD Section 62 — Recruiters only see tasks assigned to them;
     * Owner/HM see every task in the company (they created them).
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
