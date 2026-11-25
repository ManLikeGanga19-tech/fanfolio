<?php

declare(strict_types=1);

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Identity\Contracts\Accounts;
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
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\Purchases;
use App\Modules\Wallet\Contracts\TopUpGateway;
use App\Modules\Wallet\Enums\DisputeStatus;
use App\Modules\Wallet\Filament\Admin\Disputes;
use App\Modules\Wallet\Filament\Admin\PayoutReviews;
use App\Modules\Wallet\Gateways\Fake\FakeDisputeGateway;
use App\Modules\Wallet\Gateways\Fake\FakeTopUpGateway;
use App\Modules\Wallet\Models\Dispute;
use App\Modules\Wallet\Models\Payout;
use App\Modules\Wallet\Models\TopUp;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\RecordingAuditLog;

// M3 PR 6: canvas board AdminDisputes (plan M8a use case 37), the refund screen below it, the
// chargeback check on Payout reviews and the local stand-in for the processor's dispute desk.

afterEach(fn () => expectLedgerBalanced());

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'UTC'));
    Filament::setCurrentPanel('admin');
    $this->audit = RecordingAuditLog::swap();
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
});

/** A paid top-up and its chargeback, through the processor's signed webhooks. */
function boardDispute(string $fan, string $case = 'CB-2291', int $cents = 5_000, ?CarbonImmutable $respondBy = null, ?Closure $before = null): Dispute
{
    /** @var FakeTopUpGateway $gateway */
    $gateway = app(TopUpGateway::class);
    $topUp = new TopUp;
    $topUp->forceFill([
        'fan_id' => $fan, 'idempotency_key' => (string) Str::ulid(), 'gateway' => 'fake', 'gateway_ref' => 'fake_co_'.Str::ulid(),
        'method' => TopUpMethod::Card, 'amount_minor' => $cents, 'currency' => 'USD', 'credits' => $cents, 'bonus_credits' => 0,
        'ip' => '203.0.113.7', 'known_device' => true,
    ])->save();

    foreach ([[GatewayEventType::PaymentSucceeded, null], [GatewayEventType::Chargeback, new DisputeNotice($case, 'not_recognised', $respondBy ?? CarbonImmutable::now()->addDays(10), 'passed')]] as [$type, $notice]) {
        if ($type === GatewayEventType::Chargeback && $before instanceof Closure) {
            $before();
        }
        $hook = $gateway->signedWebhook($type, (string) $topUp->gateway_ref, Money::of($cents, 'USD'), null, null, 'Visa ····9012', $notice);
        test()->call('POST', '/webhooks/fake', [], [], [], ['HTTP_X_FAKE_SIGNATURE' => $hook['headers']['x-fake-signature'], 'CONTENT_TYPE' => 'application/json'], $hook['payload'])->assertOk();
    }

    return Dispute::query()->where('gateway_case_ref', $case)->sole();
}

function boardSale(string $fan, string $creator, int $credits): string
{
    return app(Purchases::class)->charge(new PurchaseRequest($fan, $creator, Money::credits($credits), PurchaseKind::Unlock, (string) Str::ulid(), 'post', (string) Str::ulid()))->transactionId;
}

it('is for admins only, and sits between Payout reviews and the Creators directory', function (): void {
    $admin = signedInStaff('admin');
    signedInStaff('moderator');
    $this->get('/admin/disputes')->assertForbidden();

    actAsUserId($admin->id);
    $this->get('/admin/disputes')->assertOk()
        ->assertSee('Disputes &amp; chargebacks', false)
        ->assertSee('Answer before the card network deadline. Evidence is gathered automatically from logs.');
});

