<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use App\Support\Money\Money;
use InvalidArgumentException;

/** One side of a ledger transaction: a debit or credit of a positive amount to one account. */
final readonly class Posting
{
    private function __construct(
        public AccountRef $account,
        public bool $debit,
        public Money $amount,
    ) {}

    public static function debit(AccountRef $account, Money $amount): self
    {
        return new self($account, true, self::positive($amount));
    }

    public static function credit(AccountRef $account, Money $amount): self
    {
        return new self($account, false, self::positive($amount));
    }

    /**
     * A debit when there is an amount, none when it is zero: for optional lines such as a fee
     * that rounds to nothing (spread it into the postings: `...Posting::debitIfAny(...)`).
     *
     * @return list<self>
     */
    public static function debitIfAny(AccountRef $account, Money $amount): array
    {
        return $amount->isZero() ? [] : [self::debit($account, $amount)];
    }

    /**
     * @return list<self>
     *
     * @see debitIfAny()
     */
    public static function creditIfAny(AccountRef $account, Money $amount): array
    {
        return $amount->isZero() ? [] : [self::credit($account, $amount)];
    }

    /** Credits minus debits: the effect on the account's balance. */
    public function delta(): int
    {
        return $this->debit ? -$this->amount->amountMinor : $this->amount->amountMinor;
    }

    private static function positive(Money $amount): Money
    {
        if ($amount->amountMinor <= 0) {
            throw new InvalidArgumentException('A posting must be a positive amount.');
        }

        return $amount;
    }
}
