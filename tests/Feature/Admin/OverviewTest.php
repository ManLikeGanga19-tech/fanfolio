<?php

declare(strict_types=1);

use App\Filament\Admin\Overview;
use App\Modules\Creators\Enums\CreatorStatus;
use App\Modules\Creators\Enums\KycStatus;
use App\Modules\Creators\Filament\Admin\CreatorApprovals;
use App\Modules\Creators\Models\Creator;
use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Wallet\Contracts\Data\LedgerCheck;
use App\Modules\Wallet\Contracts\MoneyStats;
use App\Support\Health\SystemHealth;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

// Canvas board AdminOverview, plan M8a use cases 1 and 2.

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00', 'UTC'));
    Filament::setCurrentPanel('admin');
});

it('is the admin home for moderators and admins', function (string $role): void {
    signedInStaff($role);

    $this->get('/admin')->assertOk()->assertSee('Overview')->assertSee('Creator approvals')
        // The avatar is drawn locally; no username goes to an outside avatar service.
        ->assertSee('data:image/svg+xml;base64,', false)
        ->assertDontSee('ui-avatars.com');
})->with(['moderator', 'admin']);

it('keeps fans out', function (): void {
    signedInFan();

    $this->get('/admin')->assertForbidden();
});

it('shows today\'s figures from Identity and Creators', function (): void {
    signedInStaff();

    User::factory()->count(3)->create(['created_at' => now()->subHour()]);
    User::factory()->create(['created_at' => now()->subHour(), 'age_verified_at' => now()]);
    User::factory()->create(['created_at' => now()->subDays(1)]);
    Creator::factory()->withStatus(CreatorStatus::Submitted, KycStatus::Approved)->create(['submitted_at' => now()->subHours(6)]);
    Creator::factory()->withStatus(CreatorStatus::Submitted, KycStatus::Approved)->create(['submitted_at' => now()->subHour()]);
    Creator::factory()->approved()->create();

    // Four today, plus the staff account made for the test.
    Livewire::test(Overview::class)
        ->assertViewHas('signUps', 5)
        ->assertViewHas('verified', 1)
        ->assertViewHas('waiting', 2)
        ->assertViewHas('oldestWaiting', '6h')
        ->assertViewHas('approved', 1)
        ->assertSee('Creators awaiting review')
        ->assertSee('Oldest 6h');
});

it('switches between today, 7 days and 30 days', function (): void {
    signedInStaff();
    User::factory()->create(['created_at' => now()->subDays(3)]);
    User::factory()->create(['created_at' => now()->subDays(20)]);

    $page = Livewire::test(Overview::class);
    $today = $page->viewData('signUps');

    $page->call('setRange', '7d')->assertViewHas('signUps', $today + 1)
        ->call('setRange', '30d')->assertViewHas('signUps', $today + 2)
        ->call('setRange', 'forever')->assertSet('range', 'today');
});

it('uses the viewer\'s own day (D-15)', function (): void {
    $staff = signedInStaff();
    $staff->forceFill(['timezone' => 'Pacific/Kiritimati', 'created_at' => now()->subDays(5)])->save(); // UTC+14
    User::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-24 09:30', 'UTC')]);

    // 10:00 UTC is already 25 Nov, 00:00 in Kiritimati: 09:30 UTC was yesterday there.
    $this->get('/admin')->assertSee('Wednesday 25 Nov');
    Livewire::test(Overview::class)->assertViewHas('signUps', 0);
});

it('shows system health to admins only', function (): void {
    SystemHealth::beat(Cache::store());

    signedInStaff('admin');
    $this->get('/admin')->assertSee('System health')->assertSee('Scheduler')->assertSee('Last ran');

    auth()->logout();
    signedInStaff('moderator');
    $this->get('/admin')->assertOk()->assertDontSee('System health');
});

it('describes the change against the previous period in whole percentages', function (int $now, int $before, string $text): void {
    expect(Overview::change($now, $before, 'yesterday')['text'])->toBe($text);
})->with([
    [12, 10, '+20% vs yesterday'],
    [5, 10, '-50% vs yesterday'],
    [10, 10, '0% vs yesterday'],
    [3, 0, 'None yesterday'],
    [0, 0, 'None yesterday either'],
]);

it('gives the chart a text alternative', function (): void {
    $chart = Overview::chart([0, 2, 4], CarbonImmutable::parse('2026-11-22'));

    expect($chart['bars'][2]['height'])->toBe(100)
        ->and($chart['bars'][1]['height'])->toBe(50)
        ->and($chart['label'])->toBe('Daily sign-ups from 22 Nov to 24 Nov: 6 in total, 0 on 22 Nov, 4 on 24 Nov, highest 4 in a day.');
});

