<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Jobs;

use App\Modules\Wallet\Actions\SendPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends an approved payout on the `payments` queue. Safe to retry: the gateway is idempotent per payout. */
final class SendPayoutJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $payoutId) {}

    public function handle(SendPayout $send): void
    {
        $send->send($this->payoutId);
    }
}
