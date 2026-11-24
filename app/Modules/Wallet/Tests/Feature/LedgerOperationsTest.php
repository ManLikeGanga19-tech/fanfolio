<?php

declare(strict_types=1);

use App\Modules\Wallet\Console\VerifyLedger;
use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\AccountType;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\Posting;
use App\Modules\Wallet\Contracts\Ledger;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// M3 PR 1: the nightly integrity check and monthly partitions (spec "Integrity checks", scale notes).

function postTopUp(string $fan, int $cents): string
{
    return app(Ledger::class)->post(LedgerTransaction::make('topup', 'topup:'.Str::ulid(), [
        Posting::debit(AccountRef::platform(AccountType::ProcessorClearing), Money::of($cents, 'USD')),
        Posting::credit(AccountRef::of(AccountType::FanWallet, $fan), Money::of($cents, 'USD')),
    ]))->id;
}

it('reports a balanced ledger and keeps the result for the Overview', function (): void {
    postTopUp(strtolower((string) Str::ulid()), 500);

    $this->artisan('wallet:verify-ledger')->expectsOutput('Ledger balanced.')->assertSuccessful();

    expect(Cache::get(VerifyLedger::RESULT_KEY))->toMatchArray(['ok' => true, 'unbalanced' => [], 'mismatched' => []]);
    expectLedgerBalanced();
});

it('catches a cached balance out of step and a transaction without entries', function (): void {
    Log::spy();
    $fan = strtolower((string) Str::ulid());
    postTopUp($fan, 500);
    $account = (string) DB::table('ledger_accounts')->where('owner_id', $fan)->value('id');
    // Simulate damage the ledger code never does: a balance moved by hand and a bare transaction.
    DB::table('ledger_balances')->where('account_id', $account)->update(['balance_minor' => 499]);
    DB::table('ledger_transactions')->insert([
        'id' => strtolower((string) Str::ulid()), 'type' => 'broken', 'idempotency_key' => 'broken', 'fingerprint' => str_repeat('0', 64), 'created_at' => now(),
    ]);

    $this->artisan('wallet:verify-ledger')
        ->expectsOutput('Ledger mismatch: 1 unbalanced transaction(s), 1 balance(s) out of step.')
        ->assertFailed();

    expect(Cache::get(VerifyLedger::RESULT_KEY))->toMatchArray(['ok' => false, 'mismatched' => [$account]]);
    Log::shouldHaveReceived('critical')->once();
});

it('creates monthly partitions ahead, and entries land in them', function (): void {
    $this->artisan('wallet:ledger-partitions', ['--months' => 2])->assertSuccessful();
    // Idempotent.
    $this->artisan('wallet:ledger-partitions', ['--months' => 2])->assertSuccessful();

    $month = CarbonImmutable::now('UTC')->startOfMonth();
    foreach ([0, 1, 2] as $i) {
        expect(DB::selectOne('SELECT to_regclass(?) AS t', ['ledger_entries_'.$month->addMonths($i)->format('Y_m')])->t)->not->toBeNull();
    }

    postTopUp(strtolower((string) Str::ulid()), 100);
    expect(DB::table('ledger_entries_'.$month->format('Y_m'))->count())->toBe(2)
        ->and(DB::table('ledger_entries_default')->count())->toBe(0);
    expectLedgerBalanced();
});
