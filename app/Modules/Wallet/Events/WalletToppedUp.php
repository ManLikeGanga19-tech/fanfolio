<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Credits reached a fan's wallet after a confirmed top-up (spec event WalletToppedUp). */
final readonly class WalletToppedUp implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $topUpId,
        public string $fanId,
        public int $credits,
    ) {}
}
