<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Events\CreatorReinstated;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Notifications\CreatorStatusNotice;
use App\Modules\Identity\Contracts\AccountRestrictions;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use Illuminate\Support\Facades\DB;

/**
 * Plan M8a use case 11: an admin reactivates a suspended or paused creator page, with a
 * reason for the audit log; and a moderator's pause ends by itself when its time is up.
 * A page whose account is still suspended or banned stays shut: lift that first on the
 * Users & bans board.
 */
final readonly class ReinstateCreator
{
    use ReviewsApplications;

    public function __construct(
        private Accounts $accounts,
        private AccountRestrictions $restrictions,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $staffId, string $note): Creator
    {
        $note = trim($note);

        if ($note === '' || mb_strlen($note) > 500) {
            throw new CreatorException('Write why, for the audit log.', 'note');
        }

        if (! $this->accounts->can($staffId, StaffAbility::ReinstateCreator)) {
            throw new CreatorException('Your role cannot do this.', 'staff');
        }

        $creator = $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId, $note): Creator {
            if ($creator->status !== CreatorStatus::Suspended) {
                throw new CreatorException('Only a suspended creator can be reinstated.', 'status');
            }

            if ($this->restrictions->isRestricted($creator->user_id)) {
                throw new CreatorException('The account is suspended or banned. Lift that on Users & bans first.', 'status');
            }

            $this->activate($creator);
            $this->audit->record('creator.reinstated', $staffId, 'creator', $creator->id, ['note' => $note]);

            return $creator;
        });

        $this->accounts->notify($creator->user_id, CreatorStatusNotice::reinstated());

        return $creator;
    }

    /**
     * A pause whose time is up (creators:end-pauses). Idempotent: false when there was
     * nothing to end.
     */
    public function endPause(string $creatorId): bool
    {
        $ended = DB::transaction(function () use ($creatorId): bool {
            $creator = Creator::query()->whereKey($creatorId)->lockForUpdate()->first();

            if (! $creator instanceof Creator
                || $creator->status !== CreatorStatus::Suspended
                || $creator->suspended_until === null
                || $creator->suspended_until->isFuture()) {
                return false;
            }

            $this->activate($creator);
            $this->audit->record('creator.pause_ended', null, 'creator', $creator->id);

            return true;
        });

        if ($ended) {
            $userId = Creator::query()->whereKey($creatorId)->value('user_id');
            if (is_string($userId)) {
                $this->accounts->notify($userId, CreatorStatusNotice::reinstated());
            }
        }

        return $ended;
    }

    private function activate(Creator $creator): void
    {
        $creator->forceFill([
            'status' => CreatorStatus::Active,
            'suspended_at' => null,
            'suspended_until' => null,
        ])->save();

        CreatorReinstated::dispatch($creator->id, $creator->user_id);
    }
}