it('shows the canvas tiles and the open cases, soonest deadline first', function (): void {
    signedInStaff();
    $later = boardDispute(anAccount(), 'CB-2287', 1_000, CarbonImmutable::now()->addDays(7));
    $soon = boardDispute(anAccount(), 'CB-2291', 5_000, CarbonImmutable::now()->addDay());
    $past = boardDispute(anAccount(), 'CB-2280', 2_500, CarbonImmutable::now()->addHour());
    $this->travel(2)->hours();
    // Decided ones: one won, one lost in the last 90 days.
    $won = boardDispute(anAccount(), 'CB-2001', 500);
    Dispute::query()->whereKey($won->id)->update(['status' => 'won', 'decided_at' => now()]);
    $lost = boardDispute(anAccount(), 'CB-2002', 500);
    Dispute::query()->whereKey($lost->id)->update(['status' => 'lost', 'decided_at' => now()->subDays(10)]);
    DB::table('wallet_freezes')->where('fan_id', $lost->fan_id)->update(['lifted_at' => now()]);

    $page = Livewire::test(Disputes::class)
        ->assertViewHas('tiles', ['rate' => ['bps' => 10_000, 'chargebacks' => 5, 'topups' => 5], 'open' => 3, 'urgent' => 2, 'won' => 1, 'decided' => 2, 'frozen' => 4])
        ->assertSee(['100.00%', '5 of 5 top-ups', '2 due in 48h', '50%', '1 of 2', 'Wallets locked during dispute'])
        ->assertSee(['CB-2280', 'Past due', 'CB-2291', '26 Nov', 'CB-2287', 'Not recognised', '$50', '$10'])
        ->assertSet('selected', $past->id);

    expect($page->viewData('queue')->pluck('id')->all())->toBe([$past->id, $soon->id, $later->id]);

    $page->call('showTab', 'decided')
        ->assertSee(['CB-2001', 'Won', 'CB-2002', 'Lost'])
        ->assertDontSee('CB-2291');
    expect($page->viewData('queue')->pluck('id')->all())->toBe([$won->id, $lost->id]);

    $page->call('showTab', 'anything')->assertSet('tab', 'open');
});

it('starts empty', function (): void {
    signedInStaff();

    Livewire::test(Disputes::class)
        ->assertSee(['0.00%', 'None due in 48h', 'No decisions yet', 'No open disputes.', 'Choose a case to see its evidence.'])
        ->call('showTab', 'decided')->assertSee('No decided disputes yet.');
});

it('shows a case with its evidence and what was taken back', function (): void {
    signedInStaff();
    $fan = anAccount();
    $creator = anAccount();
    $dispute = boardDispute($fan, before: function () use ($fan, $creator): void {
        boardSale($fan, $creator, 4_750);
    });

    Livewire::test(Disputes::class)
        ->assertSee(['CB-2291 · $50 top-up', 'Visa ····9012', '5,000 credits', 'reason: cardholder doesn&#039;t recognise it'], false)
        ->assertSee(['Evidence to send', '3-D Secure authentication passed', '4,750 credits spent after the top-up (unlock log)', 'Statement descriptor FF*DIGITAL shown at checkout'])
        ->assertSee(['Taken back when it arrived', '5,000 credits from the wallet', 'To cover it, 1 sale was reversed first (4,750 credits, newest first, D-20)'])
        ->assertSee(['Submit evidence', 'Accept &amp; refund', 'frozen', 'The freeze can be lifted once no dispute of this fan is open.'], false)
        ->assertSet('selected', $dispute->id);
});

it('submits the evidence from the board', function (): void {
    signedInStaff();
    $dispute = boardDispute(anAccount());

    Livewire::test(Disputes::class)
        ->callAction('submitEvidence')
        ->assertNotified('Evidence sent. The bank will decide.')
        ->assertSee(['Evidence sent', 'Waiting for the card network']);

    /** @var FakeDisputeGateway $gateway */
    $gateway = app(DisputeGateway::class);
    expect($dispute->refresh()->status)->toBe(DisputeStatus::EvidenceSent)
        ->and($gateway->answered)->toHaveKey('CB-2291');
});

it('accepts with a note, then lifts the freeze, from the board', function (): void {
    signedInStaff();
    $dispute = boardDispute(anAccount());

    Livewire::test(Disputes::class)
        ->callAction('accept', ['note' => ''])
        ->assertHasActionErrors(['note' => 'required']);

    Livewire::test(Disputes::class)
        ->callAction('accept', ['note' => 'Charged twice by mistake'])
        ->assertNotified('Accepted. The card holder keeps the refund.')
        ->assertSee(['Accepted on 25 Nov', 'Lift wallet freeze'])
        ->callAction('liftFreeze', ['note' => 'Card holder confirmed'])
        ->assertNotified('Freeze lifted. The fan can use their wallet again.')
        ->assertSee('wallet is not frozen.');

    expect($dispute->refresh()->status)->toBe(DisputeStatus::Accepted)
        ->and($this->audit->has('wallet.freeze_lifted'))->toBeTrue();
});

