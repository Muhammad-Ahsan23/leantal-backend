<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CompanyNote extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    public $timestamps = false;

    protected $fillable = ['company_id', 'category', 'super_admin_id', 'body'];

    protected $casts = ['created_at' => 'datetime'];

    public function superAdmin()
    {
        return $this->belongsTo(SuperAdmin::class);
    }
}
