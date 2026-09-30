<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OAuthToken extends Model
{
    use HasUuids;

    /**
     * Explicit table name required — Laravel's automatic class-name ->
     * table-name conversion mangles "OAuthToken" into "o_auth_tokens"
     * (it inserts an underscore between "O" and "Auth" because of the
     * consecutive capital letters), not our actual "oauth_tokens" table.
     */
    protected $table = 'oauth_tokens';

    // oauth_tokens has NEITHER created_at nor updated_at — it tracks
    // connected_at/expires_at/disconnected_at instead (set explicitly
    // by OAuthConnectController), so Eloquent's automatic timestamp
    // columns must be disabled entirely, not just one of them.
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'provider', 'provider_email', 'access_token', 'refresh_token',
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
