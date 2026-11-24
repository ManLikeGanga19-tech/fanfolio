<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\AccountRestrictions;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Notifications\AccountDecision;

/**
 * Plan M8a use case 18, admins only (D-18): ends a suspension early or lifts a ban.
 * A creator page suspended by the ban stays suspended until staff reinstate it.
 */
final readonly class LiftRestriction
{
    use DecidesOnAccounts;

    public function __construct(
        private Accounts $accounts,
        private AccountDirectory $directory,
        private AccountRestrictions $restrictions,
        private AuditLog $audit,
    ) {}

    public function handle(string $userId, string $staffId, string $note): void
    {
        $target = $this->target($userId, $staffId, StaffAbility::LiftRestriction);
        $note = self::note($note);

        if (! $target->isRestricted()) {
            throw new TrustSafetyException('This account is not suspended or banned.', 'user');
        }

        $this->restrictions->lift($userId);

        $this->audit->record('account.restriction_lifted', $staffId, 'user', $userId, [
            'was' => $target->status,
            'note' => $note,
        ]);

        $this->accounts->notify($userId, new AccountDecision(AccountDecision::LIFTED));
    }
}
