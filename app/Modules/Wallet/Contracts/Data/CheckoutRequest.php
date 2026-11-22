<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Contracts\Data;

use App\Support\Money\Money;

final readonly class CheckoutRequest
{
    public function __construct(
        public string $topUpId,
        public string $fanId,
        public Money $amount,
        public TopUpMethod $method,
        public string $returnUrl,
        public string $cancelUrl,
    ) {}
}
