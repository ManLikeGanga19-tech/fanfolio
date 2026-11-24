<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

use Carbon\CarbonImmutable;

/**
 * Account status changes that Trust & Safety decides (plan M8a, use cases 16–20). These
 * only change the account; the deciding module checks the staff member's rights, audits
 * the decision and tells the user. Suspending or banning signs every device out at once.
 */
interface AccountRestrictions
{
    public function suspend(string $userId, CarbonImmutable $until): void;

    public function ban(string $userId): void;

    /** Back to active, from a suspension or a ban. */
    public function lift(string $userId): void;

    /** Signs every device out; returns how many were signed out. */
    public function signOutEverywhere(string $userId): int;

    /** Suspended (and not yet ended) or banned. An unknown account has nothing to restrict. */
    public function isRestricted(string $userId): bool;
}
