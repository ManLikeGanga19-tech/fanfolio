<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\AccountRestrictions;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\Data\AccountSummary;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Contracts\RestrictionReason;
use App\Modules\TrustSafety\Events\UserBanned;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Notifications\AccountDecision;

/**
 * Plan M8a use case 17, admins only (D-18): signs the account out everywhere, blocks
 * sign-in and stops a creator earning at once (CreatorEligibility asks Identity), then
 * dispatches UserBanned. Blocking the person's verified ID from new accounts (D-17)
 * arrives with the KYC result hash.
 */
final readonly class BanAccount
{
    use DecidesOnAccounts;

    public function __construct(
        private Accounts $accounts,
        private AccountDirectory $directory,
        private AccountRestrictions $restrictions,
        private AuditLog $audit,
    ) {}

    public function handle(string $userId, string $staffId, RestrictionReason $reason, string $note): void
    {
        $target = $this->target($userId, $staffId, StaffAbility::BanUser);
        $note = self::note($note);

        if ($target->status === AccountSummary::BANNED) {
            throw new TrustSafetyException('This account is already banned.', 'user');
        }

        $this->restrictions->ban($userId);

        $this->audit->record('account.banned', $staffId, 'user', $userId, [
            'reason' => $reason->value,
            'note' => $note,
        ]);

        UserBanned::dispatch($userId, $staffId, $reason->value);

        $this->accounts->notify($userId, new AccountDecision(AccountDecision::BANNED, $reason));
    }
}
