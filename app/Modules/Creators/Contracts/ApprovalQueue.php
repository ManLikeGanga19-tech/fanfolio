<?php

declare(strict_types=1);

namespace App\Modules\Creators\Contracts;

use Carbon\CarbonImmutable;

/**
 * Creator approval figures for the admin Overview and navigation (plan M8a, use case 1).
 */
interface ApprovalQueue
{
    /** Applications sent for review and not decided yet. */
    public function waiting(): int;

    /** When the oldest waiting application was sent, or null when none wait. */
    public function oldestWaitingSince(): ?CarbonImmutable;

    /** Creators approved in [from, to). */
    public function approvedBetween(CarbonImmutable $from, CarbonImmutable $to): int;
}
