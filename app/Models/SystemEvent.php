<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SystemEvent extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    const UPDATED_AT = null;

    protected $fillable = ['category', 'region', 'company_id', 'message', 'context', 'resolved_at'];

    protected $casts = ['context' => 'array', 'resolved_at' => 'datetime'];
}
