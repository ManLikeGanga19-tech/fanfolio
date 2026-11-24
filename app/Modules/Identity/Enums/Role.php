<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

use App\Modules\Identity\Contracts\StaffAbility;

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

    /** The role matrix in docs/plans/M8a.md: staff only; admin-only abilities for admins. */
    public function allows(StaffAbility $ability): bool
    {
        return $this->isStaff() && (! $ability->adminOnly() || $this === self::Admin);
    }
}
