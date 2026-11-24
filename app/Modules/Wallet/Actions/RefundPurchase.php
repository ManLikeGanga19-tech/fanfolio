<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Events\PurchaseRefunded;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

use function in_array;
use function mb_strlen;
use function trim;

/**
 * Spec postings "Refund (admin)": creator_pending (or creator_earnings once released) +
 * platform_revenue → fan_wallet, back to the fan's credits (credits are never paid out
 * to fans). Admins only, with a reason, audited (rule 12). A creator who already withdrew
 * the share goes below zero and repays it from future sales (D-20). The access the sale
 * bought is revoked by its owner on PurchaseRefunded (M5, M6).
 */
final readonly class RefundPurchase
{
    public function __construct(
        private Ledger $ledger,
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $saleTransactionId, string $staffId, string $reason): void
    {
        if (! $this->accounts->can($staffId, StaffAbility::RefundPurchase)) {
            throw new WalletException('Your role cannot do this.', 'staff');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw new WalletException('Write the reason for the refund.', 'reason');
        }

        DB::transaction(function () use ($saleTransactionId, $staffId, $reason): void {
            /** @var object{id: string, fan_id: string, creator_id: string, gross_minor: int|string, creator_minor: int|string, fee_minor: int|string, currency: string, released_at: string|null, refunded_at: string|null, reference_type: string|null, reference_id: string|null}|null $hold */
            $hold = DB::table('earning_holds')->where('transaction_id', $saleTransactionId)->lockForUpdate()->first();

            if ($hold === null) {
                throw new WalletException('There is no sale with this reference.', 'sale');
            }

            if ($hold->refunded_at !== null) {
                throw new WalletException('This sale was already refunded.', 'sale');
            }

            if (in_array($staffId, [$hold->fan_id, $hold->creator_id], true)) {
                throw new WalletException('You cannot refund a sale you are part of.', 'sale');
            }

            $from = $hold->released_at === null ? AccountType::CreatorPending : AccountType::CreatorEarnings;
            $postings = [
                Posting::debit(AccountRef::of($from, $hold->creator_id), Money::of((int) $hold->creator_minor, $hold->currency)),
                Posting::credit(AccountRef::of(AccountType::FanWallet, $hold->fan_id), Money::of((int) $hold->gross_minor, $hold->currency)),
            ];
            if ((int) $hold->fee_minor > 0) {
                $postings[] = Posting::debit(AccountRef::platform(AccountType::PlatformRevenue), Money::of((int) $hold->fee_minor, $hold->currency));
            }

            $this->ledger->post(LedgerTransaction::make('refund', 'refund:'.$saleTransactionId, $postings, 'ledger_transaction', $saleTransactionId, 'sale')
                ->allowingNegativeBalances());

            DB::table('earning_holds')->where('id', $hold->id)->update(['refunded_at' => now()]);

            $this->audit->record('wallet.refund', $staffId, 'ledger_transaction', $saleTransactionId, [
                'reason' => $reason,
                'amount_minor' => (int) $hold->gross_minor,
                'from' => $from->value,
            ]);

            PurchaseRefunded::dispatch($saleTransactionId, $hold->fan_id, $hold->creator_id, $hold->reference_type, $hold->reference_id);
        });
    }
}
