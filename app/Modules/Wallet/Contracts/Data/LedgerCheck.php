<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use Carbon\CarbonImmutable;

/** The result of the nightly `wallet:verify-ledger` run (spec "Integrity checks"). */
final readonly class LedgerCheck
{
    public function __construct(
        public bool $ok,
        public CarbonImmutable $checkedAt,
        /** Unbalanced transactions plus cached balances out of step with their entries. */
        public int $mismatches,
    ) {}
}
