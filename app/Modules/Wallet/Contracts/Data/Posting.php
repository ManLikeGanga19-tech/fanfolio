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
