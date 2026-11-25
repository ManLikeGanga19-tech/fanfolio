<?php

declare(strict_types=1);

use App\Modules\Audit\Contracts\AuditHistory;
use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Wallet\Actions\ApplyGatewayEvent;
use App\Modules\Wallet\Actions\DisputeEvidence;
use App\Modules\Wallet\Actions\MoneyStatsService;
use App\Modules\Wallet\Actions\ReceiveGatewayWebhook;
use App\Modules\Wallet\Actions\RefundPurchase;
use App\Modules\Wallet\Actions\ReleaseHeldEarnings;
use App\Modules\Wallet\Actions\RespondToDispute;
use App\Modules\Wallet\Actions\StartTopUp;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\DisputeNotice;
use App\Modules\Wallet\Contracts\Data\GatewayEventType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Data\PurchaseKind;
use App\Modules\Wallet\Contracts\Data\PurchaseRequest;
use App\Modules\Wallet\Contracts\Data\TopUpMethod;
use App\Modules\Wallet\Contracts\DisputeGateway;
use App\Modules\Wallet\Contracts\Exceptions\PurchaseRefused;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\Purchases;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Enums\DisputeStatus;
use App\Modules\Wallet\Enums\TopUpStatus;
use App\Modules\Wallet\Events\ChargebackReceived;
use App\Modules\Wallet\Events\DisputeDecided;
use App\Modules\Wallet\Events\PurchaseRefunded;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Modules\Wallet\Gateways\Fake\FakeDisputeGateway;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Modules\Wallet\Models\Dispute;
use App\Modules\Wallet\Models\GatewayWebhook;
use App\Modules\Wallet\Models\TopUp;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\RecordingAuditLog;

// M3 PR 6: chargebacks and disputes. Spec postings "Chargeback" and "Hold released ... skipped
// if a chargeback is open"; owner decision D-20; canvas AdminDisputes (actions); brief rules 1–4, 12.

afterEach(fn () => expectLedgerBalanced());

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
    $this->audit = RecordingAuditLog::swap();
    $this->fan = anAccount();
    $this->c1 = anAccount();
    $this->c2 = anAccount();
    $this->admin = signedInStaff();
    $this->signIns = 0;
    $test = $this;

    app()->instance(CreatorEligibility::class, new readonly class implements CreatorEligibility
    {
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
            return true;
        }
    });

    app()->instance(AuditHistory::class, new readonly class($test) implements AuditHistory
    {
        public function __construct(private object $test) {}

        public function countFromIp(string $action, string $actorId, string $ip, DateTimeInterface $before): int
        {
            return $action === 'identity.login' ? $this->test->signIns : 0;
        }
    });
});

/** The processor calls our webhook endpoint (through the real pipeline, queue sync in tests). */
function processorSends(GatewayEventType $type, TopUp $topUp, ?DisputeNotice $notice = null, ?int $amount = null, string $currency = 'USD', ?string $eventId = null): GatewayWebhook
{
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $eventId ??= 'evt_'.Str::ulid();
    $hook = $gateway->signedWebhook($type, (string) $topUp->gateway_ref, Money::of($amount ?? $topUp->amount_minor, $currency), $eventId, null, $topUp->payment_hint, $notice);
    app(ReceiveGatewayWebhook::class)->handle('fake', $hook['payload'], $hook['headers']);

    return GatewayWebhook::query()->where('event_id', $eventId)->sole();
}

/** A top-up the processor confirmed: the credits and the bonus are in the wallet. */
function paidTopUp(string $fan, int $cents = 5_000, int $bonus = 500, array $extra = []): TopUp
{
    $topUp = new TopUp;
    $topUp->forceFill([
        'fan_id' => $fan,
        'idempotency_key' => (string) Str::ulid(),
        'gateway' => 'fake',
        'gateway_ref' => 'fake_co_'.Str::ulid(),
        'method' => TopUpMethod::Card,
        'amount_minor' => $cents,
        'currency' => 'USD',
        'credits' => $cents,
        'bonus_credits' => $bonus,
        'payment_hint' => 'Visa ····9012',
        'ip' => '203.0.113.7',
        'known_device' => true,
        ...$extra,
    ])->save();

    processorSends(GatewayEventType::PaymentSucceeded, $topUp);
    test()->travel(1)->minute();

    return $topUp->refresh();
}

