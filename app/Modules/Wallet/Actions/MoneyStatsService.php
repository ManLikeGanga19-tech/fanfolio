<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Console\VerifyLedger;
use App\Modules\Wallet\Contracts\Data\LedgerCheck;
use App\Modules\Wallet\Contracts\MoneyStats;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Models\Payout;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function count;
use function is_array;
use function is_string;

/** Sales come from earning_holds (one row per sale); payouts from the payouts table. */
final readonly class MoneyStatsService implements MoneyStats
{
    public function salesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $this->sales($from, $to)->sum('gross_minor');
    }

    public function salesPerDay(CarbonImmutable $firstDay, int $days): array
    {
        $start = $firstDay->startOfDay();

        // Timestamps are stored in UTC without a zone; group them by the viewer's calendar day.
        /** @var array<string, int|string> $sums */
        $sums = $this->sales($start, $start->addDays($days))
            ->selectRaw("to_char((created_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') AS day, sum(gross_minor) AS total", [$start->getTimezone()->getName()])
            ->groupBy('day')
            ->pluck('total', 'day')
            ->all();

        $perDay = [];
        for ($i = 0; $i < $days; $i++) {
            $perDay[] = (int) ($sums[$start->addDays($i)->format('Y-m-d')] ?? 0);
        }

        return $perDay;
    }

    public function payoutsWaiting(): array
    {
        $waiting = Payout::query()->whereIn('status', [PayoutState::Review, PayoutState::Held]);

        return ['count' => (clone $waiting)->count(), 'amount' => (int) $waiting->sum('amount_minor')];
    }

    public function lastLedgerCheck(): ?LedgerCheck
    {
        $result = Cache::get(VerifyLedger::RESULT_KEY);

        if (! is_array($result) || ! is_string($result['checked_at'] ?? null)) {
            return null;
        }

        /** @var array{checked_at: string, ok?: bool, unbalanced?: list<string>, mismatched?: list<string>} $result */
        return new LedgerCheck(
            ($result['ok'] ?? false) === true,
            CarbonImmutable::parse($result['checked_at']),
            count($result['unbalanced'] ?? []) + count($result['mismatched'] ?? []),
        );
    }

    private function sales(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('earning_holds')
            ->whereNull('refunded_at')
            ->where('created_at', '>=', $from->utc())
            ->where('created_at', '<', $to->utc());
    }
}
