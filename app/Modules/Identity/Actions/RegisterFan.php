<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\BreachedPasswordChecker;
use App\Modules\Identity\Events\UserRegistered;
use App\Modules\Identity\Exceptions\IdentityException;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sign up step 1. Input shape and uniqueness are validated by the request; this
 * enforces the password rules (SEC-03) and creates the fan account.
 */
final readonly class RegisterFan
{
    public function __construct(
        private BreachedPasswordChecker $breachedPasswords,
        private AuditLog $audit,
    ) {}

    public function handle(string $email, string $username, string $password): User
    {
        $minimum = config()->integer('fanfolio.identity.password_min_length');

        if (mb_strlen($password) < $minimum) {
            throw new IdentityException("At least {$minimum} characters.", 'password');
        }

        if ($this->breachedPasswords->isBreached($password)) {
            throw new IdentityException('This password has appeared in a data breach. Choose a different one.', 'password');
        }

        return DB::transaction(function () use ($email, $username, $password): User {
            $user = User::query()->create([
                'email' => $email,
                'username' => $username,
                'password' => $password,
            ]);

            $this->audit->record('identity.registered', $user->id, 'user', $user->id);
            UserRegistered::dispatch($user->id);

            return $user;
        });
    }
}
