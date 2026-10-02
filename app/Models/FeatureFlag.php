<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    protected $fillable = ['key', 'description', 'enabled', 'scope', 'company_ids'];

    protected $casts = [
        'enabled' => 'boolean',
        'company_ids' => 'array',
    ];
}
