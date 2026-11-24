<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use App\Support\Money\Money;

/**
 * A fan spending credits on a creator. The caller (Subscriptions in M5, Messaging in M6)
 * passes the Idempotency-Key it received, and its own reference (the subscription,
 * post or message), so the sale can be traced and refunded.
 */
final readonly class PurchaseRequest
{
    public function __construct(
        public string $fanId,
        public string $creatorId,
        public Money $amount,
        public PurchaseKind $kind,
        public string $idempotencyKey,
        public ?string $referenceType = null,
        public ?string $referenceId = null,
    ) {}
}
