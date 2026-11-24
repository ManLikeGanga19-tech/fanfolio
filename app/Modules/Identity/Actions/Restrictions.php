<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\AccountRestrictions;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Account status changes for Trust & Safety. The caller audits the decision; signing
 * devices out is audited here, per device, as for any sign-out.
 */
final readonly class Restrictions implements AccountRestrictions
{
    public function __construct(private Devices $devices) {}

    public function suspend(string $userId, CarbonImmutable $until): void
    {
        $this->change($userId, [
            'status' => AccountStatus::Suspended,
            'suspended_until' => $until->utc(),
            'restricted_at' => now(),
        ], signOut: true);
    }

    public function ban(string $userId): void
    {
        $this->change($userId, [
            'status' => AccountStatus::Banned,
            'suspended_until' => null,
            'restricted_at' => now(),
        ], signOut: true);
    }

    public function lift(string $userId): void
    {
        $this->change($userId, [
            'status' => AccountStatus::Active,
            'suspended_until' => null,
        ], signOut: false);
    }

    public function signOutEverywhere(string $userId): int
    {
        $user = User::query()->find($userId);

        return $user instanceof User ? $this->devices->revokeAll($user) : 0;
    }

    public function isRestricted(string $userId): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User && ! $user->canSignIn();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function change(string $userId, array $attributes, bool $signOut): void
    {
        DB::transaction(function () use ($userId, $attributes, $signOut): void {
            $user = User::query()->lockForUpdate()->find($userId);

            if (! $user instanceof User) {
                return;
            }

            $user->forceFill($attributes)->save();

            if ($signOut) {
                $this->devices->revokeAll($user);
            }
        });
    }
}
