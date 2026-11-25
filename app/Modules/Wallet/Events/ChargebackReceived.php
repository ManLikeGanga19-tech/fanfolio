<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A card holder disputed a top-up (D-20). The fan's wallet is frozen and what the top-up
 * paid for was taken back. Each clawed-back sale also raises PurchaseRefunded, so the
 * owners of the access it bought (M5, M6) revoke it the same way as a refund.
 */
final readonly class ChargebackReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $clawedBackSales  sale transaction ids
     */
    public function __construct(
        public string $disputeId,
        public string $fanId,
        public string $topUpId,
        public array $clawedBackSales,
    ) {}
}
