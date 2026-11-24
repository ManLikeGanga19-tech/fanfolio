<?php

declare(strict_types=1);

namespace App\Modules\Creators\Contracts;

/**
 * M2 "Done when": an unverified creator cannot publish or earn. Content (publishing)
 * and Wallet and Subscriptions (sales, payouts) ask this before acting, every time.
 */
interface CreatorEligibility
{
    /** Approved by staff, KYC approved and still valid, not suspended. */
    public function canPublish(string $userId): bool;

    /** The same conditions: no sale or payout for a creator who cannot publish. */
    public function canEarn(string $userId): bool;

    /** Can earn, and an admin has not paused this creator's payouts (plan M8a use case 13). */
    public function canWithdraw(string $userId): bool;
}
