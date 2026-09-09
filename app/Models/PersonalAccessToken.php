<?php

namespace App\Models;

use App\Services\RegionResolver;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    // Forces every token lookup/write to hit routing_db, regardless of
    // which regional connection is currently "default" for the request.
    protected $connection = 'routing_db';

    /**
     * Overrides Sanctum's default tokenable() relationship. By default,
     * Laravel's morphTo() instantiates the related model (User) using
     * WHATEVER connection is currently "default" for the app — which is
     * wrong here, since the actual User lives in whichever regional DB
     * (pgsql_us/eu/uk) is stored in this token's own `region` column.
     * This builds the relation manually against the correct connection
     * instead of letting morphTo() guess.
     */
    public function tokenable(): MorphTo
    {
        $instance = new $this->tokenable_type();

        if ($this->region) {
            $instance->setConnection(RegionResolver::connectionFor($this->region));
        }

        return $this->newMorphTo(
            $instance->newQuery(),
            $this,
            'tokenable_id',
            $instance->getKeyName(),
            'tokenable_type',
            'tokenable'
        );
    }
}
