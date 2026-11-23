<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

enum Role: string
{
    case Fan = 'fan';
    case Creator = 'creator';
    case Moderator = 'moderator';
    case Admin = 'admin';

    /** SEC-04: MFA is mandatory for every role except fan. */
    public function requiresMfa(): bool
    {
        return $this !== self::Fan;
    }

    public function isStaff(): bool
    {
        return $this === self::Moderator || $this === self::Admin;
    }
}
