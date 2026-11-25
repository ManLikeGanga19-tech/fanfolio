<?php

declare(strict_types=1);

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Creators\Contracts\Data\PayoutDestination;
use App\Modules\Creators\Contracts\PayoutDestinations;
use App\Modules\Identity\Contracts\SessionSecurity;
use App\Modules\Wallet\Actions\RequestPayout;
use App\Modules\Wallet\Actions\ReviewPayout;
use App\Modules\Wallet\Actions\SendPayout;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\PayoutRail;
use App\Modules\Wallet\Contracts\Data\PayoutResult;
use App\Modules\Wallet\Contracts\Data\PayoutStatus;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\PayoutGateways;
use App\Modules\Wallet\Enums\PayoutState;
use App\Modules\Wallet\Events\PayoutFailed;
use App\Modules\Wallet\Events\PayoutSent;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Filament\Studio\EarningsPage;
use App\Modules\Wallet\Gateways\Fake\FakePayoutGateway;
use App\Modules\Wallet\Models\Payout;
use App\Modules\Wallet\Notifications\PayoutUpdate;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// M3 PR 4: withdrawals. Spec "Payout flow" and postings; brief rule 3; decisions D-13, D-16;
// canvas StudioEarnings. Creators and Identity are stood in for here; tests/Feature/Money and the
// Creators tests use the real ones.

afterEach(fn () => expectLedgerBalanced());

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    Notification::fake();
    $this->creator = signedInFan()->id;
    $this->canWithdraw = true;
    $this->freshSecondFactor = true;
    $this->newDevice = false;
    $this->methods = [new PayoutDestination('01jtestmethodbank000000000', PayoutRail::Bank, 'Test Bank ····2222', true, true, CarbonImmutable::now()->subMonth())];
    $test = $this;

    app()->instance(CreatorEligibility::class, new readonly class($test) implements CreatorEligibility
    {
        public function __construct(private object $test) {}

        public function canPublish(string $userId): bool
        {
            return true;
        }

        public function canEarn(string $userId): bool
        {
            return true;
        }

        public function canWithdraw(string $userId): bool
        {
            return $this->test->canWithdraw;
        }
    });

    app()->instance(PayoutDestinations::class, new readonly class($test) implements PayoutDestinations
    {
        public function __construct(private object $test) {}

        public function forUser(string $userId): array
        {
            return $this->test->methods;
        }

        public function find(string $userId, string $methodId): ?PayoutDestination
        {
            foreach ($this->test->methods as $method) {
                if ($method->id === $methodId) {
                    return $method;
                }
            }

            return null;
        }

        public function instructionFor(string $userId, string $methodId): ?array
        {
            return $this->find($userId, $methodId) instanceof PayoutDestination ? ['account_name' => 'Test Person', 'details' => ['account_number' => '000011112222']] : null;
        }
    });

    app()->instance(SessionSecurity::class, new readonly class($test) implements SessionSecurity
    {
        public function __construct(private object $test) {}

        public function hasFreshSecondFactor(Session $session): bool
        {
            return $this->test->freshSecondFactor;
        }

        public function isOnNewDevice(Session $session, string $userId, int $days = 7): bool
        {
            return $this->test->newDevice;
        }
    });
});

/** Gives the creator released earnings, as if sales had cleared their hold. */
function earnings(string $creator, int $cents): void
{
    app(Ledger::class)->post(LedgerTransaction::make('test.earnings', 'earn:'.Str::ulid(), [
        Posting::debit(AccountRef::platform(AccountType::PlatformRevenue), Money::of($cents, 'USD')),
        Posting::credit(AccountRef::of(AccountType::CreatorEarnings, $creator), Money::of($cents, 'USD')),
    ]));
}

function withdraw(string $creator, int $cents, string $key = 'w1', string $method = '01jtestmethodbank000000000', string $ip = '203.0.113.5'): Payout
{
    return app(RequestPayout::class)->handle($creator, $method, $cents, $key, app('session.store'), $ip);
}

function balance(AccountType $type, ?string $owner = null): int
{
    $ref = $owner === null ? AccountRef::platform($type) : AccountRef::of($type, $owner);

    return app(Ledger::class)->balance($ref)->amountMinor;
}

