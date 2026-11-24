<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A fan spent credits on a creator (spec event PurchaseCompleted). Dispatched after commit. */
final readonly class PurchaseCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $transactionId,
        public string $fanId,
        public string $creatorId,
        public string $kind,
        public int $amountMinor,
        public ?string $referenceType,
        public ?string $referenceId,
    ) {}
}
