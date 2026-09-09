<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    use HasUuids;

    public $timestamps = false; // migration only has created_at

    protected $fillable = ['job_id', 'name', 'order', 'protected'];

    protected $casts = [
        'order' => 'integer',
        'protected' => 'boolean',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
