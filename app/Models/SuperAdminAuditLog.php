<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SuperAdminAuditLog extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    const UPDATED_AT = null;

    protected $table = 'super_admin_audit_log';

    protected $fillable = ['super_admin_id', 'action', 'target_type', 'target_id', 'metadata', 'ip_address'];

    protected $casts = ['metadata' => 'array'];

    public function superAdmin()
    {
        return $this->belongsTo(SuperAdmin::class);
    }
}
