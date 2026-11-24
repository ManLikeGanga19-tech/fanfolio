<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Spec "Integrity checks": every transaction balances (and has at least two entries),
 * and every cached balance equals the sum of its account's entries. Run nightly by
 * `wallet:verify-ledger`, and after every Wallet test. Reconciling clearing accounts
 * with gateway settlement reports arrives with the top-up and payout PRs.
 */
final readonly class LedgerIntegrity
{
    /**
     * @return array{unbalanced: list<string>, mismatched: list<string>}
     */
    public function check(): array
    {
        /** @var list<string> $unbalanced */
        $unbalanced = DB::table('ledger_transactions')
            ->leftJoin('ledger_entries', 'ledger_entries.transaction_id', '=', 'ledger_transactions.id')
            ->groupBy('ledger_transactions.id')
            ->havingRaw("COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END), 0) <> 0 OR COUNT(ledger_entries.id) < 2")
            ->orderBy('ledger_transactions.id')
            ->pluck('ledger_transactions.id')
            ->all();

        $sums = DB::table('ledger_entries')
            ->groupBy('account_id')
            ->selectRaw("account_id, SUM(CASE WHEN direction = 'credit' THEN amount_minor ELSE -amount_minor END) AS total");

        /** @var list<string> $mismatched */
        $mismatched = DB::table('ledger_accounts')
            ->leftJoin('ledger_balances', 'ledger_balances.account_id', '=', 'ledger_accounts.id')
            ->leftJoinSub($sums, 'sums', 'sums.account_id', '=', 'ledger_accounts.id')
            ->whereRaw('COALESCE(ledger_balances.balance_minor, 0) <> COALESCE(sums.total, 0)')
            ->orderBy('ledger_accounts.id')
            ->pluck('ledger_accounts.id')
            ->all();

        return ['unbalanced' => $unbalanced, 'mismatched' => $mismatched];
    }
}
