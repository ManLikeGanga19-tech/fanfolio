<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;

/**
 * Plan M8a use case 13: an admin pauses a creator's payouts (for example while a dispute
 * is looked into) and resumes them later. Publishing and sales go on; withdrawals wait
 * (CreatorEligibility::canWithdraw). A note is required and audited.
 */
final readonly class PauseCreatorPayouts
{
    use ReviewsApplications;

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function pause(string $creatorId, string $staffId, string $note): Creator
    {
        return $this->change($creatorId, $staffId, $note, true);
    }

    public function resume(string $creatorId, string $staffId, string $note): Creator
    {
        return $this->change($creatorId, $staffId, $note, false);
    }

    private function change(string $creatorId, string $staffId, string $note, bool $pause): Creator
    {
        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 500) {
            throw new CreatorException('Write why, for the audit log.', 'note');
        }

        if (! $this->accounts->can($staffId, StaffAbility::PauseCreatorPayouts)) {
            throw new CreatorException('Your role cannot do this.', 'staff');
        }

        return $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId, $note, $pause): Creator {
            if (($creator->payouts_paused_at !== null) === $pause) {
                return $creator;
            }

            $creator->forceFill(['payouts_paused_at' => $pause ? now() : null])->save();
            $this->audit->record($pause ? 'creator.payouts_paused' : 'creator.payouts_resumed', $staffId, 'creator', $creator->id, ['note' => $note]);

            return $creator;
        });
    }
}