function chargebackOn(TopUp $topUp, ?int $amount = null, string $case = 'CB-2291', string $reason = 'not_recognised', ?CarbonImmutable $respondBy = null, ?string $threeDs = 'passed'): GatewayWebhook
{
    return processorSends(GatewayEventType::Chargeback, $topUp, new DisputeNotice($case, $reason, $respondBy ?? CarbonImmutable::now()->addDays(10), $threeDs), $amount);
}

/** A sale, one minute after the last step; returns its transaction id. */
function spend(string $fan, string $creator, int $credits): string
{
    $result = app(Purchases::class)->charge(new PurchaseRequest($fan, $creator, Money::credits($credits), PurchaseKind::Unlock, (string) Str::ulid(), 'post', (string) Str::ulid()));
    test()->travel(1)->minute();

    return $result->transactionId;
}

function bal(AccountType $type, ?string $owner = null): int
{
    return app(Ledger::class)->balance($owner === null ? AccountRef::platform($type) : AccountRef::of($type, $owner))->amountMinor;
}

function isFrozen(string $fan): bool
{
    return DB::table('wallet_freezes')->where('fan_id', $fan)->whereNull('lifted_at')->exists();
}

function disputeGateway(): FakeDisputeGateway
{
    /** @var FakeDisputeGateway $gateway */
    $gateway = app(DisputeGateway::class);

    return $gateway;
}

it('takes unspent credits first, the bonus too, and freezes the wallet (D-20)', function (): void {
    Event::fake([ChargebackReceived::class]);
    $topUp = paidTopUp($this->fan);
    expect(bal(AccountType::FanWallet, $this->fan))->toBe(5_500);

    $hook = chargebackOn($topUp);

    $dispute = Dispute::query()->sole();
    expect($hook->refresh()->processed_at)->not->toBeNull()
        ->and($hook->error)->toBeNull()
        ->and($dispute->status)->toBe(DisputeStatus::Open)
        ->and($dispute->gateway_case_ref)->toBe('CB-2291')
        ->and($dispute->reasonLabel())->toBe('Not recognised')
        ->and($dispute->three_ds)->toBe('passed')
        ->and($dispute->respond_by->toDateString())->toBe('2026-12-05')
        // jsonb keeps its own key order.
        ->and($dispute->recovery)->toEqual(['wallet_minor' => 5_500, 'unspent_minor' => 5_500, 'bonus_minor' => 500, 'clawed_minor' => 0, 'written_off_minor' => 0, 'sales' => [], 'transactions' => $dispute->recovery['transactions']])
        ->and(bal(AccountType::FanWallet, $this->fan))->toBe(0)
        ->and(bal(AccountType::ProcessorClearing))->toBe(0)
        ->and(bal(AccountType::PlatformPromotions))->toBe(0)
        ->and($topUp->refresh()->status)->toBe(TopUpStatus::ChargedBack)
        ->and(isFrozen($this->fan))->toBeTrue()
        ->and($this->audit->of('wallet.chargeback')[0]['context'])->toMatchArray(['case' => 'CB-2291', 'amount_minor' => 5_000, 'from_wallet_minor' => 5_500]);
    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $e): bool => $e->disputeId === $dispute->id && $e->clawedBackSales === []);

    // The frozen fan can neither spend nor top up.
    expect(fn (): string => spend($this->fan, $this->c1, 1))->toThrow(PurchaseRefused::class)
        ->and(fn () => app(StartTopUp::class)->handle($this->fan, 0, TopUpMethod::Card, 'k', '203.0.113.7'))->toThrow(WalletException::class, 'on hold');

    // The same case again, or the same event again, changes nothing.
    chargebackOn($topUp);
    expect(Dispute::query()->count())->toBe(1);
});

