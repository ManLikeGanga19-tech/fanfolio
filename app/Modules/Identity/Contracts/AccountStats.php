<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use Carbon\CarbonImmutable;

/**
 * Account figures for the admin Overview (plan M8a, use case 1). Counts only: no
 * personal data leaves Identity. Ranges are half-open, [from, to).
 */
interface AccountStats
{
    public function signUps(CarbonImmutable $from, CarbonImmutable $to): int;

    /** Of the accounts that signed up in the range, how many passed the 18+ check. */
    public function ageVerifiedSignUps(CarbonImmutable $from, CarbonImmutable $to): int;

    /**
     * Sign-ups per calendar day in the time zone of $firstDay, oldest first.
     *
     * @return list<int> one count per day, $days long
     */
    public function signUpsPerDay(CarbonImmutable $firstDay, int $days): array;
}
