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

/**
 * Board 8 · Action needed: staff send a submitted application back with one thing to
 * fix. The creator fixes it and submits again. "Retake ID photo" opens a new ID and
 * selfie check. The note, if any, is shown to the creator, so it must not hold
 * personal data.
 */
final readonly class RequestCreatorFix
{
    use ReviewsApplications;

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $staffId, FixKind $kind, ?string $note = null): Creator
    {
        $note = $note === null ? null : trim($note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > 300) {
            throw new CreatorException('Keep the note under 300 characters.', 'note');
        }

        $creator = $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId, $kind, $note): Creator {
            if ($creator->status !== CreatorStatus::Submitted) {
                throw new CreatorException('Only a submitted application can be sent back.', 'status');
            }

            $creator->forceFill([
                'status' => CreatorStatus::Onboarding,
                'kyc_status' => $kind === FixKind::RetakeId ? KycStatus::ActionNeeded : $creator->kyc_status,
                'fix_kind' => $kind,
                'fix_note' => $note,
                'reviewed_by' => $staffId,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record('creator.fix_requested', $staffId, 'creator', $creator->id, [
                'kind' => $kind->value,
                'with_note' => $note !== null,
            ]);

            return $creator;
        });

        $this->accounts->notify($creator->user_id, new ApplicationDecision(ApplicationDecision::FIX, $kind));

        return $creator;
    }
}