it('claws back sales newest first when the credits were spent, stopping once covered', function (): void {
    Event::fake([PurchaseRefunded::class, ChargebackReceived::class]);
    $topUp = paidTopUp($this->fan);
    $s1 = spend($this->fan, $this->c1, 2_000);
    $s2 = spend($this->fan, $this->c2, 3_000);
    // Four credits: the fee rounds to nothing, so the creator gets it all.
    $s3 = spend($this->fan, $this->c1, 4);
    expect(bal(AccountType::FanWallet, $this->fan))->toBe(496);

    // A partial chargeback of $25 reverses half the bonus too: 2,750 credits to recover.
    chargebackOn($topUp, 2_500);

    $dispute = Dispute::query()->sole();
    expect($dispute->recovery)->toMatchArray(['wallet_minor' => 2_750, 'unspent_minor' => 496, 'bonus_minor' => 250, 'clawed_minor' => 3_004, 'written_off_minor' => 0, 'sales' => [$s3, $s2]])
        ->and($dispute->recovery['transactions'])->toHaveCount(3)
        // 496 + 3,004 clawed − 2,750 taken.
        ->and(bal(AccountType::FanWallet, $this->fan))->toBe(750)
        ->and(bal(AccountType::CreatorPending, $this->c2))->toBe(0)
        // c1 keeps the oldest sale, which the recovery did not need.
        ->and(bal(AccountType::CreatorPending, $this->c1))->toBe(1_600)
        ->and(DB::table('earning_holds')->where('transaction_id', $s1)->value('refunded_at'))->toBeNull()
        ->and(DB::table('earning_holds')->where('transaction_id', $s2)->value('dispute_id'))->toBe($dispute->id)
        // The kept sale stops releasing while the dispute is open (spec).
        ->and((bool) DB::table('earning_holds')->where('transaction_id', $s1)->value('frozen'))->toBeTrue();
    Event::assertDispatchedTimes(PurchaseRefunded::class, 2);
    Event::assertDispatched(ChargebackReceived::class, fn (ChargebackReceived $e): bool => $e->clawedBackSales === [$s3, $s2]);

    $this->travel(8)->days();
    expect(app(ReleaseHeldEarnings::class)->handle())->toBe(0)
        // A clawed-back sale cannot be refunded again.
        ->and(fn () => app(RefundPurchase::class)->handle($s2, $this->admin->id, 'Fan asked'))->toThrow(WalletException::class, 'already refunded');
});

it('gives everything back exactly when the bank sides with us, then lets held sales release', function (): void {
    Event::fake([DisputeDecided::class]);
    $topUp = paidTopUp($this->fan);
    $s1 = spend($this->fan, $this->c1, 2_000);
    $s2 = spend($this->fan, $this->c2, 3_000);
    $s3 = spend($this->fan, $this->c1, 4);
    chargebackOn($topUp, 2_500);
    $dispute = Dispute::query()->sole();

    $hook = processorSends(GatewayEventType::DisputeWon, $topUp, new DisputeNotice('CB-2291'));

    expect($hook->refresh()->error)->toBeNull()
        ->and($dispute->refresh()->status)->toBe(DisputeStatus::Won)
        ->and($dispute->decided_at)->not->toBeNull()
        ->and($topUp->refresh()->status)->toBe(TopUpStatus::Paid)
        // Back to how it was before the chargeback.
        ->and(bal(AccountType::FanWallet, $this->fan))->toBe(496)
        ->and(bal(AccountType::CreatorPending, $this->c1))->toBe(1_604)
        ->and(bal(AccountType::CreatorPending, $this->c2))->toBe(2_400)
        // Owed by the processor again (an asset: debits exceed credits).
        ->and(bal(AccountType::ProcessorClearing))->toBe(-5_000)
        ->and(DB::table('earning_holds')->whereNotNull('refunded_at')->count())->toBe(0)
        ->and(DB::table('earning_holds')->where('frozen', true)->count())->toBe(0)
        // D-20: the wallet stays frozen until an admin decides.
        ->and(isFrozen($this->fan))->toBeTrue()
        ->and($this->audit->of('wallet.dispute_won')[0]['context'])->toBe(['case' => 'CB-2291', 'restored_sales' => [$s2, $s3]]);
    Event::assertDispatched(DisputeDecided::class, fn (DisputeDecided $e): bool => $e->outcome === 'won' && $e->restoredSales === [$s2, $s3]);

    // A second decision for the same case is ignored.
    processorSends(GatewayEventType::DisputeLost, $topUp, new DisputeNotice('CB-2291'));
    expect($dispute->refresh()->status)->toBe(DisputeStatus::Won);

    $this->travel(8)->days();
    expect(app(ReleaseHeldEarnings::class)->handle())->toBe(3)
        ->and($s1)->not->toBe($s2);
});

