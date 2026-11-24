<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Creators\Events\CreatorApproved;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Staff approved a creator: the account gets the creator role, which opens Studio.
 * Idempotent: a replayed event, or an account that is already a creator or staff,
 * changes nothing.
 */
final readonly class GrantCreatorRole
{
    public function __construct(private AuditLog $audit) {}

    public function handle(CreatorApproved $event): void
    {
        DB::transaction(function () use ($event): void {
            $user = User::query()->whereKey($event->userId)->lockForUpdate()->first();

            if (! $user instanceof User || $user->role !== Role::Fan) {
                return;
            }

            $user->forceFill(['role' => Role::Creator])->save();

            $this->audit->record('identity.role_changed', null, 'user', $user->id, [
                'from' => Role::Fan->value,
                'to' => Role::Creator->value,
                'creator' => $event->creatorId,
            ]);
        });
    }
}
