<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An admin refunded a sale to the fan's credits. Owners of the access it bought (M5, M6) should revoke it. */
final readonly class PurchaseRefunded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $saleTransactionId,
        public string $fanId,
        public string $creatorId,
        public ?string $referenceType,
        public ?string $referenceId,
    ) {}
}
