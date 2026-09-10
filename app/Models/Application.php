<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Application extends Model
{
    use HasUuids;

    protected $fillable = [
        'company_id', 'candidate_id', 'job_id', 'stage_id',
        'status', 'rejection_reason_internal',
        'applied_at', 'rejected_at', 'hired_at', 'lock_version',
    ];

    protected $casts = [
        'applied_at' => 'datetime',
        'rejected_at' => 'datetime',
        'hired_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    // rejection_reason_internal is deliberately hidden — PRD Section 29/38:
    // "Candidate does not see the rejection reason." Any API response
    // that serializes this model directly will never leak it.
    protected $hidden = ['rejection_reason_internal'];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function stage()
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }
}
