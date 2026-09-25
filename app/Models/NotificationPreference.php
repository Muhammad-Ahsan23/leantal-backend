<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasUuids;

    public $timestamps = false; // migration has no timestamp columns

    protected $fillable = ['user_id', 'notification_type', 'channel', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];
}
