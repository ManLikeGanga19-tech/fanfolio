<?php

declare(strict_types=1);

use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\PostedTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Modules\Wallet\Contracts\Ledger;
use App\Support\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// M3 PR 1: the ledger. Brief rules 1–4; spec "Wallet and ledger"; SEC-12.

afterEach(fn () => expectLedgerBalanced());

function usd(int $cents): Money
{
    return Money::of($cents, 'USD');
}

function fanWallet(string $userId): AccountRef
{
    return AccountRef::of(AccountType::FanWallet, $userId);
}

/** A confirmed top-up of $cents to a fan (spec postings: processor_clearing → fan_wallet). */
function topUp(string $fan, int $cents, ?string $key = null): PostedTransaction
{
    return app(Ledger::class)->post(LedgerTransaction::make('topup', $key ?? 'topup:'.Str::ulid(), [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), usd($cents)),
        Posting::credit(fanWallet($fan), usd($cents)),
    ], 'topup', 'ref-1'));
}

/** A sale: fan_wallet 100% → creator_pending 80% + platform_revenue 20%. */
function sale(string $fan, string $creator, int $cents, string $key): LedgerTransaction
{
    $fee = usd($cents)->shareBps(2000);
    $postings = [
        Posting::debit(fanWallet($fan), usd($cents)),
        Posting::credit(AccountRef::of(AccountType::CreatorPending, $creator), usd($cents)->subtract($fee)),
    ];
    // Under 5 credits the 20% fee rounds down to nothing, and a zero posting is not allowed.
    if (! $fee->isZero()) {
        $postings[] = Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), $fee);
    }

    return LedgerTransaction::make('sale', $key, $postings, 'purchase', 'p-1', 'sale');
}

beforeEach(function (): void {
    $this->fan = strtolower((string) Str::ulid());
    $this->creator = strtolower((string) Str::ulid());
    $this->ledger = app(Ledger::class);
});

it('posts a balanced top-up and moves the cached balances', function (): void {
    $posted = topUp($this->fan, 1000);

    expect($posted->replayed)->toBeFalse()
        ->and($this->ledger->balance(fanWallet($this->fan))->amountMinor)->toBe(1000)
        ->and($this->ledger->balance(AccountRef::platform(AccountType::ProcessorClearing))->amountMinor)->toBe(-1000)
        ->and(DB::table('ledger_entries')->where('transaction_id', $posted->id)->count())->toBe(2)
        ->and(DB::table('ledger_transactions')->where('id', $posted->id)->value('reference_type'))->toBe('topup');
});

it('splits a sale 80/20 and keeps the fee on the platform', function (): void {
    topUp($this->fan, 1000);

    $this->ledger->post(sale($this->fan, $this->creator, 999, 'sale:1'));

    expect($this->ledger->balance(fanWallet($this->fan))->amountMinor)->toBe(1)
        ->and($this->ledger->balance(AccountRef::of(AccountType::CreatorPending, $this->creator))->amountMinor)->toBe(800)
        ->and($this->ledger->balance(AccountRef::platform(AccountType::PlatformRevenue))->amountMinor)->toBe(199)
        ->and(DB::table('ledger_transactions')->where('idempotency_key', 'sale:1')->value('tax_tag'))->toBe('sale');
});

it('never charges twice for a replayed idempotency key', function (): void {
    topUp($this->fan, 1000);

    $first = $this->ledger->post(sale($this->fan, $this->creator, 400, 'sale:replay'));
    $again = $this->ledger->post(sale($this->fan, $this->creator, 400, 'sale:replay'));

    expect($again->replayed)->toBeTrue()
        ->and($again->id)->toBe($first->id)
        ->and($this->ledger->balance(fanWallet($this->fan))->amountMinor)->toBe(600)
        ->and(DB::table('ledger_transactions')->where('idempotency_key', 'sale:replay')->count())->toBe(1);
});

it('refuses a key reused for a different transaction', function (): void {
    topUp($this->fan, 1000);
    $this->ledger->post(sale($this->fan, $this->creator, 400, 'sale:k'));

    expect(fn () => $this->ledger->post(sale($this->fan, $this->creator, 500, 'sale:k')))
        ->toThrow(LedgerException::class, 'already used for a different transaction');

    expect($this->ledger->balance(fanWallet($this->fan))->amountMinor)->toBe(600);
});

it('refuses to take a personal account below zero, and writes nothing', function (): void {
    topUp($this->fan, 300);

    try {
        $this->ledger->post(sale($this->fan, $this->creator, 301, 'sale:too-much'));
        $this->fail('Expected insufficient funds.');
    } catch (LedgerException $e) {
        expect($e->problem)->toBe(LedgerException::INSUFFICIENT);
    }

    expect($this->ledger->balance(fanWallet($this->fan))->amountMinor)->toBe(300)
        ->and(DB::table('ledger_transactions')->where('idempotency_key', 'sale:too-much')->exists())->toBeFalse();

    // A fan never seen before has nothing to spend.
    expect(fn () => $this->ledger->post(sale(strtolower((string) Str::ulid()), $this->creator, 1, 'sale:empty')))
        ->toThrow(LedgerException::class, 'Not enough funds');
});