it('counts waiting applications in the sidebar', function (): void {
    signedInStaff(Role::Moderator->value);
    Creator::factory()->withStatus(CreatorStatus::Submitted, KycStatus::Approved)->count(3)->create(['submitted_at' => now()]);

    $this->get('/admin')->assertSee('Creator approvals');
    expect(CreatorApprovals::getNavigationBadge())->toBe('3');
});

/** Stands in for Wallet's MoneyStats with fixed figures. */
function fixedMoneyStats(?LedgerCheck $check = null): void
{
    app()->instance(MoneyStats::class, new readonly class($check) implements MoneyStats
    {
        public function __construct(private ?LedgerCheck $check) {}

        public function salesBetween(CarbonImmutable $from, CarbonImmutable $to): int
        {
            // Today 1,842,000 cents; the same time yesterday 1,690,000.
            return $to->isFuture() ? 1_842_000 : 1_690_000;
        }

        public function salesPerDay(CarbonImmutable $firstDay, int $days): array
        {
            return [...array_fill(0, $days - 1, 0), 1_842_050];
        }

        public function payoutsWaiting(): array
        {
            return ['count' => 3, 'amount' => 984_000];
        }

        public function chargebackRate(CarbonImmutable $since): array
        {
            // Canvas "0.42%" over the last 30 days.
            return ['bps' => $since->diffInDays(CarbonImmutable::now()) > 29 ? 42 : 0, 'chargebacks' => 21, 'topups' => 5_000];
        }

        public function lastLedgerCheck(): ?LedgerCheck
        {
            return $this->check;
        }
    });
}

it('shows admins the money tiles and the sales chart (use case 3)', function (): void {
    fixedMoneyStats(new LedgerCheck(true, CarbonImmutable::parse('2026-11-24 02:00', 'UTC'), 0));
    signedInStaff('admin');

    Livewire::test(Overview::class)
        ->assertViewHas('sales', 1_842_000)
        ->assertViewHas('payouts', ['count' => 3, 'amount' => 984_000])
        ->assertSeeInOrder(['Sales (GMV)', '$18,420', '+8% vs yesterday by this time', 'Creators awaiting review', 'Open cases', 'Payouts to review', '$9,840', '3 requests', 'Chargeback rate (30d)', '0.42%', 'Below 0.75% target', 'Sign-ups'])
        ->assertDontSee('Passed the 18+ check')
        ->assertSee('Sales, last 14 days (USD)')
        ->assertViewHas('chart', fn (array $chart): bool => str_starts_with($chart['label'], 'Daily sales (USD) from 11 Nov to 24 Nov: $18,420.50 in total'))
        ->assertSee(['Ledger check passed', '02:00 · 0 mismatches']);
});

it('keeps money off the Overview for moderators', function (): void {
    fixedMoneyStats();
    signedInStaff('moderator');

    Livewire::test(Overview::class)
        ->assertViewHas('sales', fn (?int $sales): bool => $sales === null)
        ->assertViewHas('payouts', fn (?array $payouts): bool => $payouts === null)
        ->assertDontSee('Sales (GMV)')
        ->assertDontSee('Payouts to review')
        ->assertDontSee('Chargeback rate')
        ->assertSee(['Sign-ups', 'Passed the 18+ check', 'Creators approved', 'Sign-ups, last 14 days']);
});

it('reports the ledger check under system health', function (?LedgerCheck $check, string $title, string $detail): void {
    fixedMoneyStats($check);
    signedInStaff('admin');

    Livewire::test(Overview::class)->assertSee($title)->assertSee($detail);
})->with([
    'not run' => [null, 'Ledger check', 'Not run yet (php artisan wallet:verify-ledger, nightly)'],
    'passed today' => [new LedgerCheck(true, CarbonImmutable::parse('2026-11-24 02:00', 'UTC'), 0), 'Ledger check passed', '02:00 · 0 mismatches'],
    'failed' => [new LedgerCheck(false, CarbonImmutable::parse('2026-11-23 02:00', 'UTC'), 1), 'Ledger check failed', '23 Nov, 02:00 · 1 mismatch (see the critical log)'],
    'failed twice' => [new LedgerCheck(false, CarbonImmutable::parse('2026-11-24 02:00', 'UTC'), 2), 'Ledger check failed', '2 mismatches'],
]);
