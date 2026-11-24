<?php

declare(strict_types=1);

use App\Modules\Creators\Contracts\CreatorEligibility;
use App\Modules\Wallet\Actions\RefundPurchase;
use App\Modules\Wallet\Actions\ReleaseHeldEarnings;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Data\PurchaseKind;
use App\Modules\Wallet\Contracts\Data\PurchaseRequest;
use App\Modules\Wallet\Contracts\Data\PurchaseResult;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Modules\Wallet\Contracts\Exceptions\PurchaseRefused;
use App\Modules\Wallet\Contracts\Ledger;
use App\Modules\Wallet\Contracts\Purchases;
use App\Modules\Wallet\Events\PurchaseCompleted;
use App\Modules\Wallet\Events\PurchaseRefunded;
use App\Modules\Wallet\Exceptions\WalletException;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\RecordingAuditLog;

// M3 PR 3: the spending contract, the 7-day hold and admin refunds. Spec "Spend flow" and
// postings; brief rules 1–4 and 9; the M5 "Done when" (each sale posts fee and creator share).

afterEach(fn () => expectLedgerBalanced());

beforeEach(function (): void {
    $this->audit = RecordingAuditLog::swap();
    $this->fan = strtolower((string) Str::ulid());
    $this->creator = strtolower((string) Str::ulid());
    $this->earning = [$this->creator => true];
    // Module tests stand in for Creators; tests/Feature/Money uses real creators.
    $test = $this;
    app()->instance(CreatorEligibility::class, new readonly class($test) implements CreatorEligibility
    {
        public function __construct(private object $test) {}

        public function canPublish(string $userId): bool
        {
            return $this->canEarn($userId);
        }

        public function canEarn(string $userId): bool
        {
            return $this->test->earning[$userId] ?? false;
        }

        public function canWithdraw(string $userId): bool
        {
            return $this->canEarn($userId);
        }
    });
});

function fundWallet(string $fan, int $credits): void
{
    app(Ledger::class)->post(LedgerTransaction::make('topup', 'fund:'.Str::ulid(), [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), Money::credits($credits)),
        Posting::credit(AccountRef::of(AccountType::FanWallet, $fan), Money::credits($credits)),
    ]));
}

function buy(string $fan, string $creator, int $credits, string $key = 'k1', PurchaseKind $kind = PurchaseKind::Unlock): PurchaseResult
{
    return app(Purchases::class)->charge(new PurchaseRequest($fan, $creator, Money::credits($credits), $kind, $key, 'post', 'post-1'));
}

function balanceOf(AccountRef $account): int
{
    return app(Ledger::class)->balance($account)->amountMinor;
}

it('charges 100% to the fan, 80% to the creator held for 7 days, 20% to the platform', function (): void {
    Event::fake([PurchaseCompleted::class]);
    fundWallet($this->fan, 1000);

    $result = buy($this->fan, $this->creator, 250);

    expect($result->replayed)->toBeFalse()
        ->and($result->creatorShare->amountMinor)->toBe(200)
        ->and($result->fee->amountMinor)->toBe(50)
        ->and(balanceOf(AccountRef::of(AccountType::FanWallet, $this->fan)))->toBe(750)
        ->and(balanceOf(AccountRef::of(AccountType::CreatorPending, $this->creator)))->toBe(200)
        ->and(balanceOf(AccountRef::platform(AccountType::PlatformRevenue)))->toBe(50)
        ->and(DB::table('ledger_transactions')->where('id', $result->transactionId)->value('type'))->toBe('sale.unlock');

    $hold = DB::table('earning_holds')->sole();
    expect($hold->creator_minor)->toBe(200)
        ->and($hold->fee_minor)->toBe(50)
        ->and($hold->reference_id)->toBe('post-1')
        ->and(now()->addDays(7)->diffInSeconds($hold->release_at, true))->toBeLessThan(5)
        ->and($this->audit->of('wallet.purchase')[0]['context'])->toMatchArray(['kind' => 'unlock', 'amount_minor' => 250, 'fee_minor' => 50]);
    Event::assertDispatched(PurchaseCompleted::class, fn (PurchaseCompleted $e): bool => $e->transactionId === $result->transactionId && $e->amountMinor === 250);
});

