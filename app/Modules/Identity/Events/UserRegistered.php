<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class UserRegistered implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public string $userId) {}
}
