<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\Identity\Models\User;
use Illuminate\Notifications\Notification;

final readonly class AccountLookup implements Accounts
{
    public function hasMfa(string $userId): bool
    {
        return User::query()->whereKey($userId)->where('mfa_enabled', true)->exists();
    }

    public function isStaff(string $userId): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User && $user->role->isStaff() && $user->mfa_enabled && $user->status->canSignIn();
    }

    public function can(string $userId, StaffAbility $ability): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User && $user->canStaff($ability);
    }

    public function username(string $userId): ?string
    {
        $username = User::query()->whereKey($userId)->value('username');

        return is_string($username) ? $username : null;
    }

    public function notify(string $userId, Notification $notification): void
    {
        User::query()->find($userId)?->notify($notification);
    }
}