it('claws back released sales from earnings, may take a creator below zero, and returns them when won', function (): void {
    $topUp = paidTopUp($this->fan, 5_000, 0);
    $sale = spend($this->fan, $this->c1, 4_000);
    $this->travel(8)->days();
    app(ReleaseHeldEarnings::class)->handle();
    // The creator withdrew part of it (moved out of earnings).
    app(Ledger::class)->post(LedgerTransaction::make('payout.requested', 'payout:test', [
        Posting::debit(AccountRef::of(AccountType::CreatorEarnings, $this->c1), Money::of(3_000, 'USD')),
        Posting::credit(AccountRef::of(AccountType::PayoutClearing, $this->c1), Money::of(3_000, 'USD')),
    ]));

    chargebackOn($topUp);

    expect(Dispute::query()->sole()->recovery)->toMatchArray(['wallet_minor' => 5_000, 'unspent_minor' => 1_000, 'clawed_minor' => 4_000, 'sales' => [$sale]])
        // 200 left after the withdrawal − the 3,200 share.
        ->and(bal(AccountType::CreatorEarnings, $this->c1))->toBe(-3_000)
        ->and(bal(AccountType::FanWallet, $this->fan))->toBe(0);

    processorSends(GatewayEventType::DisputeWon, $topUp, new DisputeNotice('CB-2291'));

    expect(bal(AccountType::CreatorEarnings, $this->c1))->toBe(200)
        ->and(bal(AccountType::FanWallet, $this->fan))->toBe(1_000);
});

it('restores only the sales the wallet still covers when won', function (): void {
    $topUp = paidTopUp($this->fan, 5_000, 0);
    $s1 = spend($this->fan, $this->c1, 3_000);
    $s2 = spend($this->fan, $this->c2, 1_500);
    $s3 = spend($this->fan, $this->c1, 500);
    // $45 disputed: all three sales come back (5,000), 4,500 is taken, 500 stays in the wallet.
    chargebackOn($topUp, 4_500);
    expect(Dispute::query()->sole()->recovery['sales'])->toBe([$s3, $s2, $s1]);
    // Since then, the fan spent those 500 credits (an admin lifted the freeze, say).
    app(Ledger::class)->post(LedgerTransaction::make('test.spend', 'spent:'.Str::ulid(), [
        Posting::debit(AccountRef::of(AccountType::FanWallet, $this->fan), Money::credits(500)),
        Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), Money::credits(500)),
    ]));

    processorSends(GatewayEventType::DisputeWon, $topUp, new DisputeNotice('CB-2291'));

    // Oldest first: 4,500 back pays for the 3,000 and 1,500 sales; nothing is left for the newest.
    expect(bal(AccountType::FanWallet, $this->fan))->toBe(0)
        ->and(DB::table('earning_holds')->whereNotNull('refunded_at')->pluck('transaction_id')->all())->toBe([$s3]);
});

it('leaves a sale clawed back when it is no longer linked to the dispute', function (): void {
    $topUp = paidTopUp($this->fan, 1_000, 0);
    $sale = spend($this->fan, $this->c1, 1_000);
    chargebackOn($topUp);
    DB::table('earning_holds')->where('transaction_id', $sale)->update(['dispute_id' => null]);

    processorSends(GatewayEventType::DisputeWon, $topUp, new DisputeNotice('CB-2291'));

    expect(bal(AccountType::FanWallet, $this->fan))->toBe(1_000)
        ->and(DB::table('earning_holds')->where('transaction_id', $sale)->value('refunded_at'))->not->toBeNull();
});

