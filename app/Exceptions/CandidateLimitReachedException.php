<?php

namespace App\Exceptions;

// Thrown when adding a NEW candidate would take the company over its plan's candidate limit. It is a RuntimeException on
// purpose: the places that add candidates already turn a RuntimeException into a 422 with a user-safe message.
class CandidateLimitReachedException extends \RuntimeException
{
    //
}
