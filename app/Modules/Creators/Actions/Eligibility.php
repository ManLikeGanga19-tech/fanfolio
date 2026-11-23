<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;

/**
 * Reads the database every time (no cache): a suspension or failed check must stop
 * publishing and sales at once (rule 9).
 */
final readonly class Eligibility implements CreatorEligibility
{
    public function canPublish(string $userId): bool
    {
        return Creator::query()
            ->where('user_id', $userId)
            ->where('status', CreatorStatus::Active)
            ->where('kyc_status', KycStatus::Approved)
            ->whereNotNull('approved_at')
            ->exists();
    }

    public function canEarn(string $userId): bool
    {
        return $this->canPublish($userId);
    }
}