it('writes off what cannot be recovered when another chargeback already took the credits', function (): void {
    $first = paidTopUp($this->fan, 1_000, 0);
    spend($this->fan, $this->c1, 1_000);
    $second = paidTopUp($this->fan, 1_000, 0);

    // The older top-up's chargeback takes the newer top-up's unspent credits.
    chargebackOn($first, null, 'CB-1');
    chargebackOn($second, null, 'CB-2');

    $dispute = Dispute::query()->where('gateway_case_ref', 'CB-2')->sole();
    expect($dispute->recovery)->toMatchArray(['wallet_minor' => 0, 'unspent_minor' => 0, 'clawed_minor' => 0, 'written_off_minor' => 1_000])
        ->and(bal(AccountType::PlatformRevenue))->toBe(200 - 1_000);

    // Won: the write-off is reversed too.
    processorSends(GatewayEventType::DisputeWon, $second, new DisputeNotice('CB-2'));
    expect(bal(AccountType::PlatformRevenue))->toBe(200);
});

it('keeps the money with the card holder when the bank sides with them', function (): void {
    Event::fake([DisputeDecided::class]);
    $topUp = paidTopUp($this->fan);
    chargebackOn($topUp);

    processorSends(GatewayEventType::DisputeLost, $topUp, new DisputeNotice('CB-2291'));

    expect(Dispute::query()->sole()->status)->toBe(DisputeStatus::Lost)
        ->and(bal(AccountType::FanWallet, $this->fan))->toBe(0)
        ->and($this->audit->has('wallet.dispute_lost'))->toBeTrue();
    Event::assertDispatched(DisputeDecided::class, fn (DisputeDecided $e): bool => $e->outcome === 'lost' && $e->restoredSales === []);
});

it('keeps sales frozen while another dispute of the same fan is open', function (): void {
    $a = paidTopUp($this->fan, 1_000, 0);
    $b = paidTopUp($this->fan, 1_000, 0);
    $sale = spend($this->fan, $this->c1, 500);
    chargebackOn($a, null, 'CB-A');
    chargebackOn($b, null, 'CB-B');

    processorSends(GatewayEventType::DisputeLost, $a, new DisputeNotice('CB-A'));
    $frozen = fn (): bool => DB::table('earning_holds')->where('frozen', true)->exists();
    expect($frozen())->toBeTrue();

    processorSends(GatewayEventType::DisputeLost, $b, new DisputeNotice('CB-B'));
    expect($frozen())->toBeFalse()
        ->and($sale)->toBeString();
});

it('stores events it cannot act on, with the reason', function (Closure $send, string $error): void {
    Log::spy();
    $topUp = paidTopUp($this->fan);

    $hook = $send($topUp);

    expect($hook->refresh()->error)->toContain($error)
        ->and($hook->processed_at)->not->toBeNull()
        ->and(Dispute::query()->count())->toBe(0)
        ->and(bal(AccountType::FanWallet, $this->fan))->toBe(5_500);
})->with([
    'no case reference' => [fn (TopUp $t): GatewayWebhook => processorSends(GatewayEventType::Chargeback, $t), 'without a case reference'],
    'unknown top-up' => [fn (TopUp $t): GatewayWebhook => chargebackOn((new TopUp)->forceFill(['gateway_ref' => 'fake_co_unknown', 'amount_minor' => 500, 'currency' => 'USD'])), 'No paid top-up'],
    'top-up not paid' => [fn (TopUp $t): GatewayWebhook => chargebackOn(TopUp::query()->whereKey(paidTopUp(anAccount())->id)->tap(fn ($q) => $q->update(['status' => 'failed']))->sole()), 'No paid top-up'],
    'other currency' => [fn (TopUp $t): GatewayWebhook => processorSends(GatewayEventType::Chargeback, $t, new DisputeNotice('CB-9'), 5_000, 'KES'), 'does not match'],
    'nothing disputed' => [fn (TopUp $t): GatewayWebhook => chargebackOn($t, 0), 'does not match'],
    'more than paid' => [fn (TopUp $t): GatewayWebhook => chargebackOn($t, 5_001), 'does not match'],
    'decision for an unknown case' => [fn (TopUp $t): GatewayWebhook => processorSends(GatewayEventType::DisputeWon, $t, new DisputeNotice('CB-404')), 'No dispute with this case'],
    'decision without a case' => [fn (TopUp $t): GatewayWebhook => processorSends(GatewayEventType::DisputeLost, $t), 'No dispute with this case'],
    'a card refund we did not ask for' => [fn (TopUp $t): GatewayWebhook => processorSends(GatewayEventType::Refund, $t), 'Needs a person'],
]);

