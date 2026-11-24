<?php

declare(strict_types=1);

namespace App\Modules\Creators\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The application was rejected: under 18 or suspected fraud. Trust & Safety opens a
 * case from this (M8). The reason is a code such as "under_18", never personal data.
 */
final readonly class CreatorKycFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $creatorId, public string $userId, public string $reason) {}
}
