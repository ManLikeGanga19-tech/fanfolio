<?php

declare(strict_types=1);

namespace App\Modules\Audit\Contracts;

use DateTimeInterface;

/**
 * Read-only questions other modules may ask of the audit log, without reading its table.
 * Answers are counts, never the entries themselves.
 */
interface AuditHistory
{
    /** How many times this person did `$action` from this IP address before a moment (dispute evidence). */
    public function countFromIp(string $action, string $actorId, string $ip, DateTimeInterface $before): int;
}
