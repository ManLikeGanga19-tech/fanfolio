<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\FixKind;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Notifications\ApplicationDecision;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;

/**
 * Plan M8a use case 12: staff doubt an approved creator's ID (for example a report that
 * the account is someone else's). The page goes back to "One thing to fix · Retake ID
 * photo" (board 8): publishing and earning stop at once (rule 9), the creator verifies
 * again, submits, and the application returns to the approvals queue.
 */
final readonly class RequireIdRecheck
{
    use ReviewsApplications;

    /** Shown to the creator on "One thing to fix" and in the email; the staff note is not. */
    public const string MESSAGE = 'We need to verify your ID again before you can keep posting and earning. Everything else is fine.';

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $staffId, string $note): Creator
    {
        $note = trim($note);

        if ($note === '' || mb_strlen($note) > 500) {
            throw new CreatorException('Write why, for the audit log.', 'note');
        }

        if (! $this->accounts->can($staffId, StaffAbility::RequireIdRecheck)) {
            throw new CreatorException('Your role cannot do this.', 'staff');
        }

        $creator = $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId, $note): Creator {
            if ($creator->status !== CreatorStatus::Active) {
                throw new CreatorException('Only an active creator can be asked for a new ID check.', 'status');
            }

            $creator->forceFill([
                'status' => CreatorStatus::Onboarding,
                'kyc_status' => KycStatus::ActionNeeded,
                'fix_kind' => FixKind::RetakeId,
                // The staff note may hold personal data, so it stays in the audit log.
                'fix_note' => self::MESSAGE,
                'reviewed_by' => $staffId,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record('creator.id_recheck_required', $staffId, 'creator', $creator->id, ['note' => $note]);

            return $creator;
        });

        $this->accounts->notify($creator->user_id, new ApplicationDecision(ApplicationDecision::FIX, FixKind::RetakeId, self::MESSAGE));

        return $creator;
    }
}
