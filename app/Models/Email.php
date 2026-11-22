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
        'to_emails', 'attachments', 'from_email',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'read_at' => 'datetime', // null = a received email its owner has not opened yet
        'to_emails' => 'array', // [{email, name?}] — who a composed email went to (null for candidate-thread mail)
        'attachments' => 'array', // [{name, size, mime}] — names only, the files themselves are not kept
    ];

    /**
     * Did this user earlier compose an email to this address? A reply from such a person is stored in the
     * Inbox even though they are not a candidate: the user wrote to them first, so the reply is theirs to see.
     * Compose lowercases every recipient before saving, so the match is exact on the lowercased address.
     */
    public static function wasComposedTo(string $connection, string $companyId, string $userId, string $address): bool
    {
        return static::on($connection)
            ->where('company_id', $companyId)
            ->where('user_id', $userId)
            ->where('direction', 'outbound')
            ->whereRaw('to_emails @> ?::jsonb', [json_encode([['email' => strtolower($address)]])])
            ->exists();
    }

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
