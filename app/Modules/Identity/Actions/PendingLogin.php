<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Holds an account that is part-way through signing in. Nothing is signed in until the
 * hold is released; it expires after a few minutes.
 *
 * Stages: "mfa" waits for the second factor; "devices" has passed every factor but the
 * account is at its device limit (D-03), so the user may sign out a device and continue.
 */
final class PendingLogin
{
    public const string MFA = 'mfa';

    public const string DEVICES = 'devices';

    private const string KEY = 'identity.mfa_pending';

    public static function start(Session $session, User $user, string $stage = self::MFA, ?string $method = null): void
    {
        $session->put(self::KEY, [
            'user' => $user->id,
            'stage' => $stage,
            'method' => $method,
            'at' => now()->getTimestamp(),
        ]);
    }

    public static function user(Session $session, string $stage = self::MFA): ?User
    {
        $pending = $session->get(self::KEY);

        if (! is_array($pending) || ! is_string($pending['user'] ?? null) || ! is_int($pending['at'] ?? null)) {
            return null;
        }

        if (($pending['stage'] ?? null) !== $stage) {
            return null;
        }

        if (now()->getTimestamp() - $pending['at'] > config()->integer('fanfolio.identity.mfa_challenge_minutes') * 60) {
            self::clear($session);

            return null;
        }

        return User::query()->find($pending['user']);
    }

    public static function method(Session $session): string
    {
        $pending = $session->get(self::KEY);

        return is_array($pending) && is_string($pending['method'] ?? null) ? $pending['method'] : 'password';
    }

    public static function clear(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
