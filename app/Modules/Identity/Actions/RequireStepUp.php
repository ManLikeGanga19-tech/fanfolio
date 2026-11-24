<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use Illuminate\Contracts\Session\Session;

/**
 * Step-up (SEC-04): sensitive changes need a factor confirmed within the last few
 * minutes. Reused for payouts and payout-method changes (M3) and consent (M7).
 *
 * Two levels:
 * - Any step-up: signing in counts. Users with MFA confirm with a passkey or
 *   authenticator code (or a recovery code); users without MFA with their password.
 * - Second factor (decision D-13): payouts and payout details need a passkey or an
 *   authenticator code confirmed on purpose, just before. Signing in, a password or a
 *   recovery code does not count, so a stolen session or password cannot move money.
 */
final class RequireStepUp
{
    private const string KEY = 'identity.step_up_at';

    private const string SECOND_FACTOR_KEY = 'identity.step_up_second_factor_at';

    /** Set while the confirm page is asked for a second factor only. */
    public const string NEEDS_SECOND_FACTOR = 'identity.step_up_needs_second_factor';

    public static function confirm(Session $session): void
    {
        $session->put(self::KEY, now()->getTimestamp());
    }

    /** A passkey or authenticator code was just confirmed on the "Confirm it's you" page. */
    public static function confirmSecondFactor(Session $session): void
    {
        self::confirm($session);
        $session->put(self::SECOND_FACTOR_KEY, now()->getTimestamp());
        $session->forget(self::NEEDS_SECOND_FACTOR);
    }

    public static function isFresh(Session $session): bool
    {
        return self::recent($session->get(self::KEY));
    }

    public static function isFreshSecondFactor(Session $session): bool
    {
        return self::recent($session->get(self::SECOND_FACTOR_KEY));
    }

    public static function forget(Session $session): void
    {
        $session->forget([self::KEY, self::SECOND_FACTOR_KEY, self::NEEDS_SECOND_FACTOR]);
    }

    private static function recent(mixed $at): bool
    {
        return is_int($at) && now()->getTimestamp() - $at <= config()->integer('fanfolio.identity.step_up_minutes') * 60;
    }
}
