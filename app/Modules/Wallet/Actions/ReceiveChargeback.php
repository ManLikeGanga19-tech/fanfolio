<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Enums\TopUpStatus;
use App\Modules\Wallet\Events\ChargebackReceived;
use App\Modules\Wallet\Events\PurchaseRefunded;
use App\Modules\Wallet\Models\Dispute;
use App\Modules\Wallet\Models\GatewayWebhook;
use App\Modules\Wallet\Models\TopUp;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function intdiv;
use function min;

/**
 * A chargeback on a top-up (spec postings "Chargeback", owner decision D-20). The
 * processor has already taken the money back, so processor_clearing is credited the
 * disputed amount at once, and it is recovered in this order:
 *
 * 1. the fan's unspent credits (fan_wallet), the paid part first, then the top-up's bonus
 *    in proportion to the amount disputed (D-04 bonus reversed);
 * 2. if those are not enough, whole sales the fan made since the top-up, newest first:
 *    each is reversed to the fan's credits exactly as an admin refund would be
 *    (creator_pending, or creator_earnings once released, plus platform_revenue), and the
 *    credits are then taken; the creator may go below zero and repays from future sales;
 * 3. what is still missing of the paid part is written off against platform_revenue.
 *    Bonus credits already spent are not chased: they were FanFolio's own promotion.
 *
 * The fan's wallet is frozen until an admin lifts it, the fan's sales still on hold stop
 * releasing while the dispute is open (spec: "skipped if a chargeback is open"), and the
 * amounts are kept on the dispute so a won dispute can return them exactly.
 */
