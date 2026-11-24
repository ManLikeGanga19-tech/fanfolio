<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Events\CreatorApproved;
use App\Modules\Creators\Exceptions\CreatorException;
use App\Modules\Creators\Models\Creator;
use App\Modules\Creators\Models\PayoutMethod;
use App\Modules\Creators\Notifications\ApplicationDecision;
use App\Modules\Identity\Contracts\Accounts;

/**
 * Staff approve a submitted application whose ID and selfie the provider passed.
 * The creator can then publish and earn (CreatorEligibility). Identity gives the
 * account the creator role when it hears CreatorApproved.
 */
final readonly class ApproveCreator
{
    use ReviewsApplications;

    public function __construct(
        private Accounts $accounts,
        private AuditLog $audit,
    ) {}

    public function handle(string $creatorId, string $staffId): Creator
    {
        $creator = $this->decide($this->accounts, $creatorId, $staffId, function (Creator $creator) use ($staffId): Creator {
            if ($creator->status !== CreatorStatus::Submitted || $creator->kyc_status !== KycStatus::Review) {
                throw new CreatorException('Only a submitted application with a passed ID check can be approved.', 'status');
            }

            $method = $creator->payoutMethod();
            if (! $method instanceof PayoutMethod) {
                throw new CreatorException('The creator has no payout method.', 'status');
            }

            $creator->forceFill([
                'status' => CreatorStatus::Active,
                'kyc_status' => KycStatus::Approved,
                'approved_at' => now(),
                'reviewed_by' => $staffId,
                'reviewed_at' => now(),
                'fix_kind' => null,
                'fix_note' => null,
            ])->save();

            $this->audit->record('creator.approved', $staffId, 'creator', $creator->id, [
                'payout_name_matches_kyc' => $method->name_matches_kyc,
            ]);

            CreatorApproved::dispatch($creator->id, $creator->user_id);

            return $creator;
        });

        $this->accounts->notify($creator->user_id, new ApplicationDecision(ApplicationDecision::APPROVED));

        return $creator;
    }
}
