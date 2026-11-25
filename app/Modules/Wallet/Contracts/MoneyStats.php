<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use App\Modules\Wallet\Contracts\Data\LedgerCheck;
use Carbon\CarbonImmutable;

/**
 * Money figures for the admin Overview (plan M8a use case 3, canvas AdminOverview).
 * Amounts are integer US cents (100 credits = $1, so one credit is one cent).
 */
interface MoneyStats
{
    /** Sales (gross, before the platform fee) between two moments, refunded sales left out. */
    public function salesBetween(CarbonImmutable $from, CarbonImmutable $to): int;

    /**
     * Sales per calendar day, starting at the given day in its own time zone (D-15).
     *
     * @return list<int>
     */
    public function salesPerDay(CarbonImmutable $firstDay, int $days): array;

    /**
     * Withdrawals waiting for an admin (under review or on hold).
     *
     * @return array{count: int, amount: int}
     */
    public function payoutsWaiting(): array;

    /** The last nightly ledger check, or null before the first one has run. */
    public function lastLedgerCheck(): ?LedgerCheck;
}
