<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * Decision D-03: a 4th active device is refused at sign-in.
 */
final class DeviceLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("This account is already signed in on {$limit} devices.");
    }
}
