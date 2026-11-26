<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\MoneyStatsService;
use App\Modules\Wallet\Console\VerifyLedger;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerCheck;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Filament\Admin\PayoutReviews;
use App\Modules\Wallet\Jobs\SendPayoutJob;
use App\Modules\Wallet\Models\Payout;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// M3 PR 5: canvas board AdminPayouts (plan M8a use case 36) and the money figures the
// admin Overview reads through MoneyStats (use case 3).

afterEach(fn () => expectLedgerBalanced());

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
    Filament::setCurrentPanel('admin');
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
    Queue::fake();
});

/**
 * A withdrawal as RequestPayout leaves it: the money moved from the creator's earnings
 * into payout_clearing, the row waiting for review (or in another state).
 *
 * @param  list<string>  $flags
 * @param  array<string, mixed>  $extra
 */
function reviewPayout(string $creator, int $cents, array $flags = [Payout::OVER_REVIEW_LIMIT], PayoutState $status = PayoutState::Review, array $extra = []): Payout
{
    $id = (string) Str::ulid();
    $ledger = app(Ledger::class);
    $ledger->post(LedgerTransaction::make('test.earnings', 'earn:'.$id, [
        Posting::debit(AccountRef::platform(AccountType::PlatformRevenue), Money::of($cents, 'USD')),
        Posting::credit(AccountRef::of(AccountType::CreatorEarnings, $creator), Money::of($cents, 'USD')),
    ]));

    if ($status->inProgress()) {
        $ledger->post(LedgerTransaction::make('payout.requested', 'payout:'.$id, [
            Posting::debit(AccountRef::of(AccountType::CreatorEarnings, $creator), Money::of($cents, 'USD')),
            Posting::credit(AccountRef::of(AccountType::PayoutClearing, $creator), Money::of($cents, 'USD')),
        ]));
    }

    $payout = new Payout;
    $payout->forceFill([
        'id' => $id,
        'creator_id' => $creator,
        'idempotency_key' => 'k'.$id,
        'payout_method_id' => (string) Str::ulid(),
        'rail' => PayoutRail::Bank,
        'display_hint' => 'Test Bank ····2222',
        'amount_minor' => $cents,
        'currency' => 'USD',
        'status' => $status,
        'flags' => $flags,
        'requested_at' => now(),
        ...$extra,
    ])->save();

    return $payout;
}

/** One sale row as Purchases::charge records it (the figures only read earning_holds). */
function recordSale(int $cents, CarbonImmutable $at, bool $refunded = false): void
{
    DB::table('earning_holds')->insert([
        'id' => (string) Str::ulid(),
        'transaction_id' => (string) Str::ulid(),
        'fan_id' => (string) Str::ulid(),
        'creator_id' => (string) Str::ulid(),
        'kind' => 'unlock',
        'gross_minor' => $cents,
        'creator_minor' => intdiv($cents * 80, 100),
        'fee_minor' => $cents - intdiv($cents * 80, 100),
        'currency' => 'USD',
        'release_at' => $at->addDays(7),
        'refunded_at' => $refunded ? $at : null,
        'created_at' => $at,
    ]);
}

it('is for admins only', function (): void {
    $admin = signedInStaff('admin');
    signedInStaff('moderator');
    $this->get('/admin/payouts')->assertForbidden();

    actAsUserId($admin->id);
    $this->get('/admin/payouts')->assertOk()->assertSee('Payout reviews')
        ->assertSee('Requests of $1,000 or less with no risk flags are paid automatically.');
});

