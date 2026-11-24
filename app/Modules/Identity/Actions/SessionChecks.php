<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\SessionSecurity;
use App\Modules\Identity\Models\UserDevice;
use Illuminate\Contracts\Session\Session;

final readonly class SessionChecks implements SessionSecurity
{
    public function hasFreshSecondFactor(Session $session): bool
    {
        return RequireStepUp::isFreshSecondFactor($session);
    }

    public function isOnNewDevice(Session $session, string $userId, int $days = 7): bool
    {
        $deviceId = $session->get(Devices::SESSION_KEY);

        if (! is_string($deviceId)) {
            // No registered device on this session: treat it as new (the safe answer).
            return true;
        }

        $device = UserDevice::query()->whereKey($deviceId)->where('user_id', $userId)->first();

        return ! $device instanceof UserDevice || $device->created_at === null || $device->created_at->gt(now()->subDays($days));
    }
}
