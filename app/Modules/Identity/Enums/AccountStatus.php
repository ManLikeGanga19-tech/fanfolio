<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Suspended = 'suspended';
    case Banned = 'banned';

    public function canSignIn(): bool
    {
        return $this === self::Active || $this === self::Paused;
    }
}
