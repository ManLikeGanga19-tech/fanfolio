<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Contracts\RestrictionReason;
use App\Modules\TrustSafety\Enums\CaseDecision;
use App\Modules\TrustSafety\Enums\CaseStatus;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Models\ModerationCase;
use App\Modules\TrustSafety\Notifications\AccountDecision;
use Illuminate\Support\Facades\DB;

/**
 * Plan M8a use case 27: decide an account case: no action, warn, strike, suspend, or
 * escalate to an admin. The decision note goes to the audit log. Strikes and suspensions
 * go through their own Actions, so the role rules there (D-18) still apply. A case that
 * is escalated, or may involve a minor, is decided by an admin (use case 29); a
 * moderator can only escalate it.
 */
final readonly class DecideCase
{
    private const int NOTE_MAX = 1000;

    public function __construct(
        private Accounts $accounts,
        private Strikes $strikes,
        private SuspendAccount $suspend,
        private AuditLog $audit,
    ) {}

    public function handle(
        string $caseId,
        string $staffId,
        CaseDecision $decision,
        string $note,
        ?RestrictionReason $reason = null,
        ?int $days = null,
    ): ModerationCase {
        if (! $this->accounts->can($staffId, StaffAbility::WorkModeration)) {
            throw new TrustSafetyException('Your role cannot do this.', 'staff');
        }

        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw new TrustSafetyException('Write a decision note.', 'note');
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new TrustSafetyException('Keep the note under '.self::NOTE_MAX.' characters.', 'note');
        }

        return DB::transaction(function () use ($caseId, $staffId, $decision, $note, $reason, $days): ModerationCase {
            $case = ModerationCase::query()->whereKey($caseId)->lockForUpdate()->first();

            if (! $case instanceof ModerationCase || ! $case->isPending()) {
                throw new TrustSafetyException('This case is already decided.', 'case');
            }

            if ($case->target_user_id === $staffId) {
                throw new TrustSafetyException('You cannot decide a case about your own account.', 'case');
            }

            if ($decision === CaseDecision::Escalate) {
                if ($case->status === CaseStatus::Escalated) {
                    throw new TrustSafetyException('This case is already with an admin.', 'case');
                }

                $case->forceFill(['status' => CaseStatus::Escalated])->save();
                $this->audit->record('case.escalated', $staffId, 'moderation_case', $case->id, ['note' => $note]);

                return $case;
            }

            if ($case->needsAdmin() && ! $this->accounts->can($staffId, StaffAbility::DecideEscalatedCase)) {
                throw new TrustSafetyException('Only an admin can decide this case. Escalate it instead.', 'case');
            }

            $reason ??= $case->reason->restriction();

            match ($decision) {
                CaseDecision::Strike => $this->strikes->give($case->target_user_id, $staffId, $reason, $note),
                CaseDecision::Suspend => $this->suspend->handle($case->target_user_id, $staffId, $days ?? 0, $reason, $note),
                CaseDecision::Warn => $this->accounts->notify($case->target_user_id, new AccountDecision(AccountDecision::WARNING, $reason)),
                default => null,
            };

            $case->forceFill([
                'status' => CaseStatus::Closed,
                'decision' => $decision,
                'decision_note' => $note,
                'decided_by' => $staffId,
                'decided_at' => now(),
            ])->save();

            $this->audit->record('case.decided', $staffId, 'moderation_case', $case->id, [
                'decision' => $decision->value,
                'user' => $case->target_user_id,
                'reason' => $decision->needsReason() ? $reason->value : null,
                'days' => $decision === CaseDecision::Suspend ? $days : null,
                'note' => $note,
            ]);

            return $case;
        });
    }
}
