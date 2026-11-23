<?php

declare(strict_types=1);

namespace App\Modules\Creators\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff suspended the creator. Publishing and earning stop at once (CreatorEligibility
 * reads the database every time); listeners stop sales of their posts (rule 9).
 */
final readonly class CreatorSuspended implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $creatorId, public string $userId, public string $reason) {}
}
