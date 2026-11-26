<?php

declare(strict_types=1);

use App\Modules\Wallet\Actions\ApplyGatewayEvent;
use App\Modules\Wallet\Actions\StartTopUp;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use App\Modules\Wallet\Contracts\Data\TopUpMethod;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Enums\TopUpStatus;
use App\Modules\Wallet\Events\WalletToppedUp;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Modules\Wallet\Models\GatewayWebhook;
use App\Modules\Wallet\Models\TopUp;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingAuditLog;

// M3 PR 2: canvas FanCore · Wallet & top-up and FanMore · Top-up success; spec "Top-up flow" and
// "Inbound webhooks"; SEC-13; decisions D-04 (bonus), D-16 (VPN), D-21 (display currency), D-22.

afterEach(fn () => expectLedgerBalanced());

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    $this->fan = signedInFan();
});

/** The fan starts a top-up from the wallet page; returns the pending top-up. */
function startTopUpAs(int $pack = 1, string $method = 'card', ?string $key = null): TopUp
{
    test()->post('/wallet/topups', ['pack' => $pack, 'method' => $method, 'idempotency_key' => $key ?? (string) Str::ulid()])
        ->assertRedirectContains('/_fake/checkout/');

    return TopUp::query()->latest('created_at')->latest('id')->firstOrFail();
}

