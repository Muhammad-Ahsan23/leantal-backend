<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminRole extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    protected $fillable = ['name', 'description', 'is_owner'];

    protected $casts = ['is_owner' => 'boolean'];

    public function permissions(): HasMany
    {
        return $this->hasMany(AdminRolePermission::class, 'role_id');
    }

    public function admins(): HasMany
    {
        return $this->hasMany(SuperAdmin::class, 'role_id');
    }
}
