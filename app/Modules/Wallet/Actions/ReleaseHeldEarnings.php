<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

use function count;

/**
 * Spec postings "Hold released (after 7 days)": creator_pending → creator_earnings for
 * each sale whose hold has ended, skipping sales frozen by a dispute or refunded (brief
 * rule 3). The due rows are locked with SKIP LOCKED, so two workers never take the same
 * sale, and each release is keyed to its sale, so a rerun never releases twice. The
 * creator's share is always positive (the fee is at most 20% and rounds down).
 */
final readonly class ReleaseHeldEarnings
{
    public function __construct(private Ledger $ledger) {}

    /** How many sales were released. */
    public function handle(int $batch = 500): int
    {
        return DB::transaction(function () use ($batch): int {
            /** @var list<object{id: string, creator_id: string, creator_minor: int|string, currency: string}> $due */
            $due = DB::table('earning_holds')
                ->whereNull('released_at')
                ->whereNull('refunded_at')
                ->where('frozen', false)
                ->where('release_at', '<=', now())
                ->orderBy('release_at')
                ->limit($batch)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get(['id', 'creator_id', 'creator_minor', 'currency'])
                ->all();

            foreach ($due as $hold) {
                $share = Money::of((int) $hold->creator_minor, $hold->currency);
                $this->ledger->post(LedgerTransaction::make('hold.release', 'release:'.$hold->id, [
                    Posting::debit(AccountRef::of(AccountType::CreatorPending, $hold->creator_id), $share),
                    Posting::credit(AccountRef::of(AccountType::CreatorEarnings, $hold->creator_id), $share),
                ], 'earning_hold', $hold->id));

                DB::table('earning_holds')->where('id', $hold->id)->update(['released_at' => now()]);
            }

            return count($due);
        });
    }
}
