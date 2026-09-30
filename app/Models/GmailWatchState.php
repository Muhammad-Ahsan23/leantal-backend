<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GmailWatchState extends Model
{
    use HasUuids;

    protected $table = 'gmail_watch_state';

    protected $fillable = ['user_id', 'history_id', 'watch_expires_at'];

    protected $casts = ['watch_expires_at' => 'datetime'];
}
