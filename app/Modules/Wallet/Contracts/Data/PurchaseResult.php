<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use App\Support\Money\Money;

/**
 * A completed sale. $replayed: the idempotency key was seen before and nothing was
 * charged again; the caller should not grant anything twice either.
 */
final readonly class PurchaseResult
{
    public function __construct(
        public string $transactionId,
        public bool $replayed,
        public Money $creatorShare,
        public Money $fee,
    ) {}
}
