<?php

namespace App\Services;

use InvalidArgumentException;

/** Saving the IP allowlist would lock the person saving it out. */
class LockOutException extends InvalidArgumentException {}