it('gives a chargeback without a deadline seven days, and keeps unknown reasons as other', function (): void {
    $topUp = paidTopUp($this->fan);
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $hook = $gateway->signedWebhook(GatewayEventType::Chargeback, (string) $topUp->gateway_ref, Money::of(5_000, 'USD'), 'evt_x', null, null, new DisputeNotice('CB-7', 'weird', null, 'maybe'));
    app(ReceiveGatewayWebhook::class)->handle('fake', $hook['payload'], $hook['headers']);

    $dispute = Dispute::query()->sole();
    expect($dispute->respond_by->toDateString())->toBe('2026-12-02')
        ->and($dispute->reason)->toBe('other')
        ->and($dispute->reasonSentence())->toBe('no reason given')
        ->and($dispute->three_ds)->toBeNull();

    // Applying a processed webhook again is a no-op.
    app(ApplyGatewayEvent::class)->handle(GatewayWebhook::query()->where('event_id', 'evt_x')->sole()->id);
    expect(Dispute::query()->count())->toBe(1);
});

it('gathers the evidence from our records', function (array $topUpExtra, ?string $threeDs, int $signIns, bool $spend, array $expected): void {
    $this->signIns = $signIns;
    $topUp = paidTopUp($this->fan, 5_000, 500, $topUpExtra);
    if ($spend) {
        spend($this->fan, $this->c1, 4_750);
    }
    chargebackOn($topUp, null, 'CB-2291', 'not_recognised', null, $threeDs);

    expect(app(DisputeEvidence::class)->lines(Dispute::query()->sole()))->toBe($expected);
})->with([
    'strong case' => [[], 'passed', 14, true, [
        ['text' => '3-D Secure authentication passed', 'tone' => 'ok'],
        ['text' => 'Same IP address as 14 earlier sign-ins', 'tone' => 'ok'],
        ['text' => 'Paid from a device on the account for more than 7 days', 'tone' => 'ok'],
        ['text' => '4,750 credits spent after the top-up (unlock log)', 'tone' => 'ok'],
        ['text' => 'Statement descriptor FF*DIGITAL shown at checkout', 'tone' => 'ok'],
    ]],
    'weak case' => [['known_device' => false], 'failed', 0, false, [
        ['text' => '3-D Secure authentication failed', 'tone' => 'danger'],
        ['text' => 'IP address not seen at any earlier sign-in', 'tone' => 'warning'],
        ['text' => 'Paid from a device added in the last 7 days', 'tone' => 'warning'],
        ['text' => 'No credits spent after the top-up', 'tone' => 'muted'],
        ['text' => 'Statement descriptor FF*DIGITAL shown at checkout', 'tone' => 'ok'],
    ]],
    'little recorded' => [['ip' => null, 'known_device' => null], 'not_used', 0, false, [
        ['text' => '3-D Secure was not used for this payment', 'tone' => 'warning'],
        ['text' => 'No IP address recorded for this top-up', 'tone' => 'muted'],
        ['text' => 'No device recorded for this top-up', 'tone' => 'muted'],
        ['text' => 'No credits spent after the top-up', 'tone' => 'muted'],
        ['text' => 'Statement descriptor FF*DIGITAL shown at checkout', 'tone' => 'ok'],
    ]],
    'one sign-in, 3-D Secure not reported' => [[], null, 1, false, [
        ['text' => 'The processor did not report 3-D Secure', 'tone' => 'muted'],
        ['text' => 'Same IP address as 1 earlier sign-in', 'tone' => 'ok'],
        ['text' => 'Paid from a device on the account for more than 7 days', 'tone' => 'ok'],
        ['text' => 'No credits spent after the top-up', 'tone' => 'muted'],
        ['text' => 'Statement descriptor FF*DIGITAL shown at checkout', 'tone' => 'ok'],
    ]],
    'crypto' => [['method' => TopUpMethod::Crypto], 'passed', 1, false, [
        ['text' => 'Paid in USDT: no card was used', 'tone' => 'muted'],
        ['text' => 'Same IP address as 1 earlier sign-in', 'tone' => 'ok'],
        ['text' => 'Paid from a device on the account for more than 7 days', 'tone' => 'ok'],
        ['text' => 'No credits spent after the top-up', 'tone' => 'muted'],
        ['text' => 'Statement descriptor FF*DIGITAL shown at checkout', 'tone' => 'ok'],
    ]],
]);

