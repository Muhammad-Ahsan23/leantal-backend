<?php

namespace App\Services;

use App\Models\FeatureFlag;

/**
 * PRD Section 83 — "enable/disable specific features globally or for
 * targeted company IDs." scope='global' -> 'enabled' alone decides;
 * scope='company' -> only ON for companies listed in company_ids
 * (with 'enabled' acting as a master switch an admin can flip off
 * without losing the configured company list).
 */
class FeatureFlagService
{
    public function isEnabled(string $key, ?string $companyId = null): bool
    {
        $flag = FeatureFlag::where('key', $key)->first();

        if (!$flag || !$flag->enabled) {
            return false;
        }

        if ($flag->scope === 'global') {
            return true;
        }

        // scope === 'company'
        return $companyId && in_array($companyId, $flag->company_ids ?? [], true);
    }
}
