<?php

declare(strict_types=1);

namespace App\Filament\Admin;

use App\Modules\Creators\Contracts\ApprovalQueue;
use App\Modules\Creators\Filament\Admin\CreatorApprovals;
use App\Modules\Identity\Contracts\AccountStats;
use App\Modules\Identity\Contracts\StaffAbility;
use App\Support\Health\HealthCheck;
use App\Support\Health\SystemHealth;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * Canvas board AdminOverview (plan M8a, use cases 1 and 2). Figures come from each module
 * through its contracts. The money tiles and the sales chart on the board (sales, payouts
 * to review, chargeback rate) arrive with M3; until then the chart shows sign-ups.
 * Days and dates are the viewer's own (D-15).
 */
final class Overview extends Page
{
    public const array RANGES = ['today' => 'Today', '7d' => '7 days', '30d' => '30 days'];

    private const array PREVIOUS = ['today' => 'yesterday', '7d' => 'the 7 days before', '30d' => 'the 30 days before'];

    /** Days in the sign-ups chart. */
    public const int CHART_DAYS = 14;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Overview';

    protected static ?string $slug = 'overview';

    protected string $view = 'filament.admin.overview';

    #[Url]
    public string $range = 'today';

    /** @var list<HealthCheck>|null checked once per request */
    private ?array $healthChecks = null;

    /** SEC-15: moderators and admins. System health is for admins only. */
    public static function canAccess(): bool
    {
        return Gate::allows(StaffAbility::ViewOverview->value);
    }

    /** The panel's home page, like Filament's Dashboard. */
    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function setRange(string $range): void
    {
        $this->range = array_key_exists($range, self::RANGES) ? $range : 'today';
    }

    public function getSubheading(): string
    {
        $line = $this->now()->format('l j M');
        $health = $this->health();

        if ($health === null) {
            return $line;
        }

        $warnings = count(array_filter($health, static fn (HealthCheck $c): bool => $c->level !== HealthCheck::OK));

        return $line.' · '.match ($warnings) {
            0 => 'all systems normal',
            1 => '1 warning',
            default => $warnings.' warnings',
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $range = array_key_exists($this->range, self::RANGES) ? $this->range : 'today';
        $now = $this->now();
        $days = ['today' => 1, '7d' => 7, '30d' => 30][$range];
        $from = $now->startOfDay()->subDays($days - 1);
        // The period runs to the end of today; the one before stops at the same time of day.
        $to = $now->startOfDay()->addDay();

        $accounts = app(AccountStats::class);
        $approvals = app(ApprovalQueue::class);

        $signUps = $accounts->signUps($from, $to);
        $previous = $accounts->signUps($from->subDays($days), $now->subDays($days));
        $verified = $accounts->ageVerifiedSignUps($from, $to);
        $oldest = $approvals->oldestWaitingSince();

        $chartStart = $now->startOfDay()->subDays(self::CHART_DAYS - 1);
        $perDay = $accounts->signUpsPerDay($chartStart, self::CHART_DAYS);

        return [
            'ranges' => self::RANGES,
            'active' => $range,
            'signUps' => $signUps,
            'signUpsChange' => self::change($signUps, $previous, self::PREVIOUS[$range]),
            'verifiedShare' => $signUps === 0 ? null : intdiv($verified * 100, $signUps),
            'verified' => $verified,
            'waiting' => $approvals->waiting(),
            'oldestWaiting' => $oldest?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE, short: true),
            'oldestIsLate' => $oldest !== null && $oldest->lt(CarbonImmutable::now()->subDay()),
            'approved' => $approvals->approvedBetween($from, $to),
            'approvalsUrl' => CreatorApprovals::getUrl(),
            'chart' => self::chart($perDay, $chartStart),
            'health' => $this->health(),
        ];
    }

    /**
     * "+12% vs yesterday", with whole percentages (no floats, CLAUDE.md conventions).
     *
     * @return array{text: string, tone: 'up'|'down'|'flat'}
     */
    public static function change(int $current, int $previous, string $against): array
    {
        if ($previous === 0) {
            return $current === 0
                ? ['text' => 'None '.$against.' either', 'tone' => 'flat']
                : ['text' => 'None '.$against, 'tone' => 'up'];
        }

        $percent = intdiv(($current - $previous) * 100, $previous);

        return [
            'text' => ($percent > 0 ? '+' : '').$percent.'% vs '.$against,
            'tone' => $percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat'),
        ];
    }

    /**
     * Bar heights in a 0–100 scale for the SVG, plus the text alternative.
     *
     * @param  list<int>  $perDay
     * @return array{bars: list<array{day: string, count: int, height: int}>, total: int, label: string, ticks: list<string>}
     */
    public static function chart(array $perDay, CarbonImmutable $start): array
    {
        $max = max([1, ...$perDay]);
        $total = array_sum($perDay);
        $bars = [];

        foreach ($perDay as $i => $count) {
            $bars[] = [
                'day' => $start->addDays($i)->format('j M'),
                'count' => $count,
                'height' => $count === 0 ? 0 : max(2, intdiv($count * 100, $max)),
            ];
        }

        $first = $bars[0] ?? null;
        $last = $bars[count($bars) - 1] ?? null;

        return [
            'bars' => $bars,
            'total' => $total,
            'label' => $first === null || $last === null
                ? 'No data'
                : sprintf('Daily sign-ups from %s to %s: %d in total, %d on %s, %d on %s, highest %d in a day.', $first['day'], $last['day'], $total, $first['count'], $first['day'], $last['count'], $last['day'], $max),
            'ticks' => $first === null || $last === null ? [] : [$first['day'], $bars[intdiv(count($bars), 2)]['day'], $last['day']],
        ];
    }

    /**
     * @return list<HealthCheck>|null null for staff who may not see it
     */
    private function health(): ?array
    {
        if (! Gate::allows(StaffAbility::ViewSystemHealth->value)) {
            return null;
        }

        return $this->healthChecks ??= app(SystemHealth::class)->checks();
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now(FilamentTimezone::get());
    }
}
