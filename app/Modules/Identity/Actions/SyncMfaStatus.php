<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Models\User;

/**
 * Keeps users.mfa_enabled true exactly while the user has a passkey or a confirmed
 * authenticator app, and records every change.
 */
final readonly class SyncMfaStatus
{
    public function __construct(private AuditLog $audit) {}

    public function handle(User $user): void
    {
        $enabled = $user->hasPasskey() || $user->hasConfirmedTotp();

        if ($user->mfa_enabled === $enabled) {
            return;
        }

        $user->forceFill(['mfa_enabled' => $enabled])->save();

        $this->audit->record($enabled ? 'identity.mfa_enabled' : 'identity.mfa_disabled', $user->id, 'user', $user->id);
    }
}
