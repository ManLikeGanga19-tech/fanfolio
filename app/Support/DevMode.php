<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Development shortcuts (decision D-14), switched on with FANFOLIO_DEV_MODE=true and
 * honoured only when APP_ENV=local:
 *
 * - any 6-digit email code verifies;
 * - the fake KYC provider shows a stand-in capture page that accepts any ID photo and
 *   any selfie (nothing is uploaded);
 * - the phone handoff offers "continue on this computer" for testing on a PC;
 * - `php artisan fanfolio:dev-staff` makes staff accounts.
 *
 * Never active in testing (tests exercise the real rules) and the app refuses to boot
 * in production with the switch on.
 */
final class DevMode
{
    public static function enabled(): bool
    {
        return config()->boolean('fanfolio.dev_mode') && app()->environment('local');
    }

    /** Called at boot: a production app with the switch on is a misconfiguration. */
    public static function assertSafe(): void
    {
        if (config()->boolean('fanfolio.dev_mode') && app()->isProduction()) {
            throw new RuntimeException('FANFOLIO_DEV_MODE must be off in production.');
        }
    }
}