it('gives the whole of a tiny sale to the creator when the fee rounds to nothing', function (): void {
    fundWallet($this->fan, 10);

    $result = buy($this->fan, $this->creator, 4, 'tiny', PurchaseKind::Tip);

    expect($result->fee->isZero())->toBeTrue()
        ->and(balanceOf(AccountRef::of(AccountType::CreatorPending, $this->creator)))->toBe(4)
        ->and(DB::table('ledger_entries')->where('transaction_id', $result->transactionId)->count())->toBe(2);
});

it('never charges twice for one idempotency key, and keys never collide between fans', function (): void {
    Event::fake([PurchaseCompleted::class]);
    fundWallet($this->fan, 1000);
    $other = strtolower((string) Str::ulid());
    fundWallet($other, 1000);

    $first = buy($this->fan, $this->creator, 300, 'same');
    $again = buy($this->fan, $this->creator, 300, 'same');
    buy($other, $this->creator, 300, 'same');

    expect($again->replayed)->toBeTrue()
        ->and($again->transactionId)->toBe($first->transactionId)
        ->and(balanceOf(AccountRef::of(AccountType::FanWallet, $this->fan)))->toBe(700)
        ->and(balanceOf(AccountRef::of(AccountType::FanWallet, $other)))->toBe(700)
        ->and(DB::table('earning_holds')->count())->toBe(2);
    Event::assertDispatchedTimes(PurchaseCompleted::class, 2);
});

it('refuses a fan who has too few credits, and writes nothing', function (): void {
    fundWallet($this->fan, 100);

    try {
        buy($this->fan, $this->creator, 101);
        $this->fail('Expected insufficient credits.');
    } catch (LedgerException $e) {
        expect($e->problem)->toBe(LedgerException::INSUFFICIENT);
    }

    expect(DB::table('earning_holds')->count())->toBe(0)->and(balanceOf(AccountRef::of(AccountType::FanWallet, $this->fan)))->toBe(100);
});

it('refuses before any money moves', function (Closure $setUp, Closure $purchase, string $problem): void {
    fundWallet($this->fan, 1000);
    $setUp($this);

    try {
        $purchase($this);
        $this->fail('Expected the purchase to be refused.');
    } catch (PurchaseRefused $e) {
        expect($e->problem)->toBe($problem);
    }

    expect(DB::table('earning_holds')->count())->toBe(0)->and(balanceOf(AccountRef::of(AccountType::FanWallet, $this->fan)))->toBe(1000);
})->with([
    'creator cannot earn (rule 9)' => [fn ($t): false => $t->earning[$t->creator] = false, fn ($t): PurchaseResult => buy($t->fan, $t->creator, 100), PurchaseRefused::CREATOR_UNAVAILABLE],
    'frozen wallet (D-20)' => [fn ($t) => DB::table('wallet_freezes')->insert(['fan_id' => $t->fan, 'reason' => 'chargeback', 'frozen_at' => now()]), fn ($t): PurchaseResult => buy($t->fan, $t->creator, 100), PurchaseRefused::WALLET_FROZEN],
    'paying yourself' => [fn ($t): true => $t->earning[$t->fan] = true, fn ($t): PurchaseResult => buy($t->fan, $t->fan, 100), PurchaseRefused::INVALID],
    'zero credits' => [fn ($t): null => null, fn ($t): PurchaseResult => buy($t->fan, $t->creator, 0), PurchaseRefused::INVALID],
    'another currency' => [fn ($t): null => null, fn ($t) => app(Purchases::class)->charge(new PurchaseRequest($t->fan, $t->creator, Money::of(100, 'KES'), PurchaseKind::Tip, 'k')), PurchaseRefused::INVALID],
    'no key' => [fn ($t): null => null, fn ($t): PurchaseResult => buy($t->fan, $t->creator, 100, ' '), PurchaseRefused::INVALID],
    'key too long' => [fn ($t): null => null, fn ($t): PurchaseResult => buy($t->fan, $t->creator, 100, str_repeat('k', 101)), PurchaseRefused::INVALID],
]);

it('releases a sale to earnings after 7 days, once, and skips frozen and refunded sales', function (): void {
    fundWallet($this->fan, 1000);
    buy($this->fan, $this->creator, 100, 'a');
    buy($this->fan, $this->creator, 100, 'b');
    $frozen = buy($this->fan, $this->creator, 100, 'c');
    DB::table('earning_holds')->where('transaction_id', $frozen->transactionId)->update(['frozen' => true]);

    $this->travel(6)->days();
    expect(app(ReleaseHeldEarnings::class)->handle())->toBe(0);

    $this->travel(1)->days();
    $this->travel(1)->minutes();
    $this->artisan('wallet:release-earnings')->expectsOutput('Released 2 sale(s).')->assertSuccessful();
    $this->artisan('wallet:release-earnings')->expectsOutput('Released 0 sale(s).')->assertSuccessful();

    expect(balanceOf(AccountRef::of(AccountType::CreatorEarnings, $this->creator)))->toBe(160)
        ->and(balanceOf(AccountRef::of(AccountType::CreatorPending, $this->creator)))->toBe(80)
        ->and(DB::table('earning_holds')->whereNotNull('released_at')->count())->toBe(2);
});

