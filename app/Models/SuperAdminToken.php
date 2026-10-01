<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SuperAdminToken extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    public $timestamps = false;

    protected $fillable = ['super_admin_id', 'token_hash', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
