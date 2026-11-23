<?php

declare(strict_types=1);

namespace App\Modules\Creators\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A fan started the "Become a creator" flow. */
final readonly class CreatorApplied implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $creatorId, public string $userId) {}
}
