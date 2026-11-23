<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Events\CreatorKycFailed;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Notifications\ApplicationDecision;
use App\Modules\Identity\Contracts\Accounts;

/**
 * Staff refuse a submitted application, for example a suspected minor or a fake ID.
 * Like a provider rejection, it cannot be retried, and Trust & Safety hear about it
 * through CreatorKycFailed. The reason stays in the audit log; the creator sees a
 * neutral message.
 */
final readonly class RejectCreator
{
    use ReviewsApplications;

    public const array REASONS = [
        'suspected_minor' => 'Could be under 18',
        'suspected_fraud' => 'Fake or someone else\'s ID',
        'policy' => 'Breaks the Creator Terms',
    ];

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $staffId, string $reason): Creator
    {
        if (! array_key_exists($reason, self::REASONS)) {
            throw new CreatorException('Choose a reason.', 'reason');
        }

        $creator = $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId, $reason): Creator {
            if ($creator->status !== CreatorStatus::Submitted) {
                throw new CreatorException('Only a submitted application can be rejected.', 'status');
            }

            $creator->forceFill([
                'status' => CreatorStatus::Onboarding,
                'kyc_status' => KycStatus::Rejected,
                'reviewed_by' => $staffId,
                'reviewed_at' => now(),
                'fix_kind' => null,
                'fix_note' => null,
            ])->save();

            $this->audit->record('creator.rejected', $staffId, 'creator', $creator->id, ['reason' => $reason]);

            CreatorKycFailed::dispatch($creator->id, $creator->user_id, $reason);

            return $creator;
        });

        $this->accounts->notify($creator->user_id, new ApplicationDecision(ApplicationDecision::REJECTED));

        return $creator;
    }
}
