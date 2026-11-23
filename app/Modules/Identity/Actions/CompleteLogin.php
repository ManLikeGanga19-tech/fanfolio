<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Models\User;

/**
 * Records a successful sign-in once every factor has passed.
 */
final readonly class CompleteLogin
{
    public function __construct(private AuditLog $audit) {}

    public function handle(User $user, string $method): void
    {
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('identity.login', $user->id, 'user', $user->id, ['method' => $method]);
    }
}