it('lists waiting and held payouts oldest first with their flags, and counts them in the sidebar', function (): void {
    signedInStaff();
    $first = reviewPayout(anAccount(), 420_000);
    $this->travel(5)->minutes();
    $second = reviewPayout(anAccount(), 170_000, [Payout::NEW_DEVICE, Payout::NAME_MISMATCH], PayoutState::Held);
    reviewPayout(anAccount(), 5_000, [], PayoutState::Sent, ['sent_at' => now()]);

    $page = Livewire::test(PayoutReviews::class)
        ->assertSee(['$4,200', 'Over $1,000', '$1,700', 'New device', 'Payout name does not match ID', 'On hold'])
        ->assertViewHas('detail', fn (Payout $p): bool => $p->id === $first->id)
        ->assertSet('selected', $first->id);

    expect($page->viewData('queue')->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and(PayoutReviews::getNavigationBadge())->toBe('2')
        ->and(PayoutReviews::getNavigationBadgeTooltip())->toBe('Waiting for review');
});

it('shows the canvas tiles: waiting, auto-paid today, failed in 7 days, clearing reconciled', function (): void {
    signedInStaff();
    $creator = anAccount();
    $reviewer = anAccount();
    reviewPayout($creator, 420_000);
    reviewPayout($creator, 30_000, [], PayoutState::Sending);
    reviewPayout($creator, 12_050, [], PayoutState::Sent, ['sent_at' => now()]);
    reviewPayout($creator, 20_000, [], PayoutState::Sent, ['sent_at' => now()->subDay()]);
    reviewPayout($creator, 150_000, [], PayoutState::Sent, ['sent_at' => now(), 'reviewed_by' => $reviewer]);
    reviewPayout($creator, 9_000, [], PayoutState::Failed, ['failed_at' => now()->subDays(2)]);
    reviewPayout($creator, 9_000, [], PayoutState::Failed, ['failed_at' => now()->subDays(9)]);

    Livewire::test(PayoutReviews::class)
        ->assertViewHas('tiles', [
            'waiting' => ['count' => 1, 'amount' => 420_000],
            'autoPaid' => ['count' => 1, 'amount' => 12_050],
            'failed' => 1,
            'clearing' => ['reconciled' => true, 'held' => 450_000, 'difference' => 0, 'inProgress' => 2],
        ])
        ->assertSee(['$120.50', 'Reconciled', '$4,500 held for 2 in progress', 'Provider refused · returned to earnings']);
});

it('says when payout clearing does not match the payouts in progress', function (): void {
    signedInStaff();
    $creator = anAccount();
    reviewPayout($creator, 10_000);
    // A payout row whose money never reached clearing.
    Payout::query()->whereKey(reviewPayout($creator, 2_500, [], PayoutState::Sent)->id)->update(['status' => PayoutState::Review]);

    Livewire::test(PayoutReviews::class)
        ->assertViewHas('tiles', fn (array $t): bool => $t['clearing'] === ['reconciled' => false, 'held' => 10_000, 'difference' => -2_500, 'inProgress' => 2])
        ->assertSee('Out by $25')
        ->assertDontSee('Reconciled');
});

it('lists the checks behind a request', function (): void {
    signedInStaff();
    $creator = anAccount();
    reviewPayout($creator, 3_000, [], PayoutState::Sent);
    reviewPayout($creator, 4_000, [], PayoutState::Sent);
    $clean = reviewPayout($creator, 150_000);

    Livewire::test(PayoutReviews::class)
        ->assertViewHas('checks', [
            ['text' => 'Reserved from available earnings; $70 left after this', 'tone' => 'ok'],
            ['text' => 'Paid only from sales past the 7-day hold', 'tone' => 'ok'],
            ['text' => 'No sales in the last 90 days', 'tone' => 'muted'],
            ['text' => 'Payout method in use for more than 7 days', 'tone' => 'ok'],
            ['text' => 'Requested from a device the creator has used before', 'tone' => 'ok'],
            ['text' => 'Payout name matches the ID', 'tone' => 'ok'],
            ['text' => '2 earlier payouts sent', 'tone' => 'ok'],
            ['text' => 'Confirmed by the creator with a second factor', 'tone' => 'ok'],
        ])
        ->assertSee('To Test Bank ····2222');

    $flagged = reviewPayout(anAccount(), 90_000, [Payout::NEW_METHOD, Payout::NEW_DEVICE, Payout::NAME_MISMATCH]);
    // A refund since the request took the creator's earnings below zero (D-20).
    app(Ledger::class)->post(LedgerTransaction::make('test.refund', 'refund:'.$flagged->id, [
        Posting::debit(AccountRef::of(AccountType::CreatorEarnings, $flagged->creator_id), Money::of(1_250, 'USD')),
        Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), Money::of(1_250, 'USD')),
    ])->allowingNegativeBalances());

    Livewire::test(PayoutReviews::class)->call('select', $flagged->id)
        ->assertViewHas('checks', fn (array $checks): bool => array_column($checks, 'tone') === ['danger', 'ok', 'muted', 'warning', 'warning', 'danger', 'muted', 'ok']
            && $checks[0]['text'] === 'Earnings are -$12.50 after refunds since the request'
            && $checks[6]['text'] === 'First payout for this creator')
        ->assertSee(['New payout method', 'Payout method added in the last 7 days', 'Requested from a new device', 'Payout name does not match the ID']);

    expect($clean->creator_id)->not->toBe($flagged->creator_id)
        ->and(PayoutReviews::flags($flagged))->toBe([
            ['text' => 'New payout method', 'tone' => 'warning'],
            ['text' => 'New device', 'tone' => 'warning'],
            ['text' => 'Payout name does not match ID', 'tone' => 'danger'],
        ]);
});

