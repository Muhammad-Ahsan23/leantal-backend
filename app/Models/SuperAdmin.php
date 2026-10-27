<?php

namespace App\Models;

use App\Support\AdminPermissions;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuperAdmin extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    protected $fillable = ['name', 'email', 'password_hash', 'mfa_secret', 'mfa_enabled_at', 'last_login_at', 'role_id', 'disabled_at'];

    protected $hidden = ['password_hash', 'mfa_secret'];

    protected $casts = [
        'mfa_enabled_at' => 'datetime',
        'last_login_at' => 'datetime',
        'disabled_at' => 'datetime',
        'mfa_secret' => 'encrypted',
    ];

    /** Resolved once per request (the model is loaded fresh from the session token every request). */
    protected ?array $resolvedPermissions = null;

    public function role(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class, 'role_id');
    }

    public function isOwner(): bool
    {
        return (bool) $this->role?->is_owner;
    }

    /** @return string[] The Owner role always holds every permission, including ones added later. */
    public function permissions(): array
    {
        return $this->resolvedPermissions ??= match (true) {
            $this->role === null => [],
            $this->role->is_owner => AdminPermissions::all(),
            default => $this->role->permissions()->pluck('permission')->all(),
        };
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
