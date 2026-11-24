<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An admin banned the account (plan M8a, use case 17). The account is already signed
 * out everywhere and cannot sign in. Creators suspends the creator page; later modules
 * stop subscriptions and sales (rule 9).
 */
final readonly class UserBanned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $userId,
        public string $staffId,
        public string $reason,
    ) {}
}