it('approves and sends, audited', function (): void {
    $admin = signedInStaff();
    $payout = reviewPayout(anAccount(), 420_000);

    Livewire::test(PayoutReviews::class)
        ->callAction('approve', data: ['note' => 'Checked the bank letter'])
        ->assertHasNoActionErrors()
        ->assertNotified('Approved. Sending now.')
        ->assertDispatched('refresh-sidebar');

    expect($payout->refresh()->status)->toBe(PayoutState::Approved)
        ->and($payout->reviewed_by)->toBe($admin->id)
        ->and($payout->review_note)->toBe('Checked the bank letter')
        ->and($this->audit->has('wallet.payout_approved', $payout->id))->toBeTrue();
    Queue::assertPushed(SendPayoutJob::class);

    Livewire::test(PayoutReviews::class)->assertSee('Nothing waiting')->assertSee('Choose a payout to see its checks.');
});

it('holds with a note, then approves or declines the held payout', function (): void {
    signedInStaff();
    $payout = reviewPayout(anAccount(), 420_000);

    // A hold needs a note: the dialog refuses before anything changes.
    Livewire::test(PayoutReviews::class)
        ->callAction('hold', data: ['note' => ''])
        ->assertHasActionErrors(['note' => 'required'])
        ->assertNotNotified('On hold. The creator has been asked for more information.');
    Livewire::test(PayoutReviews::class)
        ->callAction('decline', data: ['note' => 'ab'])
        ->assertHasActionErrors(['note' => 'min']);
    expect($payout->refresh()->status)->toBe(PayoutState::Review);

    Livewire::test(PayoutReviews::class)
        ->callAction('hold', data: ['note' => 'Need a bank letter'])
        ->assertNotified('On hold. The creator has been asked for more information.');
    expect($payout->refresh()->status)->toBe(PayoutState::Held);

    Livewire::test(PayoutReviews::class)
        ->assertSee(['On hold', 'Need a bank letter', 'Approve & send', 'Decline'])
        ->assertActionVisible('approve')
        ->callAction('decline', data: ['note' => 'No letter after 7 days'])
        ->assertNotified('Declined. The money is back in the creator\'s earnings.');

    expect($payout->refresh()->status)->toBe(PayoutState::Rejected)
        ->and(app(Ledger::class)->balance(AccountRef::of(AccountType::CreatorEarnings, $payout->creator_id))->amountMinor)->toBe(420_000)
        ->and($this->audit->has('wallet.payout_held', $payout->id))->toBeTrue()
        ->and($this->audit->has('wallet.payout_declined', $payout->id))->toBeTrue();
});

it('keeps the hold note on the payout when it is approved without a note', function (): void {
    signedInStaff();
    $payout = reviewPayout(anAccount(), 420_000);

    Livewire::test(PayoutReviews::class)->callAction('hold', data: ['note' => 'Asked for a bank letter']);
    Livewire::test(PayoutReviews::class)->callAction('approve', data: ['note' => ''])->assertNotified('Approved. Sending now.');

    expect($payout->refresh()->status)->toBe(PayoutState::Approved)
        ->and($payout->review_note)->toBe('Asked for a bank letter')
        ->and($this->audit->has('wallet.payout_approved', $payout->id))->toBeTrue();

    // The decided payout still shows the note to the next reviewer who opens it.
    Livewire::withQueryParams(['payout' => $payout->id])->test(PayoutReviews::class)
        ->assertSee(["Reviewer's note", 'Asked for a bank letter', 'Sending. This payout is no longer waiting for review.']);

    // Once sent, it is still this creator's first payout: it never counts itself.
    $payout->forceFill(['status' => PayoutState::Sent, 'sent_at' => now()])->save();
    Livewire::withQueryParams(['payout' => $payout->id])->test(PayoutReviews::class)
        ->assertSee('First payout for this creator')
        ->assertDontSee('1 earlier payout sent');
});

