<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Wallet\Contracts\DisputeGateway;
use App\Modules\Wallet\Enums\DisputeStatus;
use App\Modules\Wallet\Events\DisputeDecided;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Models\Dispute;
use Illuminate\Support\Facades\DB;
use RuntimeException;

use function array_column;
use function mb_strlen;
use function mb_strtolower;
use function trim;

/**
 * Canvas AdminDisputes buttons (plan M8a use case 37), admins only, each audited (rule 12):
 *
 * - Submit evidence: the lines DisputeEvidence gathered go to the processor through the
 *   DisputeGateway; the network decides later (SettleDispute).
 * - Accept & refund: we do not contest it. The card holder keeps the refund; the money
 *   already came back from the wallet and the sales when the chargeback arrived (D-20).
 * - Lift the freeze: once no dispute of the fan is open, an admin may give the wallet
 *   back (D-20: "frozen until an admin decides").
 *
 * Nobody acts on a dispute of their own account.
 */
final readonly class RespondToDispute
{
    public function __construct(
        private DisputeGateway $gateway,
        private DisputeEvidence $evidence,
        private Accounts $accounts,
        private AuditLog $audit,
        private UnfreezeDisputedHolds $unfreeze,
    ) {}

    public function submitEvidence(string $disputeId, string $staffId): Dispute
    {
        return DB::transaction(function () use ($disputeId, $staffId): Dispute {
            $dispute = $this->open($disputeId, $staffId);

            if ($dispute->respond_by->isPast()) {
                throw new WalletException('The deadline to respond has passed. The bank will decide without evidence.', 'dispute');
            }

            $lines = array_column($this->evidence->lines($dispute), 'text');
            $this->call(fn () => $this->gateway->submitEvidence($dispute->gateway_case_ref, $lines));

            $dispute->forceFill([
                'status' => DisputeStatus::EvidenceSent,
                'evidence' => $lines,
                'evidence_sent_at' => now(),
                'evidence_sent_by' => $staffId,
            ])->save();

            $this->audit->record('wallet.dispute_evidence_sent', $staffId, 'dispute', $dispute->id, [
                'case' => $dispute->gateway_case_ref,
                'lines' => $lines,
            ]);

            return $dispute;
        });
    }

    public function accept(string $disputeId, string $staffId, string $note): Dispute
    {
        $note = $this->note($note);

        return DB::transaction(function () use ($disputeId, $staffId, $note): Dispute {
            $dispute = $this->open($disputeId, $staffId);
            $this->call(fn () => $this->gateway->accept($dispute->gateway_case_ref));

            $dispute->forceFill(['status' => DisputeStatus::Accepted, 'decided_at' => now(), 'accepted_by' => $staffId])->save();
            $this->unfreeze->handle($dispute->fan_id);

            $this->audit->record('wallet.dispute_accepted', $staffId, 'dispute', $dispute->id, [
                'case' => $dispute->gateway_case_ref,
                'note' => $note,
            ]);

            DisputeDecided::dispatch($dispute->id, $dispute->fan_id, DisputeStatus::Accepted->value);

            return $dispute;
        });
    }

    public function liftFreeze(string $fanId, string $staffId, string $note): void
    {
        $this->allowed($staffId, $fanId);
        $note = $this->note($note);

        DB::transaction(function () use ($fanId, $staffId, $note): void {
            $frozen = DB::table('wallet_freezes')->where('fan_id', $fanId)->whereNull('lifted_at')->lockForUpdate()->exists();

            if (! $frozen) {
                throw new WalletException('This wallet is not frozen.', 'wallet');
            }

            if (Dispute::query()->where('fan_id', $fanId)->whereIn('status', DisputeStatus::UNDECIDED)->exists()) {
                throw new WalletException('A dispute on this wallet is still open. Wait for the bank\'s decision or accept it first.', 'wallet');
            }

            DB::table('wallet_freezes')->where('fan_id', $fanId)->update(['lifted_at' => now()]);

            $this->audit->record('wallet.freeze_lifted', $staffId, 'user', $fanId, ['note' => $note]);
        });
    }

    /** The dispute, locked, still waiting for us. */
    private function open(string $disputeId, string $staffId): Dispute
    {
        $dispute = Dispute::query()->whereKey($disputeId)->lockForUpdate()->first();

        if (! $dispute instanceof Dispute) {
            throw new WalletException('There is no dispute with this reference.', 'dispute');
        }

        $this->allowed($staffId, $dispute->fan_id);

        if ($dispute->status === DisputeStatus::EvidenceSent) {
            throw new WalletException('Evidence was already sent. Waiting for the bank\'s decision.', 'dispute');
        }

        if ($dispute->status !== DisputeStatus::Open) {
            throw new WalletException('This dispute is closed ('.mb_strtolower($dispute->status->label()).').', 'dispute');
        }

        return $dispute;
    }

    private function allowed(string $staffId, string $fanId): void
    {
        if (! $this->accounts->can($staffId, StaffAbility::HandleDisputes)) {
            throw new WalletException('Your role cannot do this.', 'staff');
        }

        if ($staffId === $fanId) {
            throw new WalletException('You cannot act on a dispute of your own account.', 'dispute');
        }
    }

    private function note(string $note): string
    {
        $note = trim($note);

        if (mb_strlen($note) < 3 || mb_strlen($note) > 500) {
            throw new WalletException('Write a note for the audit log.', 'note');
        }

        return $note;
    }

    /** @param  callable(): void  $call */
    private function call(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            throw new WalletException('The processor did not take it: '.$e->getMessage().' Try again in a few minutes.', 'gateway');
        }
    }
}