it('shows the processor failing instead of pretending it worked', function (): void {
    signedInStaff();
    $dispute = boardDispute(anAccount());
    /** @var FakeDisputeGateway $gateway */
    $gateway = app(DisputeGateway::class);
    $gateway->willFail('Busy.');

    Livewire::test(Disputes::class)
        ->callAction('submitEvidence')
        ->assertNotified('The processor did not take it: Busy. Try again in a few minutes.')
        ->assertHasErrors('gateway');

    expect($dispute->refresh()->status)->toBe(DisputeStatus::Open);
});

it('says what happened to a won dispute', function (): void {
    signedInStaff();
    $dispute = boardDispute(anAccount());
    Dispute::query()->whereKey($dispute->id)->update(['status' => 'won', 'decided_at' => now(), 'evidence' => json_encode([['text' => 'Paid from a device added in the last 7 days', 'tone' => 'warning']]), 'evidence_sent_at' => now()]);

    Livewire::test(Disputes::class)->set('tab', 'decided')
        ->assertSee(['Evidence sent', 'Paid from a device added in the last 7 days', 'Won on 25 Nov', 'were returned'])
        ->assertSeeHtml('<span class="sr-only">Weak: </span>Paid from a device added in the last 7 days')
        ->assertDontSee('Submit evidence');

    Dispute::query()->whereKey($dispute->id)->update(['status' => 'lost', 'evidence' => null]);
    Livewire::test(Disputes::class)->set('tab', 'decided')->assertSee('No evidence sent');
});

it('finds a fan\'s sales and refunds one to their credits', function (): void {
    $admin = signedInStaff();
    $fan = anAccount();
    $creator = anAccount();
    $name = app(Accounts::class)->username($fan);
    $dispute = boardDispute($fan, before: function () use ($fan, $creator): void {
        boardSale($fan, $creator, 4_900);
    });
    app(Ledger::class)->post(LedgerTransaction::make('topup', 'fund:'.Str::ulid(), [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), Money::credits(300)),
        Posting::credit(AccountRef::of(AccountType::FanWallet, $fan), Money::credits(300)),
    ]));
    DB::table('wallet_freezes')->where('fan_id', $fan)->update(['lifted_at' => now()]);
    $sale = boardSale($fan, $creator, 300);

    $page = Livewire::test(Disputes::class)
        ->set('refundFan', '@nobody-here')->call('$refresh')->assertSee('No fan with that username.')
        ->set('refundFan', '@'.$name)->call('$refresh')
        ->assertSee(['300 credits', 'On 7-day hold', '4,900 credits', 'Charged back', 'Refund']);

    $page->callAction('refundSale', ['reason' => 'Bought the wrong post'], ['sale' => $sale])
        ->assertNotified('Refunded to the fan\'s credits.')
        ->assertSee('Refunded');

    expect(app(Ledger::class)->balance(AccountRef::of(AccountType::FanWallet, $fan))->amountMinor)->toBe(300)
        ->and($this->audit->of('wallet.refund')[0]['actor'])->toBe($admin->id)
        ->and($dispute->id)->toBeString();

    Livewire::test(Disputes::class)->set('refundFan', app(Accounts::class)->username(anAccount()))->call('$refresh')->assertSee('has not bought anything yet.');
});

