<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\Data\AccountSummary;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;

/**
 * Checks shared by every decision on an account, inside the Action (SEC-15): the staff
 * member holds the ability, does not act on their own account, and the target is not
 * staff (staff accounts are managed on the Staff board).
 *
 * @property-read Accounts $accounts
 * @property-read AccountDirectory $directory
 */
trait DecidesOnAccounts
{
    private const int NOTE_MAX = 500;

    private function target(string $userId, string $staffId, StaffAbility $ability): AccountSummary
    {
        if (! $this->accounts->can($staffId, $ability)) {
            throw new TrustSafetyException('Your role cannot do this.', 'staff');
        }

        if ($userId === $staffId) {
            throw new TrustSafetyException('You cannot act on your own account.', 'user');
        }

        $target = $this->directory->find($userId);

        if (! $target instanceof AccountSummary) {
            throw new TrustSafetyException('That account does not exist.', 'user');
        }

        if ($target->isStaff) {
            throw new TrustSafetyException('Staff accounts are managed on the Staff board.', 'user');
        }

        return $target;
    }

    /** The staff note is required and goes to the audit log only, never to the user. */
    private static function note(string $note): string
    {
        $note = trim($note);

        if (mb_strlen($note) < 3) {
            throw new TrustSafetyException('Write the reason.', 'note');
        }

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new TrustSafetyException('Keep the reason under '.self::NOTE_MAX.' characters.', 'note');
        }

        return $note;
    }
}