/** The gateway calls our webhook endpoint. */
function sendWebhook(GatewayEventType $type, TopUp $topUp, ?int $amount = null, ?string $eventId = null): TestResponse
{
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $hook = $gateway->signedWebhook($type, (string) $topUp->gateway_ref, Money::of($amount ?? $topUp->amount_minor, 'USD'), $eventId);

    return test()->call('POST', '/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $hook['headers']['x-fake-signature'], 'CONTENT_TYPE' => 'application/json'], $hook['payload']);
}

function walletOf(string $userId): int
{
    return app(Ledger::class)->balance(AccountRef::of(AccountType::FanWallet, $userId))->amountMinor;
}

it('shows the wallet with the canvas packs, bonuses and methods', function (): void {
    $this->get('/wallet', ['Accept-Language' => 'en-US'])
        ->assertOk()
        ->assertSee('Available credits')
        ->assertSee('≈ $0.00')
        ->assertSeeInOrder(['500 credits', '$5', '1,000 credits', '$10', '2,600 credits', '$25', '+4%', '5,500 credits', '$50', '+10%'])
        ->assertSee('Card')
        ->assertSee('Crypto (USDT)')
        ->assertSee('Continue to secure checkout')
        ->assertSee('never sees your card');
});

it('keeps guests out', function (): void {
    auth()->logout();

    $this->get('/wallet')->assertRedirect(route('login'));
});

it('tops up end to end: checkout, signed webhook, credits with the D-04 bonus, receipt', function (): void {
    Event::fake([WalletToppedUp::class]);

    // Pack 3 is $25 with a 4% bonus: 2,500 credits paid for, 100 free.
    $topUp = startTopUpAs(2);
    expect($topUp->status)->toBe(TopUpStatus::Pending)
        ->and($topUp->amount_minor)->toBe(2500)
        ->and($topUp->bonus_credits)->toBe(100)
        ->and($this->audit->of('wallet.topup_started'))->toHaveCount(1);

    $this->get('/_fake/checkout/'.$topUp->gateway_ref)->assertOk()->assertSee('Pay $25.00');
    $this->post('/_fake/checkout/'.$topUp->gateway_ref, ['outcome' => 'pay'])->assertRedirect(route('wallet.topups.show', $topUp->id));

    expect($topUp->refresh()->status)->toBe(TopUpStatus::Paid)
        ->and(walletOf($this->fan->id))->toBe(2600)
        ->and(app(Ledger::class)->balance(AccountRef::platform(AccountType::PlatformPromotions))->amountMinor)->toBe(-100)
        ->and(app(Ledger::class)->balance(AccountRef::platform(AccountType::ProcessorClearing))->amountMinor)->toBe(-2500)
        ->and($this->audit->of('wallet.topped_up')[0]['context'])->toMatchArray(['credits' => 2500, 'bonus_credits' => 100]);
    Event::assertDispatched(WalletToppedUp::class, fn (WalletToppedUp $e): bool => $e->credits === 2600 && $e->fanId === $this->fan->id);

    $this->get(route('wallet.topups.show', $topUp->id))
        ->assertOk()
        ->assertSee('2,600 credits added')
        ->assertSee('Your new balance is 2,600 credits.')
        ->assertSee('$25.00')
        ->assertSee('+100 credits')
        ->assertSee('Visa ····4242', false)
        ->assertSee($topUp->reference())
        ->assertSee('FF*DIGITAL')
        ->assertSee('Download receipt');

    $this->get(route('wallet.topups.receipt', $topUp->id))->assertOk()->assertSee('Receipt')->assertSee('[CREDIT REFUND POLICY]');
});

it('never starts two top-ups for one tap, and shows a paid one instead of a second checkout', function (): void {
    $key = (string) Str::ulid();
    $first = startTopUpAs(0, 'card', $key);
    startTopUpAs(0, 'card', $key);

    expect(TopUp::query()->count())->toBe(1);

    sendWebhook(GatewayEventType::PaymentSucceeded, $first)->assertOk();
    $this->post('/wallet/topups', ['pack' => 0, 'method' => 'card', 'idempotency_key' => $key])
        ->assertRedirect(route('wallet.topups.show', $first->id));

    // The Idempotency-Key header wins over the form field (spec "API contracts").
    $this->post('/wallet/topups', ['pack' => 0, 'method' => 'card', 'idempotency_key' => 'ignored'], ['Idempotency-Key' => $key])
        ->assertRedirect(route('wallet.topups.show', $first->id));
    expect(TopUp::query()->count())->toBe(1);
});

it('credits once however often the gateway repeats itself', function (): void {
    $topUp = startTopUpAs(0);

    sendWebhook(GatewayEventType::PaymentSucceeded, $topUp, null, 'evt_same')->assertOk();
    sendWebhook(GatewayEventType::PaymentSucceeded, $topUp, null, 'evt_same')->assertOk();
    // A second confirmation with a new event id changes nothing either.
    sendWebhook(GatewayEventType::PaymentSucceeded, $topUp, null, 'evt_other')->assertOk();
    // Nor does a late "failed" for a paid top-up.
    sendWebhook(GatewayEventType::PaymentFailed, $topUp, null, 'evt_late_fail')->assertOk();

    expect(walletOf($this->fan->id))->toBe(500)
        ->and(GatewayWebhook::query()->count())->toBe(3)
        ->and(DB::table('ledger_transactions')->where('type', 'topup')->count())->toBe(1)
        ->and($topUp->refresh()->status)->toBe(TopUpStatus::Paid);
});

it('shows the method the fan chose: a crypto top-up says USDT, not a card', function (): void {
    $topUp = startTopUpAs(3, 'crypto');

    $this->post('/_fake/checkout/'.$topUp->gateway_ref, ['outcome' => 'pay']);

    expect($topUp->refresh()->payment_hint)->toBe('USDT (TRC-20)');
    $this->get(route('wallet.topups.show', $topUp->id))->assertSee('USDT (TRC-20)')->assertDontSee('Visa');
    $this->get('/account/purchases')->assertSee('Top-up · USDT (TRC-20)');
});

it('marks a declined payment and adds nothing', function (): void {
    $topUp = startTopUpAs(0);

    $this->post('/_fake/checkout/'.$topUp->gateway_ref, ['outcome' => 'decline']);

    expect($topUp->refresh()->status)->toBe(TopUpStatus::Failed)->and(walletOf($this->fan->id))->toBe(0);
    $this->get(route('wallet.topups.show', $topUp->id))->assertSee('The payment did not go through');
});

it('shows a pending top-up while the gateway has not confirmed', function (): void {
    $topUp = startTopUpAs(0);

    $this->get(route('wallet.topups.show', $topUp->id))
        ->assertOk()
        ->assertSee('Waiting for your payment')
        ->assertSee('Return to checkout');
    $this->get(route('wallet.topups.receipt', $topUp->id))->assertNotFound();
});

it('refuses webhooks with a bad signature or from an unknown gateway (SEC-13)', function (): void {
    $topUp = startTopUpAs(0);
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $hook = $gateway->signedWebhook(GatewayEventType::PaymentSucceeded, (string) $topUp->gateway_ref, Money::of(500, 'USD'));

    $this->call('POST', '/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => 'forged'], $hook['payload'])->assertStatus(400);
    $this->call('POST', '/webhooks/other', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $hook['headers']['x-fake-signature']], $hook['payload'])->assertStatus(400);

    expect(GatewayWebhook::query()->count())->toBe(0)->and(walletOf($this->fan->id))->toBe(0);
});

it('accepts a payment the gateway reports without a payment hint', function (): void {
    $topUp = startTopUpAs(0);
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $hook = $gateway->signedWebhook(GatewayEventType::PaymentSucceeded, (string) $topUp->gateway_ref, Money::of(500, 'USD'), null, null, null);

    $this->call('POST', '/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $hook['headers']['x-fake-signature']], $hook['payload'])->assertOk();

    expect($topUp->refresh()->payment_hint)->toBeNull();
    $this->get(route('wallet.topups.show', $topUp->id))->assertSee('Paid with')->assertSee('Card');
});

it('stores the raw payload encrypted', function (): void {
    $topUp = startTopUpAs(0);
    sendWebhook(GatewayEventType::PaymentSucceeded, $topUp);

    $raw = (string) DB::table('gateway_webhooks')->value('payload');
    expect($raw)->not->toContain((string) $topUp->gateway_ref)
        ->and(GatewayWebhook::query()->sole()->payload)->toContain((string) $topUp->gateway_ref);
});

it('credits nothing when the amount does not match what the fan chose', function (): void {
    Log::spy();
    $topUp = startTopUpAs(0);

    sendWebhook(GatewayEventType::PaymentSucceeded, $topUp, 1)->assertOk();

    expect(walletOf($this->fan->id))->toBe(0)
        ->and($topUp->refresh()->status)->toBe(TopUpStatus::Pending)
        ->and(GatewayWebhook::query()->sole()->error)->toContain('Amount does not match');
    Log::shouldHaveReceived('critical')->once();
});

it('records events for unknown top-ups, and a chargeback it cannot place', function (): void {
    Log::spy();
    $topUp = startTopUpAs(0);
    $stranger = (new TopUp)->forceFill(['gateway_ref' => 'fake_co_unknown', 'amount_minor' => 500]);

    sendWebhook(GatewayEventType::PaymentSucceeded, $stranger, null, 'evt_unknown_paid');
    sendWebhook(GatewayEventType::PaymentFailed, $stranger, null, 'evt_unknown_failed');
    // No case reference: nothing can be taken back (ChargebacksTest covers the real thing).
    sendWebhook(GatewayEventType::Chargeback, $topUp, null, 'evt_cb');

    expect(GatewayWebhook::query()->where('event_id', 'evt_unknown_paid')->sole()->error)->toBe('No top-up with this gateway reference.')
        ->and(GatewayWebhook::query()->where('event_id', 'evt_unknown_failed')->sole()->processed_at)->not->toBeNull()
        ->and(GatewayWebhook::query()->where('event_id', 'evt_cb')->sole()->processed_at)->not->toBeNull()
        ->and(GatewayWebhook::query()->where('event_id', 'evt_cb')->sole()->error)->toContain('without a case reference');
    Log::shouldHaveReceived('warning')->twice();
    Log::shouldHaveReceived('critical')->once();

    // Applying an unknown or already-processed webhook again is a no-op.
    app(ApplyGatewayEvent::class)->handle('nope');
    app(ApplyGatewayEvent::class)->handle(GatewayWebhook::query()->where('event_id', 'evt_unknown_paid')->sole()->id);
});

it('refuses a frozen wallet, a VPN while the rule is on, a method switched off and a pack that does not exist', function (): void {
    $start = fn (int $pack = 0, TopUpMethod $method = TopUpMethod::Card, string $ip = '203.0.113.9', string $key = '') => app(StartTopUp::class)
        ->handle($this->fan->id, $pack, $method, $key !== '' ? $key : (string) Str::ulid(), $ip);

    expect(fn () => $start(9))->toThrow(WalletException::class, 'Choose a top-up amount.')
        ->and(fn () => $start(0, TopUpMethod::Card, '203.0.113.9', ' '))->toThrow(WalletException::class, 'Reload the page')
        ->and(fn () => $start(0, TopUpMethod::Card, '203.0.113.9', str_repeat('k', 101)))->toThrow(WalletException::class, 'Reload the page');

    config(['fanfolio.topup.methods' => ['card']]);
    expect(fn () => $start(0, TopUpMethod::Crypto))->toThrow(WalletException::class, 'not available');

    config(['fanfolio.fake.anonymised_ips' => ['198.51.100.7']]);
    expect(fn () => $start(0, TopUpMethod::Card, '198.51.100.7'))->toThrow(WalletException::class, 'Turn off your VPN or proxy');
    config(['fanfolio.network_risk.refuse.topups' => false]);
    expect($start(0, TopUpMethod::Card, '198.51.100.7')->status)->toBe(TopUpStatus::Pending);

    DB::table('wallet_freezes')->insert(['fan_id' => $this->fan->id, 'reason' => 'chargeback', 'frozen_at' => now()]);
    expect(fn () => $start())->toThrow(WalletException::class, 'Your wallet is on hold');

    // The form shows the message.
    $this->from('/wallet')->post('/wallet/topups', ['pack' => 0, 'method' => 'card', 'idempotency_key' => 'k'])
        ->assertRedirect('/wallet')->assertSessionHasErrors(['wallet' => 'Your wallet is on hold while we look into a payment. Contact support.']);
});

it("keeps fans out of each other's top-ups (SEC-06)", function (): void {
    $topUp = startTopUpAs(0);
    sendWebhook(GatewayEventType::PaymentSucceeded, $topUp);

    signedInFan();
    $this->get(route('wallet.topups.show', $topUp->id))->assertNotFound();
    $this->get(route('wallet.topups.receipt', $topUp->id))->assertNotFound();
    $this->get('/_fake/checkout/'.$topUp->gateway_ref)->assertNotFound();
});

it('lists paid top-ups under Purchases & receipts, by month, with a printable statement (D-22)', function (): void {
    $topUp = startTopUpAs(1);
    sendWebhook(GatewayEventType::PaymentSucceeded, $topUp);
    startTopUpAs(0); // pending: not listed

    $this->get('/account/purchases')
        ->assertOk()
        ->assertSeeInOrder(['All', 'Top-ups', 'Subscriptions', 'Unlocks', 'Tips'])
        ->assertSee(now()->format('F Y').' · added 1,000 credits')
        ->assertSee('Top-up · Visa ····4242', false)
        ->assertSee($topUp->reference())
        ->assertSee('+1,000')
        ->assertSee('Get help');

    $this->get('/account/purchases?tab=subscription')->assertOk()->assertSee('Nothing here yet.');
    $this->get('/account/purchases?tab=nonsense')->assertOk()->assertSee('+1,000');

    $this->get('/account/purchases/statement')->assertOk()->assertSee('Statement')->assertSee($topUp->reference())->assertSee('$10.00');
});

it('shows an empty statement and purchases list for a new fan', function (): void {
    $this->get('/account/purchases')->assertOk()->assertSee('No purchases yet.');
    $this->get('/account/purchases/statement')->assertOk()->assertSee('No purchases yet.');
});