function bankGateway(): FakePayoutGateway
{
    /** @var FakePayoutGateway $gateway */
    $gateway = app(PayoutGateways::class)->for(PayoutRail::Bank);

    return $gateway;
}

it('pays a small, unflagged withdrawal automatically and books it as sent', function (): void {
    Event::fake([PayoutSent::class]);
    earnings($this->creator, 50000);

    $payout = withdraw($this->creator, 25000);

    expect($payout->refresh()->status)->toBe(PayoutState::Sent)
        ->and($payout->flags)->toBe([])
        ->and($payout->gateway_ref)->not->toBeNull()
        ->and(balance(AccountType::CreatorEarnings, $this->creator))->toBe(25000)
        ->and(balance(AccountType::PayoutClearing, $this->creator))->toBe(0)
        ->and(balance(AccountType::ProcessorClearing))->toBe(25000)
        ->and($this->audit->of('wallet.payout_requested')[0]['context'])->toMatchArray(['auto_approved' => true, 'amount_minor' => 25000])
        ->and($this->audit->of('wallet.payout_sent'))->toHaveCount(1);
    Event::assertDispatched(PayoutSent::class, fn (PayoutSent $e): bool => $e->amountMinor === 25000);
    Notification::assertSentTimes(PayoutUpdate::class, 1);
});

it('sends flagged or large withdrawals to an admin, with the money locked', function (Closure $setUp, int $cents, string $flag): void {
    earnings($this->creator, 200000);
    $setUp($this);

    $payout = withdraw($this->creator, $cents);

    expect($payout->status)->toBe(PayoutState::Review)
        ->and($payout->flags)->toContain($flag)
        ->and(balance(AccountType::PayoutClearing, $this->creator))->toBe($cents)
        ->and(balance(AccountType::CreatorEarnings, $this->creator))->toBe(200000 - $cents);
})->with([
    'over $1,000 (rule 3)' => [fn ($t): null => null, 100001, Payout::OVER_REVIEW_LIMIT],
    'new payout method' => [fn ($t): array => $t->methods = [new PayoutDestination('01jtestmethodbank000000000', PayoutRail::Bank, 'New ····1', true, true, CarbonImmutable::now()->subDay())], 5000, Payout::NEW_METHOD],
    'new device' => [fn ($t): true => $t->newDevice = true, 5000, Payout::NEW_DEVICE],
    'payout name does not match ID' => [fn ($t): array => $t->methods = [new PayoutDestination('01jtestmethodbank000000000', PayoutRail::Bank, 'Other ····1', false, true, CarbonImmutable::now()->subMonth())], 5000, Payout::NAME_MISMATCH],
]);

it('pays exactly $1,000 without review', function (): void {
    earnings($this->creator, 100000);

    expect(withdraw($this->creator, 100000)->refresh()->status)->toBe(PayoutState::Sent);
});

it('refuses before any money moves', function (Closure $setUp, Closure $request, string $field): void {
    earnings($this->creator, 10000);
    $setUp($this);

    try {
        $request($this);
        $this->fail('Expected a refusal.');
    } catch (WalletException $e) {
        expect($e->field)->toBe($field);
    }

    expect(balance(AccountType::CreatorEarnings, $this->creator))->toBe(10000);
})->with([
    'withdrawals not allowed' => [fn ($t): false => $t->canWithdraw = false, fn ($t): Payout => withdraw($t->creator, 5000), 'account'],
    'under the minimum' => [fn ($t): null => null, fn ($t): Payout => withdraw($t->creator, 1999), 'amount'],
    'no fresh second factor (D-13)' => [fn ($t): false => $t->freshSecondFactor = false, fn ($t): Payout => withdraw($t->creator, 5000), 'confirm'],
    'VPN (D-16)' => [fn ($t) => config(['fanfolio.fake.anonymised_ips' => ['198.51.100.9']]), fn ($t): Payout => withdraw($t->creator, 5000, 'w1', '01jtestmethodbank000000000', '198.51.100.9'), 'network'],
    'unknown method' => [fn ($t): null => null, fn ($t): Payout => withdraw($t->creator, 5000, 'w1', 'nope'), 'method'],
    'more than available' => [fn ($t): null => null, fn ($t): Payout => withdraw($t->creator, 10001), 'amount'],
    'no key' => [fn ($t): null => null, fn ($t): Payout => withdraw($t->creator, 5000, ' '), 'idempotency_key'],
]);