it('adds the chargeback share of the creator\'s sales to the payout checks', function (): void {
    signedInStaff();
    $creator = anAccount();
    $fan = anAccount();
    boardDispute($fan, before: function () use ($fan, $creator): void {
        boardSale($fan, $creator, 5_000);
    });
    // Three more sales by others: one of four was charged back.
    foreach (range(1, 3) as $i) {
        $other = anAccount();
        app(Ledger::class)->post(LedgerTransaction::make('topup', 'fund:'.Str::ulid(), [
            Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), Money::credits(100)),
            Posting::credit(AccountRef::of(AccountType::FanWallet, $other), Money::credits(100)),
        ]));
        boardSale($other, $creator, 100);
    }
    $payout = new Payout;
    $payout->forceFill(['creator_id' => $creator, 'idempotency_key' => 'k', 'payout_method_id' => (string) Str::ulid(), 'rail' => 'bank', 'display_hint' => 'Test Bank ····2222', 'amount_minor' => 2_000, 'currency' => 'USD', 'status' => 'review', 'flags' => [], 'requested_at' => now()])->save();

    Livewire::test(PayoutReviews::class)
        ->assertViewHas('checks', fn (array $checks): bool => $checks[2] === ['text' => 'Chargebacks on their sales: 25.00% (90 days)', 'tone' => 'danger']);

    expect(Disputes::rateTone(74))->toBe('ok')
        ->and(Disputes::rateTone(75))->toBe('warning')
        ->and(Disputes::percent(42))->toBe('0.42%');
});

it('stands in for the processor\'s dispute desk locally', function (): void {
    signedInStaff();
    $fan = anAccount();
    $dispute = boardDispute($fan, 'CB-OLD');
    $topUp = new TopUp;
    $topUp->forceFill([
        'fan_id' => anAccount(), 'idempotency_key' => 'k', 'gateway' => 'fake', 'gateway_ref' => 'fake_co_'.Str::ulid(),
        'method' => TopUpMethod::Card, 'amount_minor' => 1_000, 'currency' => 'USD', 'credits' => 1_000, 'bonus_credits' => 0, 'payment_hint' => 'Visa ····4242',
    ])->save();
    // Paid (the desk is anyone's, local only; checkout is the fan's own).
    $topUp->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    app(Ledger::class)->post(LedgerTransaction::make('topup', 'topup:'.$topUp->id, [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), Money::credits(1_000)),
        Posting::credit(AccountRef::of(AccountType::FanWallet, $topUp->fan_id), Money::credits(1_000)),
    ]));

    $this->get('/_fake/disputes')->assertOk()
        ->assertSee(['Disputes desk', 'Test processor', $topUp->reference(), 'File chargeback', 'CB-OLD', 'Bank sides with FanFolio']);

    $this->post('/_fake/disputes/topups/'.$topUp->id, ['reason' => 'fraudulent', 'three_ds' => 'not_used', 'days' => 5])
        ->assertRedirect(route('fake.disputes'))
        ->assertSessionHas('status');
    $filed = Dispute::query()->where('topup_id', $topUp->id)->sole();
    expect($filed->reason)->toBe('fraudulent')
        ->and($filed->three_ds)->toBe('not_used')
        ->and($filed->respond_by->toDateString())->toBe('2026-11-30');

    $this->post('/_fake/disputes/'.$filed->id, ['outcome' => 'won'])->assertSessionHas('status', 'The bank decided '.$filed->gateway_case_ref.' for FanFolio.');
    $this->post('/_fake/disputes/'.$dispute->id, ['outcome' => 'lost'])->assertSessionHas('status', 'The bank decided CB-OLD for the card holder.');

    expect($filed->refresh()->status)->toBe(DisputeStatus::Won)
        ->and($dispute->refresh()->status)->toBe(DisputeStatus::Lost);

    $this->post('/_fake/disputes/topups/'.$topUp->id, ['reason' => 'bogus', 'three_ds' => 'passed', 'days' => 5])->assertSessionHasErrors('reason');
    $this->get('/_fake/disputes')->assertSee(['No open cases.']);
});

it('tells a frozen fan why on the wallet page', function (): void {
    $fan = signedInFan();
    $this->get('/wallet')->assertDontSee('on hold while we look into a payment dispute');

    DB::table('wallet_freezes')->insert(['fan_id' => $fan->id, 'reason' => 'chargeback', 'frozen_at' => now()]);

    $this->get('/wallet')->assertSee('Your wallet is on hold while we look into a payment dispute.')
        ->assertDontSee('Continue to secure checkout');
});
