<?php

namespace App\Support;

/**
 * Single source of truth for role strings — matches the 'role' enum values
 * used in the users table migration and PRD Section 4.
 */
class Roles
{
    public const OWNER = 'owner';
    public const HIRING_MANAGER = 'hiring_manager';
    public const RECRUITER = 'recruiter';

    // Owner + Hiring Manager share almost identical company-wide visibility
    // and permissions throughout the PRD's matrix (Section 8) — this
    // shorthand is used constantly across every Policy below.
    public const MANAGEMENT = [self::OWNER, self::HIRING_MANAGER];
}
