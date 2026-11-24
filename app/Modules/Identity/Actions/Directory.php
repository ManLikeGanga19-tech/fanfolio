<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Data\AccountSummary;
use App\Modules\Identity\Enums\AccountStatus;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserDevice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

final readonly class Directory implements AccountDirectory
{
    public function find(string $userId): ?AccountSummary
    {
        $user = $this->query()->whereKey($userId)->first();

        return $user instanceof User ? self::summary($user) : null;
    }

    public function search(string $term, string $filter, ?array $onlyIds, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->query()->latest('created_at')->latest('id');
        $term = trim($term);

        if ($term !== '') {
            $handle = ltrim($term, '@');
            $query->where(static function (Builder $q) use ($term, $handle): void {
                $q->where('username', 'ilike', '%'.addcslashes($handle, '%_\\').'%')
                    ->orWhereRaw('lower(email) = ?', [mb_strtolower($term)])
                    ->orWhere('id', $term);
            });
        }

        match ($filter) {
            'creator' => $query->where('role', Role::Creator),
            'fan' => $query->where('role', Role::Fan),
            'suspended' => $query->where('status', AccountStatus::Suspended)->where('suspended_until', '>', now()),
            'banned' => $query->where('status', AccountStatus::Banned),
            default => null,
        };

        if ($onlyIds !== null) {
            $query->whereIn('id', $onlyIds === [] ? [''] : $onlyIds);
        }

        /** @var Paginator<int, User> $users */
        $users = $query->paginate(perPage: $perPage, page: max(1, $page));

        return $users->through(static fn (User $user): AccountSummary => self::summary($user));
    }

    public function counts(CarbonImmutable $bannedSince): array
    {
        return [
            'fan' => User::query()->where('role', Role::Fan)->count(),
            'creator' => User::query()->where('role', Role::Creator)->count(),
            'banned_since' => User::query()->where('status', AccountStatus::Banned)->where('restricted_at', '>=', $bannedSince->utc())->count(),
        ];
    }

    /**
     * @return Builder<User>
     */
    private function query(): Builder
    {
        return User::query()->select('users.*')->addSelect([
            'active_devices' => UserDevice::query()
                ->selectRaw('count(*)')
                ->whereColumn('user_devices.user_id', 'users.id')
                ->whereNull('revoked_at'),
        ]);
    }

    private static function summary(User $user): AccountSummary
    {
        $status = match (true) {
            $user->status === AccountStatus::Suspended && $user->canSignIn() => AccountSummary::ACTIVE,
            default => $user->status->value,
        };

        $devices = $user->getAttribute('active_devices');

        return new AccountSummary(
            id: $user->id,
            username: $user->username,
            role: $user->role->value,
            isStaff: $user->role->isStaff(),
            ageVerified: $user->age_verified_at !== null,
            joinedAt: $user->created_at,
            status: $status,
            suspendedUntil: $status === AccountSummary::SUSPENDED ? $user->suspended_until : null,
            restrictedAt: $user->restricted_at,
            activeDevices: is_numeric($devices) ? (int) $devices : 0,
            deviceLimit: Devices::limit(),
        );
    }
}