it('allows one withdrawal in progress at a time, and a repeat of the same request returns it', function (): void {
    earnings($this->creator, 300000);
    $first = withdraw($this->creator, 150000, 'big');

    expect(withdraw($this->creator, 150000, 'big')->id)->toBe($first->id)
        ->and(fn (): Payout => withdraw($this->creator, 5000, 'second'))->toThrow(WalletException::class, 'already have a withdrawal in progress')
        ->and(Payout::query()->count())->toBe(1);
});

it('passes on a busy wallet as is', function (): void {
    earnings($this->creator, 10000);
    config(['fanfolio.money.wallet_lock_wait_seconds' => 0]);
    $lock = Cache::lock('wallet:'.$this->creator, 10);
    $lock->get();

    try {
        expect(fn (): Payout => withdraw($this->creator, 5000))->toThrow(LedgerException::class);
    } finally {
        $lock->release();
    }

    expect(Payout::query()->count())->toBe(0);
});

it('returns the money when the provider refuses, and tells the creator', function (): void {
    Event::fake([PayoutFailed::class]);
    earnings($this->creator, 10000);
    bankGateway()->willReturn(PayoutStatus::Failed, 'Account closed');

    $payout = withdraw($this->creator, 5000)->refresh();

    expect($payout->status)->toBe(PayoutState::Failed)
        ->and($payout->failure_reason)->toBe('Account closed')
        ->and(balance(AccountType::CreatorEarnings, $this->creator))->toBe(10000)
        ->and(balance(AccountType::PayoutClearing, $this->creator))->toBe(0);
    Event::assertDispatched(PayoutFailed::class);
    Notification::assertSentTimes(PayoutUpdate::class, 1);
});

it('asks the provider again about a pending payout until it is sent', function (): void {
    earnings($this->creator, 10000);
    bankGateway()->willReturn(PayoutStatus::Pending);

    $payout = withdraw($this->creator, 5000)->refresh();
    expect($payout->status)->toBe(PayoutState::Sending)->and($payout->gateway_ref)->not->toBeNull();

    // The provider has since paid it.
    Cache::put('fake:payout:'.$payout->gateway_ref, ['status' => 'sent', 'reason' => null], 60);
    $this->artisan('wallet:payout-status')->expectsOutput('Checked 1 payout(s).')->assertSuccessful();

    expect($payout->refresh()->status)->toBe(PayoutState::Sent)->and(balance(AccountType::ProcessorClearing))->toBe(5000);
});

it('fails a payout whose method was removed before it was sent', function (): void {
    earnings($this->creator, 10000);
    $this->methods = [new PayoutDestination('01jtestmethodbank000000000', PayoutRail::Bank, 'Test Bank ····2222', true, true, CarbonImmutable::now()->subMonth())];
    earnings($this->creator, 100000);
    $payout = withdraw($this->creator, 100001);
    $this->methods = [];
    $admin = signedInStaff();

    app(ReviewPayout::class)->approve($payout->id, $admin->id);

    expect($payout->refresh()->status)->toBe(PayoutState::Failed)
        ->and($payout->failure_reason)->toBe('The payout method was removed.')
        ->and(balance(AccountType::CreatorEarnings, $this->creator))->toBe(110000);
});

it('lets an admin approve, hold or decline a payout under review', function (): void {
    earnings($this->creator, 400000);
    $admin = signedInStaff();

    $approve = withdraw($this->creator, 150000, 'a');
    app(ReviewPayout::class)->approve($approve->id, $admin->id, 'Checked');
    expect($approve->refresh()->status)->toBe(PayoutState::Sent)->and($approve->reviewed_by)->toBe($admin->id);

    $held = withdraw($this->creator, 150000, 'b');
    app(ReviewPayout::class)->hold($held->id, $admin->id, 'Need a bank letter');
    expect($held->refresh()->status)->toBe(PayoutState::Held)->and(balance(AccountType::PayoutClearing, $this->creator))->toBe(150000);

    app(ReviewPayout::class)->decline($held->id, $admin->id, 'No letter after 7 days');
    expect($held->refresh()->status)->toBe(PayoutState::Rejected)
        ->and(balance(AccountType::PayoutClearing, $this->creator))->toBe(0)
        ->and(balance(AccountType::CreatorEarnings, $this->creator))->toBe(250000)
        ->and($this->audit->of('wallet.payout_declined')[0]['context'])->toMatchArray(['note' => 'No letter after 7 days']);

    expect(fn () => app(ReviewPayout::class)->approve($held->id, $admin->id))->toThrow(WalletException::class, 'no longer waiting');
});

