<?php

declare(strict_types=1);

namespace App\Modules\Identity\Gateways\Fake;

use App\Modules\Identity\Contracts\BreachedPasswordChecker;
use App\Support\Gateways\FakeDriver;

/**
 * Treats a short list of well-known breached passwords, and anything containing
 * "breached", as breached. Local and test environments only.
 */
final class FakeBreachedPasswordChecker implements BreachedPasswordChecker, FakeDriver
{
    private const array KNOWN = [
        'password1234',
        '123456789012',
        'qwertyuiop12',
        'iloveyou1234',
    ];

    public function isBreached(string $password): bool
    {
        return in_array(strtolower($password), self::KNOWN, true) || str_contains(strtolower($password), 'breached');
    }
}
