<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Console;

use App\Modules\Wallet\Actions\SendPayout;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Models\Payout;
use Illuminate\Console\Command;

/** Every 15 minutes: asks the provider about payouts it said were pending. */
final class CheckPayouts extends Command
{
    protected $signature = 'wallet:payout-status';

    protected $description = 'Ask payout providers about payouts that are still being sent';

    public function handle(SendPayout $send): int
    {
        $checked = 0;
        Payout::query()->where('status', PayoutState::Sending)->whereNotNull('gateway_ref')->orderBy('id')
            ->chunkById(100, function ($payouts) use ($send, &$checked): void {
                foreach ($payouts as $payout) {
                    /** @var Payout $payout */
                    $send->refresh($payout->id);
                    $checked++;
                }
            });

        $this->info("Checked {$checked} payout(s).");

        return self::SUCCESS;
    }
}
