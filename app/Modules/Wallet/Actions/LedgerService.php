<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Actions;

use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\PostedTransaction;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Modules\Wallet\Contracts\Ledger;
use App\Support\Money\Money;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ledger (spec "Wallet and ledger"; brief rules 1–4). The only code that writes
 * ledger transactions, entries and balances; an architecture test keeps it that way.
 *
 * Posting: refuse a reused key with different contents and return the first result for
 * a replay; take a Redis lock on each person whose account is debited (2 s TTL, waiting
 * up to 3 s); then in one database transaction lock their balance rows FOR UPDATE in
 * account order (no deadlocks), check nothing goes below zero, write the transaction
 * and its entries, and move the cached balances.
 */
final readonly class LedgerService implements Ledger
{
    public function post(LedgerTransaction $transaction): PostedTransaction
    {
        $locks = [];

        try {
            foreach ($this->lockedOwners($transaction) as $owner) {
                $lock = Cache::lock('wallet:'.$owner, config()->integer('fanfolio.money.wallet_lock_ttl_seconds'));
                $lock->block(config()->integer('fanfolio.money.wallet_lock_wait_seconds'));
                $locks[] = $lock;
            }

            return DB::transaction(fn (): PostedTransaction => $this->write($transaction));
        } catch (LockTimeoutException) {
            throw LedgerException::busy();
        } finally {
            array_map(static fn (Lock $lock): bool => $lock->release(), $locks);
        }
    }

    public function balance(AccountRef $account, string $currency = Money::CREDIT_CURRENCY): Money
    {
        // No row yet (an account never used) reads as zero.
        /** @var int|string|null $balance */
        $balance = DB::table('ledger_accounts')
            ->join('ledger_balances', 'ledger_balances.account_id', '=', 'ledger_accounts.id')
            ->where('owner_type', $account->ownerType())
            ->where('owner_id', $account->ownerId)
            ->where('type', $account->type->value)
            ->where('currency', $currency)
            ->value('balance_minor');

        return Money::of(intval($balance), $currency);
    }

    private function write(LedgerTransaction $transaction): PostedTransaction
    {
        $now = now();
        $currency = $transaction->currency();

        /** @var array<string, int> $deltas account id => change */
        $deltas = [];
        /** @var array<string, string> $guarded account id => account key, for accounts that must stay positive */
        $guarded = [];
        $entries = [];
        $id = strtolower((string) Str::ulid());

        foreach ($transaction->postings as $posting) {
            $accountId = $this->account($posting->account, $currency);
            $deltas[$accountId] = ($deltas[$accountId] ?? 0) + $posting->delta();

            if ($posting->debit && $posting->account->type->mustStayPositive() && ! $transaction->allowNegative) {
                $guarded[$accountId] = $posting->account->key();
            }

            $entries[] = [
                'id' => strtolower((string) Str::ulid()),
                'transaction_id' => $id,
                'account_id' => $accountId,
                'direction' => $posting->debit ? 'debit' : 'credit',
                'amount_minor' => $posting->amount->amountMinor,
                'currency' => $currency,
                'created_at' => $now,
            ];
        }

        ksort($deltas);
        ksort($guarded);

        // The key first: a replay returns the first result and charges nothing. A concurrent
        // post with the same key waits here for the other to commit, then inserts nothing.
        $inserted = DB::table('ledger_transactions')->insertOrIgnore([
            'id' => $id,
            'type' => $transaction->type,
            'idempotency_key' => $transaction->idempotencyKey,
            'fingerprint' => $transaction->fingerprint(),
            'reference_type' => $transaction->referenceType,
            'reference_id' => $transaction->referenceId,
            'tax_tag' => $transaction->taxTag,
            'created_at' => $now,
        ]);

        if ($inserted === 0) {
            return $this->replay($transaction);
        }

        // Balance rows exist before they are locked or moved.
        DB::table('ledger_balances')->insertOrIgnore(array_map(
            static fn (string $accountId): array => ['account_id' => $accountId, 'balance_minor' => 0, 'updated_at' => $now],
            array_keys($deltas),
        ));

        if ($guarded !== []) {
            /** @var array<string, int|string> $balances */
            $balances = DB::table('ledger_balances')
                ->whereIn('account_id', array_keys($guarded))
                ->orderBy('account_id')
                ->lockForUpdate()
                ->pluck('balance_minor', 'account_id')
                ->all();

            foreach ($guarded as $accountId => $key) {
                if ((int) $balances[$accountId] + $deltas[$accountId] < 0) {
                    throw LedgerException::insufficient($key);
                }
            }
        }

        DB::table('ledger_entries')->insert($entries);

        foreach ($deltas as $accountId => $delta) {
            DB::table('ledger_balances')->where('account_id', $accountId)->update([
                'balance_minor' => DB::raw('balance_minor + '.$delta),
                'updated_at' => $now,
            ]);
        }

        return new PostedTransaction($id, false);
    }

    /** The key is taken: the same transaction is a replay; anything else is refused. */
    private function replay(LedgerTransaction $transaction): PostedTransaction
    {
        /** @var object{id: string, fingerprint: string} $row */
        $row = DB::table('ledger_transactions')
            ->where('idempotency_key', $transaction->idempotencyKey)
            ->sole(['id', 'fingerprint']);

        if (! hash_equals($row->fingerprint, $transaction->fingerprint())) {
            throw LedgerException::keyReused();
        }

        return new PostedTransaction($row->id, true);
    }

    /** The account's id, created on first use (safe when two requests create it at once). */
    private function account(AccountRef $account, string $currency): string
    {
        $match = [
            'owner_type' => $account->ownerType(),
            'owner_id' => $account->ownerId,
            'type' => $account->type->value,
            'currency' => $currency,
        ];

        DB::table('ledger_accounts')->insertOrIgnore($match + ['id' => strtolower((string) Str::ulid()), 'created_at' => now()]);

        /** @var string $id */
        $id = DB::table('ledger_accounts')->where($match)->value('id');

        return $id;
    }

    /**
     * People whose accounts this transaction debits, sorted so locks are always taken in
     * the same order.
     *
     * @return list<string>
     */
    private function lockedOwners(LedgerTransaction $transaction): array
    {
        $owners = [];
        foreach ($transaction->postings as $posting) {
            if ($posting->debit && $posting->account->ownerId !== null) {
                $owners[$posting->account->ownerId] = true;
            }
        }

        $owners = array_keys($owners);
        sort($owners);

        return $owners;
    }
}
