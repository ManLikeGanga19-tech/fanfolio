<?php

declare(strict_types=1);

namespace App\Modules\Creators\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A suspended or paused creator page is active again (plan M8a use case 11). Sales may
 * resume; every sale still checks CreatorEligibility.
 */
final readonly class CreatorReinstated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $creatorId, public string $userId) {}
}
