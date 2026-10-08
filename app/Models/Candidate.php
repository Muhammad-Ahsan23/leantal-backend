<?php

namespace App\Models;

use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Candidate extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

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

    public function applications()
    {
        return $this->hasMany(Application::class);
    }

    /**
     * PRD Sections 7, 96, 142 — Owner/HM see every candidate in the company. A Recruiter sees:
     *   - candidates assigned to them, AND
     *   - candidates who applied to a job assigned to them ("Candidates belonging to jobs assigned to
     *     them"; an assigned Recruiter "gains access to the job, its candidate pipeline").
     * Nothing else: another Recruiter's candidates stay invisible.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, Roles::MANAGEMENT, true)) {
            return $query->where('company_id', $user->company_id);
        }

        return $query->where('company_id', $user->company_id)
            ->where(function (Builder $q) use ($user) {
                $q->where('assigned_user_id', $user->id)
                  ->orWhereHas('applications', function ($a) use ($user) {
                      $a->whereHas('job', fn ($j) => $j->where('assigned_user_id', $user->id));
                  });
            });
    }
}
