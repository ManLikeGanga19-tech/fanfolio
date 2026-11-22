<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * SEC-03: tells whether a password appears in known breach corpora.
 * Implementations must never send the password or its full hash anywhere.
 */
interface BreachedPasswordChecker
{
    public function isBreached(string $password): bool;
}
