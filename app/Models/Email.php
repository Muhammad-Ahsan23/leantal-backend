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
        'thread_id', 'subject', 'body', 'sent_at', 'read_at',
        'to_emails', 'attachments',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime', // null = a received email its owner has not opened yet
        'to_emails' => 'array', // [{email, name?}] — who a composed email went to (null for candidate-thread mail)
        'attachments' => 'array', // [{name, size, mime}] — names only, the files themselves are not kept
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
