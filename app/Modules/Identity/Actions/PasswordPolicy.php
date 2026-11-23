<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\BreachedPasswordChecker;
use App\Modules\Identity\Exceptions\IdentityException;

/**
 * SEC-03: the rules every new password meets, at sign-up and at reset.
 */
final readonly class PasswordPolicy
{
    public function __construct(private BreachedPasswordChecker $breachedPasswords) {}

    public function assertAcceptable(string $password, string $field = 'password'): void
    {
        $minimum = config()->integer('fanfolio.identity.password_min_length');

        if (mb_strlen($password) < $minimum) {
            throw new IdentityException("At least {$minimum} characters.", $field);
        }

        if ($this->breachedPasswords->isBreached($password)) {
            throw new IdentityException('This password has appeared in a data breach. Choose a different one.', $field);
        }
    }
}