final readonly class ReceiveChargeback
{
    public function __construct(
        private Ledger $ledger,
        private AuditLog $audit,
    ) {}

    /**
     * Runs inside ApplyGatewayEvent's transaction.
     *
     * @return string|null why nothing was done, for the webhook's error column
     */
    public function handle(GatewayWebhook $hook): ?string
    {
        if ($hook->case_ref === null || $hook->details === null) {
            Log::critical('Chargeback without a case reference', ['webhook' => $hook->id]);

            return 'Chargeback without a case reference; nothing taken back.';
        }

        if (Dispute::query()->where('gateway_case_ref', $hook->case_ref)->exists()) {
            return null;
        }

        $topUp = TopUp::query()->where('gateway', $hook->gateway)->where('gateway_ref', $hook->gateway_ref)->lockForUpdate()->first();

        if (! $topUp instanceof TopUp || $topUp->status !== TopUpStatus::Paid) {
            Log::critical('Chargeback on a top-up that is not paid', ['webhook' => $hook->id]);

            return 'No paid top-up with this gateway reference; nothing taken back.';
        }

        if ($hook->currency !== $topUp->currency || $hook->amount_minor <= 0 || $hook->amount_minor > $topUp->amount_minor) {
            Log::critical('Chargeback amount does not match the top-up', ['topup' => $topUp->id, 'webhook' => $hook->id]);

            return 'Chargeback amount does not match the top-up; nothing taken back.';
        }

        $details = $hook->details;
        $respondBy = $details['respond_by'];

        $dispute = new Dispute;
        $dispute->forceFill([
            'topup_id' => $topUp->id,
            'fan_id' => $topUp->fan_id,
            'gateway' => $hook->gateway,
            'gateway_case_ref' => $hook->case_ref,
            'reason' => $details['reason'],
            'amount_minor' => $hook->amount_minor,
            'currency' => $hook->currency,
            'three_ds' => $details['three_ds'],
            // No deadline from the processor: card networks allow at least 7 days.
            'respond_by' => $respondBy === null ? now()->addDays(7) : CarbonImmutable::parse($respondBy),
            'recovery' => [],
            'received_at' => now(),
        ])->save();

        $recovery = $this->recover($dispute, $topUp);
        $dispute->forceFill(['recovery' => $recovery])->save();
        $topUp->forceFill(['status' => TopUpStatus::ChargedBack])->save();

        DB::table('wallet_freezes')->upsert(
            [['fan_id' => $topUp->fan_id, 'reason' => 'chargeback', 'frozen_at' => now(), 'lifted_at' => null]],
            ['fan_id'],
            ['reason', 'frozen_at', 'lifted_at'],
        );
        DB::table('earning_holds')->where('fan_id', $topUp->fan_id)->whereNull('released_at')->whereNull('refunded_at')->update(['frozen' => true]);

        $this->audit->record('wallet.chargeback', null, 'dispute', $dispute->id, [
            'topup' => $topUp->id,
            'case' => $dispute->gateway_case_ref,
            'amount_minor' => $dispute->amount_minor,
            'from_wallet_minor' => $recovery['wallet_minor'],
            'clawed_back_minor' => $recovery['clawed_minor'],
            'written_off_minor' => $recovery['written_off_minor'],
            'sales' => $recovery['sales'],
        ]);

        ChargebackReceived::dispatch($dispute->id, $topUp->fan_id, $topUp->id, $recovery['sales']);

        return null;
    }

    /**
     * @return array{wallet_minor: int, unspent_minor: int, bonus_minor: int, clawed_minor: int, written_off_minor: int, sales: list<string>, transactions: list<string>}
     */
    private function recover(Dispute $dispute, TopUp $topUp): array
    {
        $currency = $dispute->currency;
        $amount = $dispute->amount_minor;
        $bonus = intdiv($topUp->bonus_credits * $amount, $topUp->amount_minor);
        $need = $amount + $bonus;
        $wallet = AccountRef::of(AccountType::FanWallet, $topUp->fan_id);
        $available = $this->ledger->balance($wallet, $currency)->amountMinor;

        $sales = [];
        $transactions = [];
        $clawed = 0;

        if ($available < $need) {
            /** @var list<object{id: string, transaction_id: string, creator_id: string, gross_minor: int|string, creator_minor: int|string, fee_minor: int|string, released_at: string|null, reference_type: string|null, reference_id: string|null}> $holds */
            $holds = DB::table('earning_holds')
                ->where('fan_id', $topUp->fan_id)
                ->whereNull('refunded_at')
                ->where('created_at', '>=', $topUp->paid_at)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($holds as $hold) {
                if ($available + $clawed >= $need) {
                    break;
                }

                $from = $hold->released_at === null ? AccountType::CreatorPending : AccountType::CreatorEarnings;
                $postings = [
                    Posting::debit(AccountRef::of($from, $hold->creator_id), Money::of((int) $hold->creator_minor, $currency)),
                    Posting::credit($wallet, Money::of((int) $hold->gross_minor, $currency)),
                ];
                if ((int) $hold->fee_minor > 0) {
                    $postings[] = Posting::debit(AccountRef::platform(AccountType::PlatformRevenue), Money::of((int) $hold->fee_minor, $currency));
                }

                // D-20: the creator's account may go below zero; future sales repay it first.
                $transactions[] = $this->ledger->post(LedgerTransaction::make(
                    'chargeback.clawback',
                    'chargeback:'.$dispute->id.':'.$hold->transaction_id,
                    $postings,
                    'ledger_transaction',
                    $hold->transaction_id,
                    'sale',
                )->allowingNegativeBalances())->id;

                DB::table('earning_holds')->where('id', $hold->id)->update(['refunded_at' => now(), 'dispute_id' => $dispute->id]);
                PurchaseRefunded::dispatch($hold->transaction_id, $topUp->fan_id, $hold->creator_id, $hold->reference_type, $hold->reference_id);

                $clawed += (int) $hold->gross_minor;
                $sales[] = $hold->transaction_id;
            }
        }

        $taken = min($available + $clawed, $need);
        $paidTaken = min($taken, $amount);
        $bonusTaken = $taken - $paidTaken;
        $writtenOff = $amount - $paidTaken;

        $postings = [Posting::credit(AccountRef::platform(AccountType::ProcessorClearing), Money::of($amount, $currency))];
        if ($taken > 0) {
            $postings[] = Posting::debit($wallet, Money::of($taken, $currency));
        }
        if ($bonusTaken > 0) {
            $postings[] = Posting::credit(AccountRef::platform(AccountType::PlatformPromotions), Money::of($bonusTaken, $currency));
        }
        if ($writtenOff > 0) {
            $postings[] = Posting::debit(AccountRef::platform(AccountType::PlatformRevenue), Money::of($writtenOff, $currency));
        }

        // The fan's wallet keeps its guard: it can never go below zero here.
        $transactions[] = $this->ledger->post(LedgerTransaction::make('chargeback', 'chargeback:'.$dispute->id, $postings, 'dispute', $dispute->id))->id;

        return [
            'wallet_minor' => $taken,
            // Of what was taken, the credits the fan had not spent (the rest came back from sales).
            'unspent_minor' => min($available, $taken),
            'bonus_minor' => $bonusTaken,
            'clawed_minor' => $clawed,
            'written_off_minor' => $writtenOff,
            'sales' => $sales,
            'transactions' => $transactions,
        ];
    }
}
