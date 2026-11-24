<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Contracts\AccountStats;
use App\Modules\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class SignUpStats implements AccountStats
{
    public function signUps(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->between($from, $to)->count();
    }

    public function ageVerifiedSignUps(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return $this->between($from, $to)->whereNotNull('age_verified_at')->count();
    }

    public function signUpsPerDay(CarbonImmutable $firstDay, int $days): array
    {
        $start = $firstDay->startOfDay();
        $zone = $start->getTimezone()->getName();

        // Timestamps are stored in UTC without a zone; group them by the viewer's calendar day.
        /** @var array<string, int|string> $counts */
        $counts = $this->between($start, $start->addDays($days))
            ->selectRaw("to_char((created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') AS day, count(*) AS n", [$zone])
            ->groupBy('day')
            ->pluck('n', 'day')
            ->all();

        $perDay = [];
        for ($i = 0; $i < $days; $i++) {
            $perDay[] = (int) ($counts[$start->addDays($i)->format('Y-m-d')] ?? 0);
        }

        return $perDay;
    }

    /**
     * @return Builder<User>
     */
    private function between(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return User::query()
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->utc());
    }
}
