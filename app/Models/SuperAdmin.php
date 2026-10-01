<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SuperAdmin extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    protected $fillable = ['name', 'email', 'password_hash', 'mfa_secret', 'mfa_enabled_at', 'last_login_at'];

    protected $hidden = ['password_hash', 'mfa_secret'];

    protected $casts = [
        'mfa_enabled_at' => 'datetime',
        'last_login_at' => 'datetime',
        'mfa_secret' => 'encrypted',
    ];
}
