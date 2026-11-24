<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The payout provider refused a withdrawal; the money is back in the creator's earnings. */
final readonly class PayoutFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $payoutId, public string $creatorId, public int $amountMinor) {}
}
