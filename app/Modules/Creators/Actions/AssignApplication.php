<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\Accounts;

/**
 * Plan M8a use case 5, canvas AdminApprovals ("Assigned to you"): a staff member takes
 * a submitted application so two people do not work it at once. Taking one held by
 * someone else is allowed (they may be away) and the audit entry names who held it.
 * Holding an application is not required to decide on it.
 */
final readonly class AssignApplication
{
    use ReviewsApplications;

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function assign(string $creatorId, string $staffId): Creator
    {
        return $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId): Creator {
            $this->ensureSubmitted($creator);

            if ($creator->assigned_to === $staffId) {
                return $creator;
            }

            $previous = $creator->assigned_to;

            $creator->forceFill(['assigned_to' => $staffId, 'assigned_at' => now()])->save();

            $this->audit->record('creator.assigned', $staffId, 'creator', $creator->id, ['taken_from' => $previous]);

            return $creator;
        });
    }

    public function release(string $creatorId, string $staffId): Creator
    {
        return $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId): Creator {
            $this->ensureSubmitted($creator);

            if ($creator->assigned_to === null) {
                return $creator;
            }

            if ($creator->assigned_to !== $staffId) {
                throw new CreatorException('Someone else holds this application.', 'assigned');
            }

            $creator->forceFill(['assigned_to' => null, 'assigned_at' => null])->save();

            $this->audit->record('creator.unassigned', $staffId, 'creator', $creator->id);

            return $creator;
        });
    }

    private function ensureSubmitted(Creator $creator): void
    {
        if ($creator->status !== CreatorStatus::Submitted) {
            throw new CreatorException('This application is no longer waiting for review.', 'status');
        }
    }
}
