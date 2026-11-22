<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use App\Support\Money\Money;

final readonly class PayoutInstruction
{
    /**
     * @param  array<string, string>  $destination  decrypted rail-specific details (account number, wallet address…); never log
     */
    public function __construct(
        public string $payoutId,
        public PayoutRail $rail,
        public Money $amount,
        public string $accountName,
        public array $destination,
    ) {}
}
