<?php

declare(strict_types=1);

namespace App\Modules\Creators\Console;

use App\Modules\Creators\Actions\StartCreatorKyc;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Notifications\IdExpiringSoon;
use App\Modules\Identity\Contracts\Accounts;
use Illuminate\Console\Command;

/**
 * Daily. Reminds each active creator once, 30 days before their ID expires. Once it has
 * expired, CreatorEligibility refuses publishing and earning.
 */
final class SendIdExpiryReminders extends Command
{
    protected $signature = 'creators:id-expiry-reminders';

    protected $description = 'Remind creators 30 days before their verified ID expires';

    public function handle(Accounts $accounts): int
    {
        $sent = 0;

        Creator::query()
            ->where('status', CreatorStatus::Active)
            ->whereNull('id_expiry_reminded_at')
            ->where('id_expires_at', '>', now())
            ->where('id_expires_at', '<=', now()->addDays(StartCreatorKyc::RENEW_WITHIN_DAYS))
            ->orderBy('id')
            ->chunkById(200, function ($creators) use ($accounts, &$sent): void {
                foreach ($creators as $creator) {
                    /** @var Creator $creator */
                    if ($creator->id_expires_at === null) {
                        continue;
                    }

                    // Mark first, so a crash never sends the same reminder twice.
                    $marked = Creator::query()->whereKey($creator->id)->whereNull('id_expiry_reminded_at')->update(['id_expiry_reminded_at' => now()]);

                    if ($marked === 1) {
                        $accounts->notify($creator->user_id, new IdExpiringSoon($creator->id_expires_at));
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
