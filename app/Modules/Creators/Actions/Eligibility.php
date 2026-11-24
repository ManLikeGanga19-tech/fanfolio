<?php

declare(strict_types=1);

namespace App\Modules\Creators\Actions;

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Contracts\AccountRestrictions;

/**
 * Reads the database every time (no cache): a suspension or failed check must stop
 * publishing and sales at once (rule 9). A suspended or banned account (plan M8a)
 * stops both too, and starts again by itself when a suspension ends.
 */
final readonly class Eligibility implements CreatorEligibility
{
    public function __construct(private AccountRestrictions $restrictions) {}

    public function canPublish(string $userId): bool
    {
        $creatorOk = Creator::query()
            ->where('user_id', $userId)
            ->where('status', CreatorStatus::Active)
            ->where('kyc_status', KycStatus::Approved)
            ->whereNotNull('approved_at')
            // An expired ID stops publishing and earning until a new one is verified.
            // Null: the document has no expiry date.
            ->where(static fn ($q) => $q->whereNull('id_expires_at')->orWhere('id_expires_at', '>', now()))
            ->exists();

        return $creatorOk && ! $this->restrictions->isRestricted($userId);
    }

    public function canEarn(string $userId): bool
    {
        return $this->canPublish($userId);
    }

    public function canWithdraw(string $userId): bool
    {
        return $this->canEarn($userId)
            && ! Creator::query()->where('user_id', $userId)->whereNotNull('payouts_paused_at')->exists();
    }
}
