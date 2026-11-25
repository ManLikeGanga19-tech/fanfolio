<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Enums\DisputeStatus;
use App\Modules\Wallet\Events\DisputeDecided;
use App\Modules\Wallet\Models\Dispute;
use App\Modules\Wallet\Models\GatewayWebhook;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function array_reverse;

/**
 * The card network's decision, from the processor's signed webhook.
 *
 * Lost: the card holder keeps the money; nothing more moves (it was taken back when the
 * chargeback arrived). Won: the processor returns the money, so what ReceiveChargeback
 * took is returned with reversing entries (rule 2): the credits go back to the fan's
 * wallet, then each clawed-back sale is charged again, oldest first, while the wallet
 * covers it (it may not, if an admin lifted the freeze and the fan spent since).
 * A dispute already decided (accepted by us, or a repeated decision) is left as it is.
 */
final readonly class SettleDispute
{
    public function __construct(
        private Ledger $ledger,
        private AuditLog $audit,
        private UnfreezeDisputedHolds $unfreeze,
    ) {}

    /** @return string|null why nothing was done, for the webhook's error column */
    public function handle(GatewayWebhook $hook, bool $won): ?string
    {
        $dispute = $hook->case_ref === null ? null : Dispute::query()->where('gateway_case_ref', $hook->case_ref)->lockForUpdate()->first();

        if (! $dispute instanceof Dispute) {
            Log::warning('Dispute decision for an unknown case', ['webhook' => $hook->id]);

            return 'No dispute with this case reference.';
        }

        if ($dispute->status->isDecided()) {
            return null;
        }

        $restored = $won ? $this->giveBack($dispute) : [];
        $outcome = $won ? DisputeStatus::Won : DisputeStatus::Lost;

        $dispute->forceFill(['status' => $outcome, 'decided_at' => now()])->save();
        $this->unfreeze->handle($dispute->fan_id);

        $this->audit->record('wallet.dispute_'.$outcome->value, null, 'dispute', $dispute->id, [
            'case' => $dispute->gateway_case_ref,
            'restored_sales' => $restored,
        ]);

        DisputeDecided::dispatch($dispute->id, $dispute->fan_id, $outcome->value, $restored);

        return null;
    }

    /**
     * @return list<string> the sales charged again
     */
    private function giveBack(Dispute $dispute): array
    {
        $recovery = $dispute->recovery;
        $currency = $dispute->currency;
        $wallet = AccountRef::of(AccountType::FanWallet, $dispute->fan_id);

        $postings = [Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), Money::of($dispute->amount_minor, $currency))];
        if ($recovery['wallet_minor'] > 0) {
            $postings[] = Posting::credit($wallet, Money::of($recovery['wallet_minor'], $currency));
        }
        if ($recovery['bonus_minor'] > 0) {
            $postings[] = Posting::debit(AccountRef::platform(AccountType::PlatformPromotions), Money::of($recovery['bonus_minor'], $currency));
        }
        if ($recovery['written_off_minor'] > 0) {
            $postings[] = Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), Money::of($recovery['written_off_minor'], $currency));
        }

        $this->ledger->post(LedgerTransaction::make('chargeback.won', 'chargeback-won:'.$dispute->id, $postings, 'dispute', $dispute->id));

        $restored = [];
        foreach (array_reverse($recovery['sales']) as $saleId) {
            /** @var object{id: string, creator_id: string, gross_minor: int|string, creator_minor: int|string, fee_minor: int|string, released_at: string|null}|null $hold */
            $hold = DB::table('earning_holds')->where('transaction_id', $saleId)->where('dispute_id', $dispute->id)->whereNotNull('refunded_at')->lockForUpdate()->first();

            if ($hold === null || $this->ledger->balance($wallet, $currency)->amountMinor < (int) $hold->gross_minor) {
                continue;
            }

            $sale = [
                Posting::debit($wallet, Money::of((int) $hold->gross_minor, $currency)),
                Posting::credit(AccountRef::of($hold->released_at === null ? AccountType::CreatorPending : AccountType::CreatorEarnings, $hold->creator_id), Money::of((int) $hold->creator_minor, $currency)),
            ];
            if ((int) $hold->fee_minor > 0) {
                $sale[] = Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), Money::of((int) $hold->fee_minor, $currency));
            }

            $this->ledger->post(LedgerTransaction::make('chargeback.won.sale', 'chargeback-won:'.$dispute->id.':'.$saleId, $sale, 'ledger_transaction', $saleId, 'sale'));
            DB::table('earning_holds')->where('id', $hold->id)->update(['refunded_at' => null]);
            $restored[] = $saleId;
        }

        return $restored;
    }
}
