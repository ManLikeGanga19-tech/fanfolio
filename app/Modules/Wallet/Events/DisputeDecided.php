<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A dispute was won, lost or accepted. When it was won, the sales clawed back for it were
 * charged again: their owners (M5, M6) give back the access PurchaseRefunded took away.
 */
final readonly class DisputeDecided implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  'won'|'lost'|'accepted'  $outcome
     * @param  list<string>  $restoredSales  sale transaction ids charged again (won only)
     */
    public function __construct(
        public string $disputeId,
        public string $fanId,
        public string $outcome,
        public array $restoredSales = [],
    ) {}
}
