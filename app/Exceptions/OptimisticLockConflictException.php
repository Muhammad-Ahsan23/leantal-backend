<?php

namespace App\Exceptions;

/**
 * PRD Section 98 — thrown when a stage-move request's submitted
 * lock_version doesn't match the application's current lock_version,
 * meaning someone else changed it first. Mapped to HTTP 409 (not 422)
 * because this is a genuine conflict, not a validation failure.
 */
class OptimisticLockConflictException extends \RuntimeException
{
    //
}
