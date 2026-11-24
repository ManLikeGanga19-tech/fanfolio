<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Events\CreatorSuspended;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Notifications\CreatorStatusNotice;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use Carbon\CarbonImmutable;

/**
 * Staff stop an active creator page (plan M8a use case 11). Publishing and earning stop
 * at once (CreatorEligibility) and CreatorSuspended tells other modules to stop sales
 * (rule 9). An admin suspends until reinstated; a moderator pauses for 1 to 7 days and
 * the pause ends by itself (creators:end-pauses). The creator is told the category,
 * never the staff note.
 */
final readonly class SuspendCreator
{
    use ReviewsApplications;

    public const int MAX_PAUSE_DAYS = 7;

    public const array REASONS = [
        'policy' => 'Breaks the Creator Terms',
        'content' => 'Content under review',
        'identity' => 'Doubts about identity or consent',
        'payments' => 'Payment or chargeback risk',
        'leak_report' => 'Leak or stolen content report',
    ];

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    /**
     * @param  int|null  $days  null: until an admin reinstates; 1 to 7: a pause that ends by itself.
     */
    public function handle(string $creatorId, string $staffId, string $reason, ?string $note = null, ?int $days = null): Creator
    {
        $reason = trim($reason);
        $note = $note === null ? null : trim($note);

        if ($reason === '' || mb_strlen($reason) > 50) {
            throw new CreatorException('Choose a reason.', 'reason');
        }

        if ($note !== null && mb_strlen($note) > 500) {
            throw new CreatorException('Keep the note under 500 characters.', 'note');
        }

        if ($days !== null && ($days < 1 || $days > self::MAX_PAUSE_DAYS)) {
            throw new CreatorException('A pause lasts 1 to 7 days.', 'days');
        }

        $ability = $days === null ? StaffAbility::SuspendCreator : StaffAbility::PauseCreator;
        if (! $this->accounts->can($staffId, $ability)) {
            throw new CreatorException('Your role cannot do this.', 'staff');
        }

        $until = $days === null ? null : CarbonImmutable::now()->addDays($days);
        $changed = false;

        $creator = $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId, $reason, $note, $days, $until, &$changed): Creator {
            if ($creator->status === CreatorStatus::Suspended) {
                return $creator;
            }

            if ($creator->status !== CreatorStatus::Active) {
                throw new CreatorException('Only an active creator can be suspended.', 'status');
            }

            $creator->forceFill([
                'status' => CreatorStatus::Suspended,
                'suspended_at' => now(),
                'suspended_until' => $until,
            ])->save();

            $this->audit->record($days === null ? 'creator.suspended' : 'creator.paused', $staffId, 'creator', $creator->id, [
                'reason' => $reason,
                'note' => $note,
                'days' => $days,
            ]);

            CreatorSuspended::dispatch($creator->id, $creator->user_id, $reason);
            $changed = true;

            return $creator;
        });

        if ($changed) {
            $this->accounts->notify($creator->user_id, CreatorStatusNotice::suspended(self::REASONS[$reason] ?? null, $until));
        }

        return $creator;
    }
}
