<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApplicationQuestion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'job_id', 'question', 'type', 'required', 'order',
        'knockout', 'knockout_action', 'knockout_expected_answer', 'options',
    ];

    protected $casts = [
        'required' => 'boolean',
        'knockout' => 'boolean',
        'order' => 'integer',
        'options' => 'array',
    ];

    public function job()
    {
        return $this->belongsTo(Job::class);
    }
}
