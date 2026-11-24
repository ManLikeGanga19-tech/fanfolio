<?php

declare(strict_types=1);

namespace App\Modules\Creators\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The provider matched the creator's ID and selfie, and they are 18 or older. */
final readonly class CreatorKycPassed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $creatorId, public string $userId) {}
}
