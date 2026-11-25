<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Enums\DisputeStatus;
use App\Modules\Wallet\Models\Dispute;
use Illuminate\Support\Facades\DB;

/**
 * Once none of a fan's disputes is open, the sales they made start releasing to their
 * creators again (spec: release is skipped only while a chargeback is open). The fan's
 * wallet stays frozen until an admin lifts it (D-20).
 */
final readonly class UnfreezeDisputedHolds
{
    public function handle(string $fanId): void
    {
        if (Dispute::query()->where('fan_id', $fanId)->whereIn('status', DisputeStatus::UNDECIDED)->exists()) {
            return;
        }

        DB::table('earning_holds')->where('fan_id', $fanId)->where('frozen', true)->update(['frozen' => false]);
    }
}
