<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\CreatorKyc;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\Data\VerificationStatus;
use App\Modules\Identity\Contracts\StaffAbility;
use Carbon\CarbonImmutable;

/**
 * Plan M8a use case 6 and rule 13: the approvals board shows the applicant's age; the
 * date of birth itself is shown only when a reviewer asks for it, and each reveal is
 * written to the audit log with their name.
 */
final readonly class RevealApplicantDob
{
    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $staffId): CarbonImmutable
    {
        if (! $this->accounts->can($staffId, StaffAbility::ReviewCreators)) {
            throw new CreatorException('Only staff with MFA can review creators.', 'staff');
        }

        $creator = Creator::query()->findOrFail($creatorId);

        if ($creator->user_id === $staffId) {
            throw new CreatorException('You cannot review your own application.', 'staff');
        }

        $check = self::verifiedCheck($creator);

        if (! $check instanceof CreatorKyc || $check->dob === null) {
            throw new CreatorException('The provider did not return a date of birth.', 'dob');
        }

        $this->audit->record('kyc.dob_revealed', $staffId, 'creator_kyc', $check->id);

        return CarbonImmutable::parse($check->dob, 'UTC');
    }

    /** The latest check the provider passed or sent to review: the one staff decide on. */
    public static function verifiedCheck(Creator $creator): ?CreatorKyc
    {
        return $creator->kycChecks()
            ->whereIn('status', [VerificationStatus::Passed, VerificationStatus::Review])
            ->latest('checked_at')
            ->first();
    }
}
