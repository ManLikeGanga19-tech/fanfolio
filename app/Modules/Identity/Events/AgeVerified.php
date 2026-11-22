<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A fan passed the 18+ check; adult creators and posts may now be shown to them.
 */
final readonly class AgeVerified implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $userId) {}
}
