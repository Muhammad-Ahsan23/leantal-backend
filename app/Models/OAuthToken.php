<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OAuthToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'provider', 'access_token', 'refresh_token',
        'scope', 'connected_at', 'expires_at', 'disconnected_at',
    ];

    // Never expose raw tokens in API responses — this model is queried
    // internally to CALL providers' APIs, never serialized to a client.
    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = [
        'connected_at' => 'datetime',
        'expires_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
