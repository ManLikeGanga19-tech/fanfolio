<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts;

use App\Modules\Wallet\Contracts\Data\AccountRef;
use App\Modules\Wallet\Contracts\Data\LedgerTransaction;
use App\Modules\Wallet\Contracts\Data\PostedTransaction;
use App\Modules\Wallet\Contracts\Exceptions\LedgerException;
use App\Support\Money\Money;

/**
 * The only way money moves (brief rule 1; spec "Money moves only through
 * Wallet\Contracts\Ledger::post"). Business actions (top-ups, purchases, payouts) build a
 * LedgerTransaction and post it here.
 */
interface Ledger
{
    /**
     * Writes the transaction, its entries and the cached balances in one database
     * transaction, holding a Redis lock and a row lock on each personal account it
     * debits (rule 4). A repeated idempotency key returns the first result and charges
     * nothing; the same key with a different transaction is refused.
     *
     * @throws LedgerException insufficient funds, a busy wallet or a reused key
     */
    public function post(LedgerTransaction $transaction): PostedTransaction;

    /** The cached balance (credits minus debits); zero for an account never used. */
    public function balance(AccountRef $account, string $currency = Money::CREDIT_CURRENCY): Money;
}
