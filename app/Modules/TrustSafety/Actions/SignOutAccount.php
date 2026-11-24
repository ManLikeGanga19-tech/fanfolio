<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\AccountRestrictions;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;

/**
 * Plan M8a use case 20: sign the account out on every device (for example after a
 * suspected takeover). The account can sign in again.
 */
final readonly class SignOutAccount
{
    use DecidesOnAccounts;

    public function __construct(
        private Accounts $accounts,
        private AccountDirectory $directory,
        private AccountRestrictions $restrictions,
        private AuditLog $audit,
    ) {}

    public function handle(string $userId, string $staffId): int
    {
        $this->target($userId, $staffId, StaffAbility::SignOutUser);

        $devices = $this->restrictions->signOutEverywhere($userId);

        $this->audit->record('account.signed_out_everywhere', $staffId, 'user', $userId, ['devices' => $devices]);

        return $devices;
    }
}
