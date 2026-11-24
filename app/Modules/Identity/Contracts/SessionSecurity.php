<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use Illuminate\Contracts\Session\Session;

/**
 * What other modules may ask about the signed-in session, without reading Identity's
 * session keys: whether a second factor was confirmed just now (D-13, payouts), and
 * whether this device was added recently (the spec's new-device payout rule).
 */
interface SessionSecurity
{
    /** A passkey or authenticator code was confirmed on purpose in the last few minutes (D-13). */
    public function hasFreshSecondFactor(Session $session): bool;

    /** The session's device was first registered for this account within the last $days days. */
    public function isOnNewDevice(Session $session, string $userId, int $days = 7): bool;
}
