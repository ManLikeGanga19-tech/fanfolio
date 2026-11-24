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
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Notifications\AccountDecision;
use Carbon\CarbonImmutable;

/**
 * Plan M8a use case 16: suspend for 1, 7 or 30 days. Moderators up to 7 (D-18). The
 * account is signed out everywhere at once and the suspension lifts itself.
 */
final readonly class SuspendAccount
{
    use DecidesOnAccounts;

    public const array DAYS = [1, 7, 30];

    public function __construct(
        private Accounts $accounts,
        private AccountDirectory $directory,
        private AccountRestrictions $restrictions,
        private AuditLog $audit,
    ) {}

    public function handle(string $userId, string $staffId, int $days, RestrictionReason $reason, string $note): CarbonImmutable
    {
        $target = $this->target($userId, $staffId, StaffAbility::SuspendUser);
        $note = self::note($note);

        if (! in_array($days, self::DAYS, true)) {
            throw new TrustSafetyException('Choose 1, 7 or 30 days.', 'days');
        }

        if ($days > 7 && ! $this->accounts->can($staffId, StaffAbility::SuspendUserLong)) {
            throw new TrustSafetyException('Moderators can suspend for up to 7 days. Ask an admin for longer.', 'days');
        }

        if ($target->status === AccountSummary::BANNED) {
            throw new TrustSafetyException('This account is banned. Lift the ban first.', 'user');
        }

        $until = CarbonImmutable::now()->addDays($days);
        $this->restrictions->suspend($userId, $until);

        $this->audit->record('account.suspended', $staffId, 'user', $userId, [
            'days' => $days,
            'until' => $until->utc()->toIso8601String(),
            'reason' => $reason->value,
            'note' => $note,
        ]);

        $this->accounts->notify($userId, new AccountDecision(AccountDecision::SUSPENDED, $reason, $until));

        return $until;
    }
}
