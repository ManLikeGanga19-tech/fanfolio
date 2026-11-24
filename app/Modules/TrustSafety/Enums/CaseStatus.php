<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Enums;

enum CaseStatus: string
{
    /** In the queue for moderators and admins. */
    case Open = 'open';
    /** Waiting for an admin (escalated, or anything that may involve a minor). */
    case Escalated = 'escalated';
    /** Decided. */
    case Closed = 'closed';
}
