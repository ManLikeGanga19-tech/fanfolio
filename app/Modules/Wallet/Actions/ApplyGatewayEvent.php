<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Enums\TopUpStatus;
use App\Modules\Wallet\Events\WalletToppedUp;
use App\Modules\Wallet\Models\GatewayWebhook;
use App\Modules\Wallet\Models\TopUp;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Acts on a stored, verified webhook (on the payments queue). A confirmed payment posts
 * the top-up (`processor_clearing → fan_wallet`) and its bonus as a separate balanced
 * transaction (`platform_promotions → fan_wallet`, D-04), keyed to the top-up so a
 * second confirmation never credits twice. The amount must match what the fan chose.
 * A chargeback opens a dispute and takes the money back (D-20, ReceiveChargeback); the
 * network's decision settles it (SettleDispute). Card refunds are never issued by us
 * (fans' credits are not refunded to cards; see [CREDIT REFUND POLICY]), so a refund
 * event is stored and logged for a person to look at.
 */
final readonly class ApplyGatewayEvent
{
    public function __construct(
        private Ledger $ledger,
        private AuditLog $audit,
        private ReceiveChargeback $chargeback,
        private SettleDispute $settle,
    ) {}

    public function handle(string $webhookId): void
    {
        DB::transaction(function () use ($webhookId): void {
            $hook = GatewayWebhook::query()->whereKey($webhookId)->lockForUpdate()->first();

            if (! $hook instanceof GatewayWebhook || $hook->processed_at !== null) {
                return;
            }

            match ($hook->type) {
                GatewayEventType::PaymentSucceeded => $this->paid($hook),
                GatewayEventType::PaymentFailed => $this->failed($hook),
                GatewayEventType::Chargeback => $this->done($hook, $this->chargeback->handle($hook)),
                GatewayEventType::DisputeWon => $this->done($hook, $this->settle->handle($hook, won: true)),
                GatewayEventType::DisputeLost => $this->done($hook, $this->settle->handle($hook, won: false)),
                GatewayEventType::Refund => $this->refunded($hook),
            };
        });
    }

    private function paid(GatewayWebhook $hook): void
    {
        $topUp = $this->topUp($hook);
        if (! $topUp instanceof TopUp) {
            return;
        }

        if ($topUp->status === TopUpStatus::Paid) {
            $this->done($hook);

            return;
        }

        if ($topUp->amount_minor !== $hook->amount_minor || $topUp->currency !== $hook->currency) {
            Log::critical('Top-up amount does not match the gateway', ['topup' => $topUp->id, 'webhook' => $hook->id]);
            $this->done($hook, 'Amount does not match the top-up; nothing credited.');

            return;
        }

        $wallet = AccountRef::of(AccountType::FanWallet, $topUp->fan_id);
        // What the fan paid; a credit is a US cent, so this is also the credits bought.
        $paid = Money::of($topUp->amount_minor, $topUp->currency);

        $this->ledger->post(LedgerTransaction::make('topup', 'topup:'.$topUp->id, [
            Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), $paid),
            Posting::credit($wallet, $paid),
        ], 'topup', $topUp->id));

        if ($topUp->bonus_credits > 0) {
            $bonus = Money::of($topUp->bonus_credits, Money::CREDIT_CURRENCY);
            $this->ledger->post(LedgerTransaction::make('topup.bonus', 'topup-bonus:'.$topUp->id, [
                Posting::debit(AccountRef::platform(AccountType::PlatformPromotions), $bonus),
                Posting::credit($wallet, $bonus),
            ], 'topup', $topUp->id));
        }

        $topUp->forceFill(['status' => TopUpStatus::Paid, 'paid_at' => now(), 'payment_hint' => $hook->payment_hint])->save();
        $this->done($hook);

        $this->audit->record('wallet.topped_up', null, 'topup', $topUp->id, [
            'credits' => $topUp->credits,
            'bonus_credits' => $topUp->bonus_credits,
            'gateway_event' => $hook->event_id,
        ]);

        WalletToppedUp::dispatch($topUp->id, $topUp->fan_id, $topUp->totalCredits());
    }

    private function failed(GatewayWebhook $hook): void
    {
        $topUp = $this->topUp($hook);
        if (! $topUp instanceof TopUp) {
            return;
        }

        if ($topUp->status === TopUpStatus::Pending) {
            $topUp->forceFill(['status' => TopUpStatus::Failed, 'failed_at' => now()])->save();
        }

        $this->done($hook);
    }

    private function refunded(GatewayWebhook $hook): void
    {
        Log::critical('The processor reported a card refund FanFolio did not ask for', ['webhook' => $hook->id]);
        $this->done($hook, 'Card refund reported by the processor; not posted. Needs a person.');
    }

    private function topUp(GatewayWebhook $hook): ?TopUp
    {
        $topUp = TopUp::query()
            ->where('gateway', $hook->gateway)
            ->where('gateway_ref', $hook->gateway_ref)
            ->lockForUpdate()
            ->first();

        if (! $topUp instanceof TopUp) {
            Log::warning('Gateway webhook for an unknown top-up', ['webhook' => $hook->id]);
            $this->done($hook, 'No top-up with this gateway reference.');
        }

        return $topUp;
    }

    private function done(GatewayWebhook $hook, ?string $error = null): void
    {
        $hook->forceFill(['processed_at' => now(), 'error' => $error])->save();
    }
}
