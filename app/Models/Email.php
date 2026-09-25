<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Email extends Model
{
    use HasUuids;

    const UPDATED_AT = null; // migration only has created_at

    protected $fillable = [
        'company_id', 'user_id', 'candidate_id', 'direction', 'provider',
        'thread_id', 'subject', 'body', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
