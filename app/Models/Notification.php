<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasUuids;

    const UPDATED_AT = null; // migration only has created_at

    protected $fillable = ['company_id', 'user_id', 'type', 'message', 'link', 'read_at'];

    protected $casts = ['read_at' => 'datetime'];
}
