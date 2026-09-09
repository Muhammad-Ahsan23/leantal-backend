<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'password_hash',
        'role',
        'status',
        'mfa_enabled',
    ];

    // 'password_hash' (not 'password') matches our migration column name —
    // PRD Section 106 names it explicitly, and we're keeping the schema and
    // model in sync rather than renaming the DB column to Laravel's default.
    protected $hidden = [
        'password_hash',
        'mfa_secret',
    ];

    protected $casts = [
        'mfa_enabled' => 'boolean',
        'last_login_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    // Overridden because Laravel's auth internals look for getAuthPassword()
    // to know which column holds the hash — we point it at password_hash.
    public function getAuthPassword()
    {
        return $this->password_hash;
    }
}
