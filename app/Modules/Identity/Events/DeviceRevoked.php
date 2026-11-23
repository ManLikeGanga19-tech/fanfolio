<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A device was signed out. Playback sessions bound to it (M4) must stop.
 */
final readonly class DeviceRevoked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $deviceId,
    ) {}
}
