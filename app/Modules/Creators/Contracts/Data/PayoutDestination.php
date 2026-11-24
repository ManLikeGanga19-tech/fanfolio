<?php

declare(strict_types=1);

namespace App\Modules\Creators\Contracts\Data;

use App\Modules\Wallet\Contracts\Data\PayoutRail;
use Carbon\CarbonImmutable;

/** A creator's active payout method as Wallet may show it: masked, never the account details. */
final readonly class PayoutDestination
{
    public function __construct(
        public string $id,
        public PayoutRail $rail,
        public string $displayHint,
        public bool $nameMatchesKyc,
        public bool $isDefault,
        public CarbonImmutable $addedAt,
    ) {}
}