it('refuses reviews from non-admins, without a note, and of your own payout', function (): void {
    earnings($this->creator, 400000);
    $payout = withdraw($this->creator, 150000);
    $moderator = signedInStaff('moderator');
    $admin = signedInStaff();

    expect(fn () => app(ReviewPayout::class)->approve($payout->id, $moderator->id))->toThrow(WalletException::class, 'Your role cannot do this.')
        ->and(fn () => app(ReviewPayout::class)->hold($payout->id, $admin->id, ' '))->toThrow(WalletException::class, 'Write a note')
        ->and(fn () => app(ReviewPayout::class)->decline('nope', $admin->id, 'Fraud'))->toThrow(WalletException::class, 'no longer waiting');

    // An admin's own payout.
    earnings($admin->id, 400000);
    $own = withdraw($admin->id, 150000, 'own');
    expect(fn () => app(ReviewPayout::class)->approve($own->id, $admin->id))->toThrow(WalletException::class, 'your own payout');
});

it('ignores sends and answers for payouts that are not being sent', function (): void {
    earnings($this->creator, 400000);
    $payout = withdraw($this->creator, 150000);

    app(SendPayout::class)->send('nope');
    app(SendPayout::class)->send($payout->id);
    app(SendPayout::class)->refresh($payout->id);
    app(SendPayout::class)->settle($payout->id, new PayoutResult(PayoutStatus::Sent, 'x'));

    expect($payout->refresh()->status)->toBe(PayoutState::Review);
});

it('shows Earnings & withdrawals from the canvas and requests a withdrawal from it', function (): void {
    earnings($this->creator, 192000);
    Filament::setCurrentPanel('studio');

    Livewire::test(EarningsPage::class)
        ->assertSee('Available to withdraw')
        ->assertSee('$1,920')
        ->assertSee('Pending · releases after 7 days')
        ->assertSee('Paid out all time')
        ->assertSee('Test Bank ····2222', false)
        ->assertSee("You'll confirm with your passkey or authenticator app. Requests over $1,000 are reviewed within 24 hours.", false)
        ->assertSee('No withdrawals yet.')
        ->call('fillMax')
        ->assertSet('amount', '1920.00')
        ->set('amount', 'twenty')
        ->call('requestWithdrawal')
        ->assertHasErrors('amount')
        ->set('amount', '10')
        ->call('requestWithdrawal')
        ->assertHasErrors(['amount' => 'The minimum withdrawal is $20.00.'])
        ->set('amount', '250.50')
        ->call('requestWithdrawal')
        ->assertHasNoErrors()
        ->assertNotified('Withdrawal requested. It is on its way.')
        ->assertSee('$250.50')
        ->assertSee('Sent');

    expect(balance(AccountType::CreatorEarnings, $this->creator))->toBe(192000 - 25050);
});

it('says a large request goes to review, and sends a stale session to confirm first (D-13)', function (): void {
    earnings($this->creator, 192000);
    Filament::setCurrentPanel('studio');

    Livewire::test(EarningsPage::class)->set('amount', '1,500')->call('requestWithdrawal')
        ->assertNotified('Withdrawal requested. We review it within 24 hours.')
        ->assertSee('Under review');

    $this->freshSecondFactor = false;
    Livewire::test(EarningsPage::class)->set('amount', '20')->call('requestWithdrawal')->assertRedirect(route('wallet.payouts.confirm'));
});

