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
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Jobs\SendPayoutJob;
use App\Modules\Wallet\Models\Payout;
use App\Modules\Wallet\Notifications\PayoutUpdate;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

use function in_array;
use function mb_strlen;
use function trim;

/**
 * Canvas AdminPayouts: an admin approves a payout waiting for review ("Approve & send"),
 * holds it ("Holding asks the creator for more information and keeps the funds
 * locked") or declines it (the money returns to the creator's earnings). Admins only,
 * never their own payout, audited (rule 12). The board itself arrives in M3 PR 5.
 */
final readonly class ReviewPayout
{
    public function __construct(
        private Ledger $ledger,
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function approve(string $payoutId, string $staffId, string $note = ''): Payout
    {
        return $this->decide($payoutId, $staffId, $note, false, function (Payout $payout): void {
            $payout->forceFill(['status' => PayoutState::Approved])->save();
            SendPayoutJob::dispatch($payout->id)->onQueue('payments')->afterCommit();
        }, 'wallet.payout_approved');
    }

    public function hold(string $payoutId, string $staffId, string $note): Payout
    {
        return $this->decide($payoutId, $staffId, $note, true, function (Payout $payout): void {
            $payout->forceFill(['status' => PayoutState::Held])->save();
            $this->accounts->notify($payout->creator_id, new PayoutUpdate(PayoutUpdate::HELD, $payout->amount_minor, $payout->display_hint));
        }, 'wallet.payout_held');
    }

    public function decline(string $payoutId, string $staffId, string $note): Payout
    {
        return $this->decide($payoutId, $staffId, $note, true, function (Payout $payout): void {
            $amount = Money::of($payout->amount_minor, $payout->currency);
            $this->ledger->post(LedgerTransaction::make('payout.declined', 'payout:'.$payout->id.':return', [
                Posting::debit(AccountRef::of(AccountType::PayoutClearing, $payout->creator_id), $amount),
                Posting::credit(AccountRef::of(AccountType::CreatorEarnings, $payout->creator_id), $amount),
            ], 'payout', $payout->id));

            $payout->forceFill(['status' => PayoutState::Rejected])->save();
            $this->accounts->notify($payout->creator_id, new PayoutUpdate(PayoutUpdate::DECLINED, $payout->amount_minor, $payout->display_hint));
        }, 'wallet.payout_declined');
    }

    /**
     * @param  callable(Payout): void  $apply
     */
    private function decide(string $payoutId, string $staffId, string $note, bool $noteRequired, callable $apply, string $action): Payout
    {
        if (! $this->accounts->can($staffId, StaffAbility::ReviewPayouts)) {
            throw new WalletException('Your role cannot do this.', 'staff');
        }

        $note = trim($note);
        if (($noteRequired && mb_strlen($note) < 3) || mb_strlen($note) > 500) {
            throw new WalletException('Write a note for the audit log.', 'note');
        }

        return DB::transaction(function () use ($payoutId, $staffId, $note, $apply, $action): Payout {
            $payout = Payout::query()->whereKey($payoutId)->lockForUpdate()->first();

            if (! $payout instanceof Payout || ! in_array($payout->status, [PayoutState::Review, PayoutState::Held], true)) {
                throw new WalletException('This payout is no longer waiting for review.', 'payout');
            }

            if ($payout->creator_id === $staffId) {
                throw new WalletException('You cannot review your own payout.', 'payout');
            }

            $apply($payout);
            $payout->forceFill(['reviewed_by' => $staffId, 'reviewed_at' => now(), 'review_note' => $note === '' ? null : $note])->save();
            $this->audit->record($action, $staffId, 'payout', $payout->id, ['note' => $note, 'amount_minor' => $payout->amount_minor]);

            return $payout;
        });
    }
}
