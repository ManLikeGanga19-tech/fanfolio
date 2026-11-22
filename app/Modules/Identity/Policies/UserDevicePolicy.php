<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;

/**
 * SEC-06: a device can be seen and signed out only by its owner.
 */
final class UserDevicePolicy
{
    public function revoke(User $user, UserDevice $device): bool
    {
        return $device->user_id === $user->id;
    }
}
