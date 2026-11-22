<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Holds the account between the password and the second factor. Nothing is signed
 * in until the second factor passes; the hold expires after a few minutes.
 */
final class PendingLogin
{
    private const string KEY = 'identity.mfa_pending';

    public static function start(Session $session, User $user): void
    {
        $session->put(self::KEY, ['user' => $user->id, 'at' => now()->getTimestamp()]);
    }

    public static function user(Session $session): ?User
    {
        $pending = $session->get(self::KEY);

        if (! is_array($pending) || ! is_string($pending['user'] ?? null) || ! is_int($pending['at'] ?? null)) {
            return null;
        }

        if (now()->getTimestamp() - $pending['at'] > config()->integer('fanfolio.identity.mfa_challenge_minutes') * 60) {
            self::clear($session);

            return null;
        }

        return User::query()->find($pending['user']);
    }

    public static function clear(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
