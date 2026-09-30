<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MicrosoftSubscriptionState extends Model
{
    use HasUuids;

    protected $table = 'microsoft_subscription_state';

    protected $fillable = ['user_id', 'subscription_id', 'expires_at'];

    protected $casts = ['expires_at' => 'datetime'];
}
