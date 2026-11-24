<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Events\CreatorSuspended;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\Accounts;

/**
 * Staff suspend an active creator. Publishing and earning stop at once
 * (CreatorEligibility) and CreatorSuspended tells other modules to stop sales (rule 9).
 * The users board that calls it arrives in M8.
 */
final readonly class SuspendCreator
{
    use ReviewsApplications;

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $staffId, string $reason): Creator
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 50) {
            throw new CreatorException('Give a short reason code.', 'reason');
        }

        return $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId, $reason): Creator {
            if ($creator->status === CreatorStatus::Suspended) {
                return $creator;
            }

            if ($creator->status !== CreatorStatus::Active) {
                throw new CreatorException('Only an active creator can be suspended.', 'status');
            }

            $creator->forceFill([
                'status' => CreatorStatus::Suspended,
                'suspended_at' => now(),
            ])->save();

            $this->audit->record('creator.suspended', $staffId, 'creator', $creator->id, ['reason' => $reason]);

            CreatorSuspended::dispatch($creator->id, $creator->user_id, $reason);

            return $creator;
        });
    }
}