it('finishes the withdrawal after the second-factor step, once, without typing it again (D-13)', function (): void {
    earnings($this->creator, 192000);
    Filament::setCurrentPanel('studio');

    $this->freshSecondFactor = false;
    Livewire::test(EarningsPage::class)->set('amount', '40')->call('requestWithdrawal')->assertRedirect(route('wallet.payouts.confirm'));
    expect(Payout::query()->count())->toBe(0)
        ->and(session(EarningsPage::DRAFT_KEY))->toMatchArray(['amount' => '40']);

    // Back from the confirmation: the same request completes with its own key.
    $this->freshSecondFactor = true;
    Livewire::test(EarningsPage::class)
        ->assertNotified('Withdrawal requested. It is on its way.')
        ->assertSet('amount', '')
        ->assertSee('$40');

    // The draft is used up: opening Earnings again asks for nothing.
    Livewire::test(EarningsPage::class)->assertNotNotified();

    expect(Payout::query()->count())->toBe(1)
        ->and(Payout::query()->sole()->amount_minor)->toBe(4000)
        ->and(session()->has(EarningsPage::DRAFT_KEY))->toBeFalse()
        ->and(balance(AccountType::CreatorEarnings, $this->creator))->toBe(192000 - 4000);
});

it('drops a malformed draft instead of acting on it', function (): void {
    earnings($this->creator, 192000);
    Filament::setCurrentPanel('studio');
    session()->put(EarningsPage::DRAFT_KEY, ['amount' => 40]);

    Livewire::test(EarningsPage::class)->assertSet('amount', '')->assertNotNotified();

    expect(Payout::query()->count())->toBe(0)->and(session()->has(EarningsPage::DRAFT_KEY))->toBeFalse();
});

it('turns typed dollars into cents without floats', function (string $typed, ?int $cents): void {
    expect(EarningsPage::toCents($typed))->toBe($cents);
})->with([
    ['1920', 192000], ['1,920.00', 192000], ['$250.5', 25050], ['0.07', 7], ['12.345', null], ['-5', null], ['', null], ['abc', null],
]);

it('formats the cards like the canvas', function (): void {
    expect(EarningsPage::money(192000))->toBe('$1,920')->and(EarningsPage::money(25050))->toBe('$250.50')->and(EarningsPage::money(-500))->toBe('−$5.00');
});

it('confirms with the second factor and returns to Earnings (D-13)', function (): void {
    // No passkey or authenticator yet: set one up first.
    $this->get(route('wallet.payouts.confirm'))->assertRedirect(route('account.security'));

    signedInFanWithMfa();
    $this->get(route('wallet.payouts.confirm'))->assertRedirect(route('account.confirm'));

    confirmedSecondFactor();
    $this->get(route('wallet.payouts.confirm'))->assertRedirect(EarningsPage::getUrl(panel: 'studio'));
});

it('labels every state as the canvas does, and knows which still hold money', function (): void {
    expect(array_map(static fn (PayoutState $s): string => $s->label(), PayoutState::cases()))
        ->toBe(['Under review', 'Under review', 'Sending', 'Sending', 'Sent', 'Failed · refunded', 'Declined · refunded'])
        ->and(array_values(array_filter(PayoutState::cases(), static fn (PayoutState $s): bool => $s->inProgress())))
        ->toBe([PayoutState::Review, PayoutState::Held, PayoutState::Approved, PayoutState::Sending]);
});

it('emails the creator what happened, never a staff note', function (string $kind, string $subject, string $line): void {
    $mail = new PayoutUpdate($kind, 25050, 'Test Bank ····2222')->toMail(new stdClass);

    expect($mail->subject)->toContain($subject)
        ->and(implode(' ', $mail->introLines))->toContain($line)
        ->and(new PayoutUpdate($kind, 1, 'x')->via(new stdClass))->toBe(['mail']);
})->with([
    'sent' => [PayoutUpdate::SENT, 'is on its way', 'We sent $250.50 to Test Bank ····2222.'],
    'failed' => [PayoutUpdate::FAILED, 'did not go through', 'back in your available earnings'],
    'held' => [PayoutUpdate::HELD, 'more information', 'stays reserved for you'],
    'declined' => [PayoutUpdate::DECLINED, 'was not approved', 'back in your available earnings'],
]);

it('shows a busy wallet on the Studio form instead of failing', function (): void {
    earnings($this->creator, 10000);
    Filament::setCurrentPanel('studio');
    config(['fanfolio.money.wallet_lock_wait_seconds' => 0]);
    $lock = Cache::lock('wallet:'.$this->creator, 10);
    $lock->get();

    try {
        Livewire::test(EarningsPage::class)->set('amount', '50')->call('requestWithdrawal')
            ->assertHasErrors(['amount' => 'Another payment on this wallet is in progress. Try again.']);
    } finally {
        $lock->release();
    }
});
