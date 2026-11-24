<?php

declare(strict_types=1);

namespace App\Modules\TrustSafety\Actions;

use App\Modules\Audit\Contracts\AuditLog;
use App\Modules\Identity\Contracts\AccountDirectory;
use App\Modules\Identity\Contracts\Accounts;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Modules\TrustSafety\Contracts\RestrictionReason;
use App\Modules\TrustSafety\Exceptions\TrustSafetyException;
use App\Modules\TrustSafety\Models\Strike;
use App\Modules\TrustSafety\Notifications\AccountDecision;
use Illuminate\Support\Facades\DB;

/**
 * Plan M8a use case 19: give or remove a strike, moderators and admins. Three active
 * strikes flag the account for a ban decision (the "Flagged" filter on the board).
 */
final readonly class Strikes
{
    use DecidesOnAccounts;

    public function __construct(
        private Accounts $accounts,
        private AccountDirectory $directory,
        private AuditLog $audit,
    ) {}

    public function give(string $userId, string $staffId, RestrictionReason $reason, string $note): Strike
    {
        $this->target($userId, $staffId, StaffAbility::GiveStrike);
        $note = self::note($note);

        $strike = new Strike;
        $strike->forceFill([
            'user_id' => $userId,
            'reason' => $reason,
            'given_by' => $staffId,
            'created_at' => now(),
        ])->save();

        $active = self::activeCount($userId);

        $this->audit->record('strike.given', $staffId, 'user', $userId, [
            'strike' => $strike->id,
            'reason' => $reason->value,
            'note' => $note,
            'active' => $active,
        ]);

        $this->accounts->notify($userId, new AccountDecision(AccountDecision::STRIKE, $reason));

        return $strike;
    }

    public function remove(string $strikeId, string $staffId, string $note): void
    {
        $note = self::note($note);

        DB::transaction(function () use ($strikeId, $staffId, $note): void {
            $strike = Strike::query()->lockForUpdate()->find($strikeId);

            if (! $strike instanceof Strike || $strike->removed_at !== null) {
                throw new TrustSafetyException('That strike is already removed.', 'strike');
            }

            $this->target($strike->user_id, $staffId, StaffAbility::GiveStrike);

            $strike->forceFill(['removed_at' => now(), 'removed_by' => $staffId])->save();

            $this->audit->record('strike.removed', $staffId, 'user', $strike->user_id, [
                'strike' => $strike->id,
                'note' => $note,
            ]);
        });
    }

    public static function activeCount(string $userId): int
    {
        return Strike::query()->active()->where('user_id', $userId)->count();
    }

    /**
     * Accounts with at least Strike::FLAG_AT active strikes.
     *
     * @return list<string>
     */
    public static function flaggedUserIds(): array
    {
        /** @var list<string> $ids */
        $ids = Strike::query()->active()
            ->groupBy('user_id')
            ->havingRaw('count(*) >= ?', [Strike::FLAG_AT])
            ->pluck('user_id')
            ->all();

        return $ids;
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, int>
     */
    public static function activeCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var array<string, int|string> $counts */
        $counts = Strike::query()->active()
            ->whereIn('user_id', $userIds)
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) AS n')
            ->pluck('n', 'user_id')
            ->all();

        return array_map(intval(...), $counts);
    }
}
