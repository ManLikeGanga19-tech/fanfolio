<?php

declare(strict_types=1);

namespace App\Modules\Creators\Console;

use App\Modules\Creators\Actions\ReinstateCreator;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Models\Creator;
use Illuminate\Console\Command;

/**
 * Every five minutes. Ends moderators' pauses whose time is up (plan M8a use case 11).
 * An admin's suspension has no end date and is never touched here.
 */
final class EndCreatorPauses extends Command
{
    protected $signature = 'creators:end-pauses';

    protected $description = 'Reactivate creator pages whose pause has ended';

    public function handle(ReinstateCreator $reinstate): int
    {
        $ended = 0;

        Creator::query()
            ->where('status', CreatorStatus::Suspended)
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($creators) use ($reinstate, &$ended): void {
                foreach ($creators as $creator) {
                    /** @var Creator $creator */
                    if ($reinstate->endPause($creator->id)) {
                        $ended++;
                    }
                }
            });

        $this->info("Ended {$ended} pause(s).");

        return self::SUCCESS;
    }
}
