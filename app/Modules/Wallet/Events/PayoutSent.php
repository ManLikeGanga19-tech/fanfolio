<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The payout provider confirmed a creator's withdrawal (spec event PayoutSent). */
final readonly class PayoutSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $payoutId, public string $creatorId, public int $amountMinor) {}
}
