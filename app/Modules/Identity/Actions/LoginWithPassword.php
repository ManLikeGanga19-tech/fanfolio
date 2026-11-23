<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Email fallback on the Log in screen. Checks credentials and account status and
 * returns the user; the caller starts the session. A wrong email and a wrong
 * password get the same answer and the same amount of hashing work.
 */
final readonly class LoginWithPassword
{
    private const string FAILED = 'Email or password is not right.';

    /**
     * Argon2id hash of a random value nobody knows, with the default cost settings, so an
     * unknown email costs the same verification work as a known one.
     */
    private const string TIMING_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ZnBORmNuMWd5SjRWSnhReQ$laMjhg4TCHAq6PAFDwM9ndQvD98ON6oJO7U7knuIjJk';

    public function __construct(
        private Hasher $hasher,
        private AuditLog $audit,
    ) {}

    public function handle(string $email, string $password): User
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Same cost as a real check, so response time does not reveal which emails exist.
            $this->hasher->check($password, self::TIMING_HASH);

            throw new IdentityException(self::FAILED, 'email');
        }

        if ($user->password === null) {
            // Passkey-only account: same work and same answer as a wrong password.
            $this->hasher->check($password, self::TIMING_HASH);
            $this->audit->record('identity.login_failed', null, 'user', $user->id, ['reason' => 'no_password']);

            throw new IdentityException(self::FAILED, 'email');
        }

        if (! $this->hasher->check($password, $user->password)) {
            $this->audit->record('identity.login_failed', null, 'user', $user->id, ['reason' => 'password']);

            throw new IdentityException(self::FAILED, 'email');
        }

        if (! $user->status->canSignIn()) {
            $this->audit->record('identity.login_refused', $user->id, 'user', $user->id, ['status' => $user->status->value]);

            throw new IdentityException('This account cannot sign in. Contact support.', 'email');
        }

        if ($this->hasher->needsRehash($user->password)) {
            $user->forceFill(['password' => $password])->save();
        }

        return $user;
    }
}
