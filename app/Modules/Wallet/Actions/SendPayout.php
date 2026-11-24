<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Contracts\PayoutDestinations;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\PayoutInstruction;
use App\Modules\Wallet\Contracts\Data\PayoutResult;
use App\Modules\Wallet\Contracts\Data\PayoutStatus;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Events\PayoutFailed;
use App\Modules\Wallet\Events\PayoutSent;
use App\Modules\Wallet\Models\Payout;
use App\Modules\Wallet\Notifications\PayoutUpdate;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

use function in_array;
use function mb_substr;

/**
 * Spec postings "Payout sent" (payout_clearing → processor_clearing) and "Payout failed"
 * (payout_clearing → creator_earnings: the money returns automatically). Sending is
 * outside any database transaction, so a slow provider never holds row locks; the
 * gateway is idempotent per payout id, so a retry never pays twice. A provider that
 * answers "pending" is asked again by `wallet:payout-status`.
 */
final readonly class SendPayout
{
    public function __construct(
        private PayoutGateways $gateways,
        private PayoutDestinations $destinations,
        private Ledger $ledger,
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function send(string $payoutId): void
    {
        $payout = DB::transaction(function () use ($payoutId): ?Payout {
            $payout = Payout::query()->whereKey($payoutId)->lockForUpdate()->first();
            if (! $payout instanceof Payout || ! in_array($payout->status, [PayoutState::Approved, PayoutState::Sending], true)) {
                return null;
            }

            $payout->forceFill(['status' => PayoutState::Sending])->save();

            return $payout;
        });

        if (! $payout instanceof Payout) {
            return;
        }

        $instruction = $this->destinations->instructionFor($payout->creator_id, $payout->payout_method_id);
        if ($instruction === null) {
            $this->settle($payout->id, new PayoutResult(PayoutStatus::Failed, 'none:'.$payout->id, 'The payout method was removed.'));

            return;
        }

        $result = $this->gateways->for($payout->rail)->send(new PayoutInstruction(
            $payout->id,
            $payout->rail,
            Money::of($payout->amount_minor, $payout->currency),
            $instruction['account_name'],
            $instruction['details'],
        ));

        $this->settle($payout->id, $result);
    }

    /** Asks the provider again about a payout it said was pending. */
    public function refresh(string $payoutId): void
    {
        $payout = Payout::query()->whereKey($payoutId)->where('status', PayoutState::Sending)->whereNotNull('gateway_ref')->first();
        if (! $payout instanceof Payout) {
            return;
        }

        $this->settle($payout->id, $this->gateways->for($payout->rail)->status((string) $payout->gateway_ref));
    }

    public function settle(string $payoutId, PayoutResult $result): void
    {
        DB::transaction(function () use ($payoutId, $result): void {
            $payout = Payout::query()->whereKey($payoutId)->lockForUpdate()->first();
            if (! $payout instanceof Payout || $payout->status !== PayoutState::Sending) {
                return;
            }

            $amount = Money::of($payout->amount_minor, $payout->currency);
            $clearing = AccountRef::of(AccountType::PayoutClearing, $payout->creator_id);

            if ($result->status === PayoutStatus::Pending) {
                $payout->forceFill(['gateway_ref' => $result->gatewayRef])->save();

                return;
            }

            if ($result->status === PayoutStatus::Sent) {
                $this->ledger->post(LedgerTransaction::make('payout.sent', 'payout:'.$payout->id.':sent', [
                    Posting::debit($clearing, $amount),
                    Posting::credit(AccountRef::platform(AccountType::ProcessorClearing), $amount),
                ], 'payout', $payout->id));

                $payout->forceFill(['status' => PayoutState::Sent, 'gateway_ref' => $result->gatewayRef, 'sent_at' => now()])->save();
                $this->audit->record('wallet.payout_sent', null, 'payout', $payout->id, ['amount_minor' => $payout->amount_minor]);
                PayoutSent::dispatch($payout->id, $payout->creator_id, $payout->amount_minor);
                $this->accounts->notify($payout->creator_id, new PayoutUpdate(PayoutUpdate::SENT, $payout->amount_minor, $payout->display_hint));

                return;
            }

            $this->ledger->post(LedgerTransaction::make('payout.failed', 'payout:'.$payout->id.':return', [
                Posting::debit($clearing, $amount),
                Posting::credit(AccountRef::of(AccountType::CreatorEarnings, $payout->creator_id), $amount),
            ], 'payout', $payout->id));

            $reason = mb_substr($result->failureReason ?? 'The payout provider refused it.', 0, 200);
            $payout->forceFill(['status' => PayoutState::Failed, 'gateway_ref' => $result->gatewayRef, 'failure_reason' => $reason, 'failed_at' => now()])->save();
            $this->audit->record('wallet.payout_failed', null, 'payout', $payout->id, ['reason' => $reason]);
            PayoutFailed::dispatch($payout->id, $payout->creator_id, $payout->amount_minor);
            $this->accounts->notify($payout->creator_id, new PayoutUpdate(PayoutUpdate::FAILED, $payout->amount_minor, $payout->display_hint));
        });
    }
}