it('sends the evidence to the processor, once, before the deadline', function (): void {
    $topUp = paidTopUp($this->fan);
    chargebackOn($topUp);
    $dispute = Dispute::query()->sole();
    $respond = app(RespondToDispute::class);

    $sent = $respond->submitEvidence($dispute->id, $this->admin->id);

    expect($sent->status)->toBe(DisputeStatus::EvidenceSent)
        ->and($sent->evidence)->toHaveCount(5)
        ->and($sent->evidence_sent_by)->toBe($this->admin->id)
        ->and($sent->evidence[0])->toBe(['text' => '3-D Secure authentication passed', 'tone' => 'ok'])
        ->and(disputeGateway()->answered['CB-2291'])->toBe(['action' => 'evidence', 'evidence' => array_column($sent->evidence, 'text')])
        ->and($this->audit->of('wallet.dispute_evidence_sent')[0]['context']['case'])->toBe('CB-2291')
        ->and(fn () => $respond->submitEvidence($dispute->id, $this->admin->id))->toThrow(WalletException::class, 'already sent')
        ->and(fn () => $respond->accept($dispute->id, $this->admin->id, 'Too late'))->toThrow(WalletException::class, 'already sent');
});

it('refuses to answer a dispute that is closed, past due, unknown, your own, or when the processor fails', function (): void {
    $topUp = paidTopUp($this->fan);
    chargebackOn($topUp, null, 'CB-1', 'fraudulent', CarbonImmutable::now()->addHour());
    $dispute = Dispute::query()->sole();
    $respond = app(RespondToDispute::class);
    $moderator = signedInStaff('moderator');
    $fanAdmin = signedInStaff();
    DB::table('disputes')->where('id', $dispute->id)->update(['fan_id' => $fanAdmin->id]);

    expect(fn () => $respond->submitEvidence('nope', $this->admin->id))->toThrow(WalletException::class, 'no dispute')
        ->and(fn () => $respond->submitEvidence($dispute->id, $moderator->id))->toThrow(WalletException::class, 'Your role')
        ->and(fn () => $respond->submitEvidence($dispute->id, $fanAdmin->id))->toThrow(WalletException::class, 'your own account');
    DB::table('disputes')->where('id', $dispute->id)->update(['fan_id' => $this->fan]);

    disputeGateway()->willFail('Timed out.');
    expect(fn () => $respond->submitEvidence($dispute->id, $this->admin->id))->toThrow(WalletException::class, 'The processor did not take it: Timed out.')
        ->and($dispute->refresh()->status)->toBe(DisputeStatus::Open);

    $this->travel(2)->hours();
    expect(fn () => $respond->submitEvidence($dispute->id, $this->admin->id))->toThrow(WalletException::class, 'deadline');

    processorSends(GatewayEventType::DisputeLost, $topUp, new DisputeNotice('CB-1'));
    expect(fn () => $respond->accept($dispute->id, $this->admin->id, 'Late'))->toThrow(WalletException::class, 'closed (lost)');
});

