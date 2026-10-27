<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRolePermission extends Model
{
    protected $connection = 'admin_db';

    protected $primaryKey = null; // composite key (role_id, permission)

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['role_id', 'permission'];
}
