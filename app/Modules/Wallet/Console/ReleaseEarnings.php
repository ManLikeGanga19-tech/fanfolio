<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Console;

use App\Modules\Wallet\Actions\ReleaseHeldEarnings;
use Illuminate\Console\Command;

/** Nightly (spec "Hold released (after 7 days)"): moves creators' held shares to their earnings. */
final class ReleaseEarnings extends Command
{
    protected $signature = 'wallet:release-earnings';

    protected $description = 'Release creator earnings whose 7-day hold has ended';

    public function handle(ReleaseHeldEarnings $release): int
    {
        $total = 0;
        do {
            $released = $release->handle();
            $total += $released;
        } while ($released === 500);

        $this->info("Released {$total} sale(s).");

        return self::SUCCESS;
    }
}