it('shows a payout that is no longer waiting, and refuses to decide it', function (): void {
    signedInStaff();
    $payout = reviewPayout(anAccount(), 420_000);
    $page = Livewire::test(PayoutReviews::class);

    // Another admin approved it in the meantime.
    $payout->forceFill(['status' => PayoutState::Approved])->save();
    $page->callAction('approve', data: ['note' => 'Fine'])
        ->assertHasErrors('payout')
        ->assertNotified('This payout is no longer waiting for review.');

    Livewire::withQueryParams(['payout' => $payout->id])->test(PayoutReviews::class)
        ->assertSee('Sending. This payout is no longer waiting for review.')
        ->assertDontSee('Approve & send');
});

it('pages through a long queue', function (): void {
    signedInStaff();
    $creator = anAccount();
    foreach (range(1, 26) as $i) {
        reviewPayout($creator, 100_000 + $i);
        $this->travel(1)->minutes();
    }

    $page = Livewire::test(PayoutReviews::class)->assertSee('Page 1 of 2');
    expect($page->viewData('queue')->count())->toBe(25);

    $page->call('goToPage', 2)->assertSet('page', 2)->assertSee('Page 2 of 2')
        ->call('goToPage', -3)->assertSet('page', 1);
    expect(PayoutReviews::money(-1_005))->toBe('-$10.05');
});

it('gives the Overview its sales, payouts waiting and ledger check', function (): void {
    $stats = new MoneyStatsService;
    $nairobi = CarbonImmutable::now('Africa/Nairobi')->startOfDay();

    recordSale(1_000, CarbonImmutable::parse('2026-11-25 09:00', 'UTC'));
    recordSale(2_550, CarbonImmutable::parse('2026-11-24 22:30', 'UTC')); // 25 Nov 01:30 in Nairobi
    recordSale(700, CarbonImmutable::parse('2026-11-23 12:00', 'UTC'));
    recordSale(9_999, CarbonImmutable::parse('2026-11-25 08:00', 'UTC'), refunded: true);

    expect($stats->salesBetween($nairobi, $nairobi->addDay()))->toBe(3_550)
        ->and($stats->salesPerDay($nairobi->subDays(2), 3))->toBe([700, 0, 3_550])
        ->and($stats->payoutsWaiting())->toBe(['count' => 0, 'amount' => 0]);

    reviewPayout(anAccount(), 120_000);
    reviewPayout(anAccount(), 5_000, [], PayoutState::Held);
    expect($stats->payoutsWaiting())->toBe(['count' => 2, 'amount' => 125_000]);

    expect($stats->lastLedgerCheck())->toBeNull();
    Cache::forever(VerifyLedger::RESULT_KEY, ['ok' => false]);
    expect($stats->lastLedgerCheck())->toBeNull();
    Cache::forever(VerifyLedger::RESULT_KEY, 'garbage');
    expect($stats->lastLedgerCheck())->toBeNull();

    Cache::forever(VerifyLedger::RESULT_KEY, ['checked_at' => '2026-11-25T02:00:00+00:00']);
    expect($stats->lastLedgerCheck())->toEqual(new LedgerCheck(false, CarbonImmutable::parse('2026-11-25T02:00:00+00:00'), 0));

    $this->artisan('wallet:verify-ledger')->assertSuccessful();
    $check = $stats->lastLedgerCheck();
    expect($check?->ok)->toBeTrue()->and($check?->mismatches)->toBe(0);

    Cache::forever(VerifyLedger::RESULT_KEY, ['ok' => false, 'checked_at' => now()->toIso8601String(), 'unbalanced' => ['a'], 'mismatched' => ['b', 'c']]);
    expect($stats->lastLedgerCheck()?->mismatches)->toBe(3);
});