it('lets an admin refund a sale back to credits, once, with a reason', function (): void {
    Event::fake([PurchaseRefunded::class]);
    $admin = signedInStaff();
    fundWallet($this->fan, 1000);
    $sale = buy($this->fan, $this->creator, 250);

    app(RefundPurchase::class)->handle($sale->transactionId, $admin->id, 'Post was not as described');

    expect(balanceOf(AccountRef::of(AccountType::FanWallet, $this->fan)))->toBe(1000)
        ->and(balanceOf(AccountRef::of(AccountType::CreatorPending, $this->creator)))->toBe(0)
        ->and(balanceOf(AccountRef::platform(AccountType::PlatformRevenue)))->toBe(0)
        ->and(DB::table('earning_holds')->value('refunded_at'))->not->toBeNull()
        ->and($this->audit->of('wallet.refund')[0])->toMatchArray(['actor' => $admin->id])
        ->and($this->audit->of('wallet.refund')[0]['context'])->toMatchArray(['reason' => 'Post was not as described', 'from' => 'creator_pending']);
    Event::assertDispatched(PurchaseRefunded::class, fn (PurchaseRefunded $e): bool => $e->referenceId === 'post-1');

    expect(fn () => app(RefundPurchase::class)->handle($sale->transactionId, $admin->id, 'Again'))->toThrow(WalletException::class, 'already refunded');

    // A refunded sale is never released.
    $this->travel(8)->days();
    expect(app(ReleaseHeldEarnings::class)->handle())->toBe(0);
});

it('takes a released and withdrawn share back from earnings, below zero if need be (D-20)', function (): void {
    $admin = signedInStaff();
    fundWallet($this->fan, 1000);
    $sale = buy($this->fan, $this->creator, 4, 'tiny');
    $this->travel(8)->days();
    app(ReleaseHeldEarnings::class)->handle();
    // The creator withdraws the 4 credits (a payout, M3 PR 4).
    app(Ledger::class)->post(LedgerTransaction::make('payout', 'payout:x', [
        Posting::debit(AccountRef::of(AccountType::CreatorEarnings, $this->creator), Money::credits(4)),
        Posting::credit(AccountRef::of(AccountType::PayoutClearing, $this->creator), Money::credits(4)),
    ]));

    app(RefundPurchase::class)->handle($sale->transactionId, $admin->id, 'Duplicate tip');

    expect(balanceOf(AccountRef::of(AccountType::CreatorEarnings, $this->creator)))->toBe(-4)
        ->and(balanceOf(AccountRef::of(AccountType::FanWallet, $this->fan)))->toBe(1000)
        ->and($this->audit->of('wallet.refund')[0]['context'])->toMatchArray(['from' => 'creator_earnings']);
});

it('refuses refunds from non-admins, without a reason, for unknown sales and by a party to the sale', function (): void {
    $admin = signedInStaff();
    $moderator = signedInStaff('moderator');
    fundWallet($this->fan, 1000);
    $sale = buy($this->fan, $this->creator, 100);

    expect(fn () => app(RefundPurchase::class)->handle($sale->transactionId, $moderator->id, 'Refund'))->toThrow(WalletException::class, 'Your role cannot do this.')
        ->and(fn () => app(RefundPurchase::class)->handle($sale->transactionId, $admin->id, ' '))->toThrow(WalletException::class, 'Write the reason')
        ->and(fn () => app(RefundPurchase::class)->handle('nope', $admin->id, 'Refund'))->toThrow(WalletException::class, 'no sale');

    // An admin who is the fan in a sale cannot refund it.
    $this->earning[$this->creator] = true;
    fundWallet($admin->id, 100);
    $own = buy($admin->id, $this->creator, 100, 'own');
    expect(fn () => app(RefundPurchase::class)->handle($own->transactionId, $admin->id, 'Refund'))->toThrow(WalletException::class, 'part of');
});
