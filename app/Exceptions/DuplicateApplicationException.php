<?php

namespace App\Exceptions;

// PRD Section 33/135 — thrown when a candidate tries to apply to a job
// they already have an active application for.
class DuplicateApplicationException extends \RuntimeException
{
    //
}
