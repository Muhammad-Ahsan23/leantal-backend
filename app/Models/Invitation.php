<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Invitation extends Model
{
    use HasUuids;

    // Migration only has created_at (no updated_at column) — disable
    // Eloquent's automatic updated_at tracking while keeping created_at.
    const UPDATED_AT = null;

    protected $fillable = [
        'company_id', 'email', 'role', 'invited_by', 'token', 'status', 'expires_at', 'accepted_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
