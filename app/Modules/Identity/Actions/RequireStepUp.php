<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use Illuminate\Contracts\Session\Session;

/**
 * Step-up (SEC-04): sensitive changes need a factor confirmed within the last few
 * minutes. Reused for payouts and payout-method changes (M3) and consent (M7).
 *
 * Signing in counts as a confirmation. Users with MFA confirm with a passkey or
 * authenticator code; users without MFA (fans) confirm with their password.
 */
final class RequireStepUp
{
    private const string KEY = 'identity.step_up_at';

    public static function confirm(Session $session): void
    {
        $session->put(self::KEY, now()->getTimestamp());
    }

    public static function isFresh(Session $session): bool
    {
        $at = $session->get(self::KEY);

        return is_int($at) && now()->getTimestamp() - $at <= config()->integer('fanfolio.identity.step_up_minutes') * 60;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
