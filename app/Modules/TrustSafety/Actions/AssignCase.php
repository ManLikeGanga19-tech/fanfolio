<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Models\ModerationCase;
use Illuminate\Support\Facades\DB;

/**
 * Plan M8a use case 26, canvas "Assign to me": a moderator or admin takes a pending case.
 * Taking one someone else holds is allowed and the audit entry names who held it.
 */
final readonly class AssignCase
{
    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function assign(string $caseId, string $staffId): ModerationCase
    {
        return $this->onPending($caseId, $staffId, function (ModerationCase $case) use ($staffId): ModerationCase {
            if ($case->assigned_to === $staffId) {
                return $case;
            }

            $previous = $case->assigned_to;
            $case->forceFill(['assigned_to' => $staffId, 'assigned_at' => now()])->save();
            $this->audit->record('case.assigned', $staffId, 'moderation_case', $case->id, ['taken_from' => $previous]);

            return $case;
        });
    }

    public function release(string $caseId, string $staffId): ModerationCase
    {
        return $this->onPending($caseId, $staffId, function (ModerationCase $case) use ($staffId): ModerationCase {
            if ($case->assigned_to === null) {
                return $case;
            }

            if ($case->assigned_to !== $staffId) {
                throw new TrustSafetyException('Someone else holds this case.', 'case');
            }

            $case->forceFill(['assigned_to' => null, 'assigned_at' => null])->save();
            $this->audit->record('case.unassigned', $staffId, 'moderation_case', $case->id);

            return $case;
        });
    }

    /**
     * @param  callable(ModerationCase): ModerationCase  $change
     */
    private function onPending(string $caseId, string $staffId, callable $change): ModerationCase
    {
        if (! $this->accounts->can($staffId, StaffAbility::WorkModeration)) {
            throw new TrustSafetyException('Your role cannot do this.', 'staff');
        }

        return DB::transaction(function () use ($caseId, $change): ModerationCase {
            $case = ModerationCase::query()->whereKey($caseId)->lockForUpdate()->first();

            if (! $case instanceof ModerationCase || ! $case->isPending()) {
                throw new TrustSafetyException('This case is already decided.', 'case');
            }

            return $change($case);
        });
    }
}
