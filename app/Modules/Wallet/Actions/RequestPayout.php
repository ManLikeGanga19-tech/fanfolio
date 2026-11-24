<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Contracts\Data\PayoutDestination;
use App\Modules\Creators\Contracts\PayoutDestinations;
use App\Modules\Identity\Contracts\NetworkRiskCheck;
use App\Modules\Identity\Contracts\SessionSecurity;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Jobs\SendPayoutJob;
use App\Modules\Wallet\Models\Payout;
use App\Support\Money\Money;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

use function array_filter;
use function array_values;
use function strlen;
use function trim;

/**
 * Spec "Payout flow", brief rule 3, decisions D-13 and D-16. A creator asks to withdraw
 * part of their earnings to one of their payout methods. Refused before money moves: no
 * withdrawals allowed (cannot earn, or payouts paused by an admin), under the minimum
 * ($20), no fresh second factor (D-13), a VPN or proxy (D-16), an unknown method, or a
 * withdrawal already in progress (one at a time). The amount is locked at once
 * (creator_earnings → payout_clearing), which also refuses more than is available.
 *
 * Flags send it to an admin: over $1,000 (rule 3), a payout method added in the last 7
 * days, a device added in the last 7 days (the spec's new-device rule), a payout name
 * that does not match the ID. Without flags it is approved and sent automatically.
 */
final readonly class RequestPayout
{
    public const int NEW_WITHIN_DAYS = 7;

    public function __construct(
        private Ledger $ledger,
        private CreatorEligibility $eligibility,
        private PayoutDestinations $destinations,
        private SessionSecurity $sessions,
        private NetworkRiskCheck $network,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $methodId, int $amountMinor, string $idempotencyKey, Session $session, string $ip): Payout
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new WalletException('Something went wrong. Reload the page and try again.', 'idempotency_key');
        }

        $existing = Payout::query()->where('creator_id', $creatorId)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof Payout) {
            return $existing;
        }

        if (! $this->eligibility->canWithdraw($creatorId)) {
            throw new WalletException('Withdrawals are not available on your account right now. Contact support.', 'account');
        }

        $minimum = config()->integer('fanfolio.money.payout_min_minor');
        if ($amountMinor < $minimum) {
            throw new WalletException('The minimum withdrawal is '.WalletDisplayService::dollars($minimum).'.', 'amount');
        }

        if (! $this->sessions->hasFreshSecondFactor($session)) {
            throw new WalletException('Confirm with your passkey or authenticator app first.', 'confirm');
        }

        if ($this->network->refuses('payouts') && $this->network->isAnonymised($ip)) {
            throw new WalletException('Turn off your VPN or proxy to continue.', 'network');
        }

        $destination = $this->destinations->find($creatorId, $methodId)
            ?? throw new WalletException('Choose where to send it.', 'method');

        $flags = $this->flags($creatorId, $amountMinor, $destination, $session);

        return DB::transaction(function () use ($creatorId, $idempotencyKey, $destination, $amountMinor, $flags): Payout {
            // One withdrawal at a time (velocity limit); the lock makes two requests at once safe.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['payouts:'.$creatorId]);
            $inProgress = array_values(array_filter(PayoutState::cases(), static fn (PayoutState $s): bool => $s->inProgress()));
            if (Payout::query()->where('creator_id', $creatorId)->whereIn('status', $inProgress)->exists()) {
                throw new WalletException('You already have a withdrawal in progress.', 'amount');
            }

            $payout = new Payout;
            $payout->forceFill([
                'creator_id' => $creatorId,
                'idempotency_key' => $idempotencyKey,
                'payout_method_id' => $destination->id,
                'rail' => $destination->rail,
                'display_hint' => $destination->displayHint,
                'amount_minor' => $amountMinor,
                'currency' => Money::CREDIT_CURRENCY,
                'status' => $flags === [] ? PayoutState::Approved : PayoutState::Review,
                'flags' => $flags,
                'requested_at' => now(),
            ])->save();

            $amount = Money::of($amountMinor, Money::CREDIT_CURRENCY);
            try {
                $this->ledger->post(LedgerTransaction::make('payout.requested', 'payout:'.$payout->id.':lock', [
                    Posting::debit(AccountRef::of(AccountType::CreatorEarnings, $creatorId), $amount),
                    Posting::credit(AccountRef::of(AccountType::PayoutClearing, $creatorId), $amount),
                ], 'payout', $payout->id));
            } catch (LedgerException $e) {
                throw $e->problem === LedgerException::INSUFFICIENT
                    ? new WalletException('That is more than you can withdraw.', 'amount')
                    : $e;
            }

            $this->audit->record('wallet.payout_requested', $creatorId, 'payout', $payout->id, [
                'amount_minor' => $amountMinor,
                'rail' => $destination->rail->value,
                'flags' => $flags,
                'auto_approved' => $flags === [],
            ]);

            if ($flags === []) {
                SendPayoutJob::dispatch($payout->id)->onQueue('payments')->afterCommit();
            }

            return $payout;
        });
    }

    /**
     * @return list<string>
     */
    private function flags(string $creatorId, int $amountMinor, PayoutDestination $destination, Session $session): array
    {
        $flags = [];

        if ($amountMinor > config()->integer('fanfolio.money.payout_manual_review_above_minor')) {
            $flags[] = Payout::OVER_REVIEW_LIMIT;
        }
        if ($destination->addedAt->gt(now()->subDays(self::NEW_WITHIN_DAYS))) {
            $flags[] = Payout::NEW_METHOD;
        }
        if ($this->sessions->isOnNewDevice($session, $creatorId, self::NEW_WITHIN_DAYS)) {
            $flags[] = Payout::NEW_DEVICE;
        }
        if (! $destination->nameMatchesKyc) {
            $flags[] = Payout::NAME_MISMATCH;
        }

        return $flags;
    }
}