it('accepts a chargeback with a note, and only then can the freeze be lifted', function (): void {
    Event::fake([DisputeDecided::class]);
    $topUp = paidTopUp($this->fan);
    chargebackOn($topUp);
    $dispute = Dispute::query()->sole();
    $respond = app(RespondToDispute::class);

    expect(fn () => $respond->liftFreeze($this->fan, $this->admin->id, 'Looks fine'))->toThrow(WalletException::class, 'still open')
        ->and(fn () => $respond->accept($dispute->id, $this->admin->id, ' '))->toThrow(WalletException::class, 'note');

    disputeGateway()->willFail();
    expect(fn () => $respond->accept($dispute->id, $this->admin->id, 'Fan was charged twice'))->toThrow(WalletException::class, 'did not take it');

    $accepted = $respond->accept($dispute->id, $this->admin->id, 'Fan was charged twice');

    expect($accepted->status)->toBe(DisputeStatus::Accepted)
        ->and($accepted->accepted_by)->toBe($this->admin->id)
        ->and(disputeGateway()->answered['CB-2291']['action'])->toBe('accept')
        ->and($this->audit->of('wallet.dispute_accepted')[0]['context'])->toBe(['case' => 'CB-2291', 'note' => 'Fan was charged twice'])
        ->and(isFrozen($this->fan))->toBeTrue();
    Event::assertDispatched(DisputeDecided::class, fn (DisputeDecided $e): bool => $e->outcome === 'accepted');

    // A later "lost" from the processor leaves the accepted dispute as it is.
    processorSends(GatewayEventType::DisputeLost, $topUp, new DisputeNotice('CB-2291'));
    expect($accepted->refresh()->status)->toBe(DisputeStatus::Accepted);

    $respond->liftFreeze($this->fan, $this->admin->id, 'Repeat customer, card holder confirmed');

    expect(isFrozen($this->fan))->toBeFalse()
        ->and($this->audit->of('wallet.freeze_lifted')[0])->toMatchArray(['actor' => $this->admin->id, 'subject_id' => $this->fan])
        ->and(fn () => $respond->liftFreeze($this->fan, $this->admin->id, 'Again'))->toThrow(WalletException::class, 'not frozen')
        ->and(fn () => $respond->liftFreeze($this->fan, signedInStaff('moderator')->id, 'Again'))->toThrow(WalletException::class, 'Your role');

    // The fan can top up again.
    expect(app(StartTopUp::class)->handle($this->fan, 0, TopUpMethod::Card, 'after-lift', '203.0.113.7')->status)->toBe(TopUpStatus::Pending);
});

it('records where a top-up was started from, for evidence', function (): void {
    $start = app(StartTopUp::class);

    $known = $start->handle($this->fan, 0, TopUpMethod::Card, 'k1', '203.0.113.9', true);
    $odd = $start->handle($this->fan, 0, TopUpMethod::Card, 'k2', 'not-an-ip');

    expect($known->ip)->toBe('203.0.113.9')
        ->and($known->known_device)->toBeTrue()
        ->and($odd->ip)->toBeNull()
        ->and($odd->known_device)->toBeNull();
});

it('measures the chargeback rate in basis points of paid top-ups', function (): void {
    $stats = app(MoneyStatsService::class);
    expect($stats->chargebackRate(CarbonImmutable::now()->subDays(30)))->toBe(['bps' => 0, 'chargebacks' => 0, 'topups' => 0]);

    $topUps = [];
    for ($i = 0; $i < 3; $i++) {
        $topUps[] = paidTopUp(anAccount(), 500, 0);
    }
    chargebackOn($topUps[0]);

    expect($stats->chargebackRate(CarbonImmutable::now()->subDays(30)))->toBe(['bps' => 3_333, 'chargebacks' => 1, 'topups' => 3]);
});