it('lets a transaction go below zero only when it says so (D-20 clawbacks)', function (): void {
    $clawback = LedgerTransaction::make('chargeback.clawback', 'cb:1', [
        Posting::debit(AccountRef::of(AccountType::CreatorEarnings, $this->creator), usd(500)),
        Posting::credit(AccountRef::platform(AccountType::ProcessorClearing), usd(500)),
    ])->allowingNegativeBalances();

    $this->ledger->post($clawback);

    expect($this->ledger->balance(AccountRef::of(AccountType::CreatorEarnings, $this->creator))->amountMinor)->toBe(-500)
        ->and($clawback->allowNegative)->toBeTrue();
});

it('answers "busy" while another payment holds the wallet (rule 4)', function (): void {
    topUp($this->fan, 1000);
    config(['fanfolio.money.wallet_lock_wait_seconds' => 0]);
    $held = Cache::lock('wallet:'.$this->fan, 10);
    $held->get();

    try {
        $this->ledger->post(sale($this->fan, $this->creator, 100, 'sale:busy'));
        $this->fail('Expected busy.');
    } catch (LedgerException $e) {
        expect($e->problem)->toBe(LedgerException::BUSY);
    } finally {
        $held->release();
    }

    // The lock is released after a post, so the next one goes through.
    $this->ledger->post(sale($this->fan, $this->creator, 100, 'sale:after'));
    $this->ledger->post(sale($this->fan, $this->creator, 100, 'sale:after-2'));
    expect($this->ledger->balance(fanWallet($this->fan))->amountMinor)->toBe(800);
});

it('nets several postings to the same account in one transaction', function (): void {
    topUp($this->fan, 500);

    $this->ledger->post(LedgerTransaction::make('adjust', 'adjust:1', [
        Posting::debit(fanWallet($this->fan), usd(500)),
        Posting::credit(fanWallet($this->fan), usd(200)),
        Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), usd(300)),
    ]));

    expect($this->ledger->balance(fanWallet($this->fan))->amountMinor)->toBe(200);
});

it('keeps transactions and entries append-only for every role (SEC-12)', function (string $sql): void {
    topUp($this->fan, 100);

    // In a savepoint, so the refused statement does not abort the test's own transaction.
    expect(fn () => DB::transaction(fn (): bool => DB::statement($sql)))->toThrow(QueryException::class, 'append-only');
})->with([
    'update transaction' => ["UPDATE ledger_transactions SET type = 'x'"],
    'delete transaction' => ['DELETE FROM ledger_transactions'],
    'update entry' => ['UPDATE ledger_entries SET amount_minor = 1'],
    'delete entry' => ['DELETE FROM ledger_entries'],
]);

it('reads zero for an account never used and keeps currencies apart', function (): void {
    expect($this->ledger->balance(fanWallet($this->fan))->equals(usd(0)))->toBeTrue()
        ->and($this->ledger->balance(fanWallet($this->fan), 'KES')->currency)->toBe('KES');
});

it('checks transactions before they reach the database', function (Closure $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'bad type' => [fn (): LedgerTransaction => LedgerTransaction::make('Bad Type', 'k', []), 'Invalid transaction type'],
    'no key' => [fn (): LedgerTransaction => LedgerTransaction::make('sale', '  ', []), 'idempotency key'],
    'long key' => [fn (): LedgerTransaction => LedgerTransaction::make('sale', str_repeat('k', 192), []), 'idempotency key'],
    'one posting' => [fn (): LedgerTransaction => LedgerTransaction::make('sale', 'k', [Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), usd(1))]), 'at least two postings'],
    'mixed currency' => [fn (): LedgerTransaction => LedgerTransaction::make('sale', 'k', [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), usd(1)),
        Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), Money::of(1, 'KES')),
    ]), 'one currency'],
    'unbalanced' => [fn (): LedgerTransaction => LedgerTransaction::make('sale', 'k', [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), usd(2)),
        Posting::credit(AccountRef::platform(AccountType::PlatformRevenue), usd(1)),
    ]), 'Debits and credits must be equal'],
    'zero posting' => [fn (): Posting => Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), usd(0)), 'positive amount'],
    'negative posting' => [fn (): Posting => Posting::credit(AccountRef::platform(AccountType::ProcessorClearing), usd(-5)), 'positive amount'],
    'platform account for a person' => [fn (): AccountRef => AccountRef::of(AccountType::PlatformRevenue, 'someone'), 'belongs to the platform'],
    'person account for the platform' => [fn (): AccountRef => AccountRef::platform(AccountType::FanWallet), 'belongs to a person'],
]);

it('knows which accounts belong to people and must stay positive', function (): void {
    $personal = array_values(array_filter(AccountType::cases(), static fn (AccountType $t): bool => $t->isPersonal()));

    expect($personal)->toBe([AccountType::FanWallet, AccountType::CreatorPending, AccountType::CreatorEarnings, AccountType::PayoutClearing])
        ->and(AccountType::FanWallet->mustStayPositive())->toBeTrue()
        ->and(AccountType::ProcessorClearing->mustStayPositive())->toBeFalse()
        ->and(fanWallet('u1')->key())->toBe('fan_wallet:u1')
        ->and(AccountRef::platform(AccountType::PlatformRevenue)->key())->toBe('platform_revenue:platform')
        ->and(AccountRef::platform(AccountType::PlatformRevenue)->ownerType())->toBe('platform');
});
