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
        'application_fields',
    ];

    protected $casts = [
        'compensation_enabled' => 'boolean',
        'additional_sections' => 'array',
        'application_fields' => 'array',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Application form fields a job can switch on/off (client request, same idea as Breezy HR).
     * Name and Email are always required and are not configurable.
     * Modes: required | optional | disabled.
     */
    public const APPLICATION_FIELD_KEYS = [
        'phone', 'address', 'desired_salary', 'preferred_location', 'linkedin',
        'resume', 'experience_summary', 'work_history', 'education', 'cover_letter',
    ];

    public const APPLICATION_FIELD_MODES = ['required', 'optional', 'disabled'];

    /** Same defaults the job form shows: Phone, LinkedIn and Resume optional, everything else off. */
    public const DEFAULT_APPLICATION_FIELDS = [
        'phone' => 'optional',
        'address' => 'disabled',
        'desired_salary' => 'disabled',
        'preferred_location' => 'disabled',
        'linkedin' => 'optional',
        'resume' => 'optional',
        'experience_summary' => 'disabled',
        'work_history' => 'disabled',
        'education' => 'disabled',
        'cover_letter' => 'disabled',
    ];

    /** Keeps only the known keys with a valid mode; anything else falls back to the default. */
    public static function normalizeApplicationFields(?array $saved): array
    {
        $out = self::DEFAULT_APPLICATION_FIELDS;
        foreach (self::APPLICATION_FIELD_KEYS as $key) {
            $mode = $saved[$key] ?? null;
            if (is_string($mode) && in_array($mode, self::APPLICATION_FIELD_MODES, true)) {
                $out[$key] = $mode;
            }
        }

        return $out;
    }

    /** This job's settings with the defaults filled in (jobs created before the feature have none saved). */
    public function resolvedApplicationFields(): array
    {
        return self::normalizeApplicationFields($this->application_fields);
    }

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

    public function pipelineStages()
    {
        return $this->hasMany(PipelineStage::class)->orderBy('order');
    }

    public function applicationQuestions()
    {
        return $this->hasMany(ApplicationQuestion::class)->orderBy('order');
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
